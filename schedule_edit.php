<?php
// schedule_edit.php
// session_start();
date_default_timezone_set('Asia/Singapore');

require __DIR__ . '/db.php';
require __DIR__ . '/auth/guard.php';
$me = $_SESSION['auth'] ?? null;
requireLogin(); // anyone logged in can see the navbar
if (!hasRole($conn, 'Admin')) { // must be logged in + have Admin
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Access denied. Only users can edit schedules.',
    ];
    header("Location: ./index.php");
    exit;
}


// ---------- helpers ----------
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function to_ymd($s)
{
    $s = trim((string)$s);
    if ($s === '') return '';
    $fmts = ['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y'];
    foreach ($fmts as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt && $dt->format($fmt) === $s) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : '';
}

function day_from_ymd($ymd)
{
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    return $ts ? date('D', $ts) : '';
}

function fetch_schedule(mysqli $conn, int $id)
{
    $stmt = $conn->prepare("SELECT * FROM session_plans WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function save_schedule(mysqli $conn, array $payload): bool
{
    [$course_title, $course_code, $module_code, $cohort_code, $learning_mode, $plan_json, $id] = $payload;

    $stmt = $conn->prepare("
    UPDATE session_plans
       SET course_title = ?,
           course_code = ?,
           module_code = ?,
           cohort_code = ?,
           learning_mode = ?,
           plan_json = ?,
           last_updated = NOW()
     WHERE id = ?
  ");
    $stmt->bind_param('ssssssi', $course_title, $course_code, $module_code, $cohort_code, $learning_mode, $plan_json, $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ---------- routing ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    http_response_code(400);
    echo "Invalid ID";
    exit;
}

if ($method === 'POST') {
    // CSRF
    if (!isset($_POST['csrf']) || !isset($_SESSION['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        http_response_code(403);
        echo "Invalid CSRF token";
        exit;
    }

    // Basic fields (columns)
    $course_title  = trim($_POST['course_title'] ?? '');
    $course_code   = trim($_POST['course_code'] ?? '');
    $module_code   = trim($_POST['module_code'] ?? '');
    $cohort_code   = trim($_POST['cohort_code'] ?? '');
    $learning_mode = trim($_POST['learning_mode'] ?? '');

    // Meta (stored inside plan_json)
    $module_title  = trim($_POST['module_title'] ?? '');
    $start_date_in = trim($_POST['start_date'] ?? '');
    $start_date    = to_ymd($start_date_in);

    // Rows
    $rows_in = $_POST['rows'] ?? [];
    $rows_out = [];

    if (is_array($rows_in)) {
        foreach ($rows_in as $r) {
            $no       = trim($r['no'] ?? '');
            $type     = trim($r['type'] ?? '');
            $details  = trim($r['details'] ?? '');
            $duration = trim($r['duration'] ?? '');
            $faculty  = trim($r['faculty'] ?? '');
            $date_in  = trim($r['date'] ?? '');
            $time     = trim($r['time'] ?? '');

            // normalize date
            $ymd = to_ymd($date_in);
            $day = $ymd ? day_from_ymd($ymd) : '';

            // skip completely empty rows
            $any = $no . $type . $details . $duration . $faculty . $ymd . $time;
            if ($any === '') continue;

            $rows_out[] = [
                'no'       => $no,
                'type'     => $type,
                'details'  => $details,
                'duration' => $duration,
                'faculty'  => $faculty,
                'date'     => $ymd,     // store ISO for consistency
                'day'      => $day,     // auto-computed
                'time'     => $time,
            ];
        }
    }

    // Rebuild plan_json
    $plan = [
        'meta' => [
            'module_title'  => $module_title,
            'course_title'  => $course_title,
            'course_code'   => $course_code,
            'module_code'   => $module_code,
            'cohort_code'   => $cohort_code,
            'learning_mode' => $learning_mode,
            'start_date'    => $start_date,
            'counts'        => ['rows' => count($rows_out)],
        ],
        'rows' => $rows_out,
    ];

    $plan_json = json_encode($plan, JSON_UNESCAPED_UNICODE);

    $ok = save_schedule($conn, [
        $course_title,
        $course_code,
        $module_code,
        $cohort_code,
        $learning_mode,
        $plan_json,
        $id
    ]);

    if ($ok) {
        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => "Schedule #$id saved.",
            'expires_at' => time() + 20
        ];
        header("Location: schedule_view.php?id={$id}");
        exit;
    } else {
        $error = "Failed to save. Please try again.";
    }
}

// GET: load data
$row = fetch_schedule($conn, $id);
if (!$row) {
    http_response_code(404);
    echo "Schedule not found.";
    exit;
}

$plan = json_decode($row['plan_json'] ?? '[]', true) ?: [];
$meta = $plan['meta'] ?? [];

$course_title  = $row['course_title']  ?: ($meta['course_title']  ?? '');
$course_code   = $row['course_code']   ?: ($meta['course_code']   ?? '');
$module_code   = $row['module_code']   ?: ($meta['module_code']   ?? '');
$module_title  = $row['module_title']  ?? ($meta['module_title']  ?? ''); // some schemas don't have a column
$cohort_code   = $row['cohort_code']   ?: ($meta['cohort_code']   ?? '');
$learning_mode = $row['learning_mode'] ?: ($meta['learning_mode'] ?? '');
$start_date    = $meta['start_date']   ?? '';
$rowsData      = $plan['rows']         ?? [];

// CSRF
$_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Edit Schedule #<?= (int)$id ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-size: 12px;
        }

        .tbl th,
        .tbl td {
            vertical-align: middle;
        }

        .tbl input,
        .tbl textarea,
        .tbl select {
            font-size: 12px;
        }

        .short {
            max-width: 110px;
        }

        .very-short {
            max-width: 70px;
        }

        .details {
            min-width: 260px;
        }

        .sticky-head thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8f9fa;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container-fluid py-3">

        <div class="d-flex gap-2 mb-3">
            <a href="schedule_view.php?id=<?= (int)$id ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to View</a>
            <span class="align-self-center text-muted">Editing Schedule <strong>#<?= (int)$id ?></strong></span>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

            <div class="card mb-3">
                <div class="card-header fw-bold" style="background:#941D63;color:#fff">Meta</div>
                <div class="card-body row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Course Title</label>
                        <input name="course_title" value="<?= h($course_title) ?>" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Course Code</label>
                        <input name="course_code" value="<?= h($course_code) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Module Code</label>
                        <input name="module_code" value="<?= h($module_code) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Module Title</label>
                        <input name="module_title" value="<?= h($module_title) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cohort Code</label>
                        <input name="cohort_code" value="<?= h($cohort_code) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Learning Mode</label>
                        <input name="learning_mode" value="<?= h($learning_mode) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date"
                            value="<?= h(to_ymd($start_date)) ?>"
                            class="form-control form-control-sm">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Rows</span>
                    <div class="d-flex gap-2">
                        <button type="button" id="addRow" class="btn btn-sm btn-success">+ Add Row</button>
                    </div>
                </div>

                <div class="table-responsive sticky-head">
                    <table class="table table-sm table-bordered align-middle tbl mb-0" id="rowsTable">
                        <thead>
                            <tr class="table-light">
                                <th class="very-short">#</th>
                                <th class="short">Type</th>
                                <th class="details">Details</th>
                                <th class="very-short">Dur</th>
                                <th class="short">Faculty</th>
                                <th class="short">Date</th>
                                <th class="very-short">Day</th>
                                <th class="short">Time</th>
                                <th class="very-short">—</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $idx = 0;
                            if ($rowsData) {
                                foreach ($rowsData as $r):
                                    $no       = $r['no'] ?? '';
                                    $type     = $r['type'] ?? '';
                                    $details  = $r['details'] ?? '';
                                    $duration = $r['duration'] ?? '';
                                    $faculty  = $r['faculty'] ?? '';
                                    $dateIso  = to_ymd($r['date'] ?? '');
                                    $day      = $r['day'] ?? day_from_ymd($dateIso);
                                    $time     = $r['time'] ?? '';
                            ?>
                                    <tr>
                                        <td><input name="rows[<?= $idx ?>][no]" value="<?= h($no) ?>" class="form-control form-control-sm"></td>
                                        <td><input name="rows[<?= $idx ?>][type]" value="<?= h($type) ?>" class="form-control form-control-sm"></td>
                                        <td><textarea name="rows[<?= $idx ?>][details]" class="form-control form-control-sm" rows="1"><?= h($details) ?></textarea></td>
                                        <td><input name="rows[<?= $idx ?>][duration]" value="<?= h($duration) ?>" class="form-control form-control-sm"></td>
                                        <td><input name="rows[<?= $idx ?>][faculty]" value="<?= h($faculty) ?>" class="form-control form-control-sm"></td>
                                        <td><input type="date" name="rows[<?= $idx ?>][date]" value="<?= h($dateIso) ?>" class="form-control form-control-sm date-input"></td>
                                        <td><input name="rows[<?= $idx ?>][day]" value="<?= h($day) ?>" class="form-control form-control-sm day-input" readonly></td>
                                        <td><input name="rows[<?= $idx ?>][time]" value="<?= h($time) ?>" class="form-control form-control-sm"></td>
                                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
                                    </tr>
                                <?php
                                    $idx++;
                                endforeach;
                            }
                            // ensure at least one empty row
                            if ($idx === 0): ?>
                                <tr>
                                    <td><input name="rows[0][no]" class="form-control form-control-sm"></td>
                                    <td><input name="rows[0][type]" class="form-control form-control-sm"></td>
                                    <td><textarea name="rows[0][details]" class="form-control form-control-sm" rows="1"></textarea></td>
                                    <td><input name="rows[0][duration]" class="form-control form-control-sm"></td>
                                    <td><input name="rows[0][faculty]" class="form-control form-control-sm"></td>
                                    <td><input type="date" name="rows[0][date]" class="form-control form-control-sm date-input"></td>
                                    <td><input name="rows[0][day]" class="form-control form-control-sm day-input" readonly></td>
                                    <td><input name="rows[0][time]" class="form-control form-control-sm"></td>
                                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-body d-flex justify-content-end gap-2">
                    <a href="schedule_view.php?id=<?= (int)$id ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                    <button class="btn btn-primary btn-sm">Save</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        (function() {
            const table = document.getElementById('rowsTable').querySelector('tbody');
            const addBtn = document.getElementById('addRow');

            function computeDay(iso) {
                if (!iso) return '';
                const d = new Date(iso + 'T00:00:00');
                if (isNaN(d)) return '';
                return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d.getUTCDay()];
            }

            function renumber() {
                // keep indexes dense and unique for PHP arrays
                const rows = Array.from(table.querySelectorAll('tr'));
                rows.forEach((tr, i) => {
                    tr.querySelectorAll('input,textarea,select').forEach(inp => {
                        inp.name = inp.name.replace(/rows\[\d+]/, `rows[${i}]`);
                    });
                });
            }

            table.addEventListener('input', function(e) {
                const el = e.target;
                if (el.classList.contains('date-input')) {
                    const tr = el.closest('tr');
                    const dayInput = tr.querySelector('.day-input');
                    dayInput.value = computeDay(el.value);
                }
            });

            table.addEventListener('click', function(e) {
                if (e.target.classList.contains('delRow')) {
                    const tr = e.target.closest('tr');
                    tr.remove();
                    renumber();
                }
            });

            addBtn.addEventListener('click', function() {
                const idx = table.querySelectorAll('tr').length;
                const tr = document.createElement('tr');
                tr.innerHTML = `
      <td><input name="rows[${idx}][no]" class="form-control form-control-sm"></td>
      <td><input name="rows[${idx}][type]" class="form-control form-control-sm"></td>
      <td><textarea name="rows[${idx}][details]" class="form-control form-control-sm" rows="1"></textarea></td>
      <td><input name="rows[${idx}][duration]" class="form-control form-control-sm"></td>
      <td><input name="rows[${idx}][faculty]" class="form-control form-control-sm"></td>
      <td><input type="date" name="rows[${idx}][date]" class="form-control form-control-sm date-input"></td>
      <td><input name="rows[${idx}][day]" class="form-control form-control-sm day-input" readonly></td>
      <td><input name="rows[${idx}][time]" class="form-control form-control-sm"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
    `;
                table.appendChild(tr);
            });
        })();
    </script>
</body>

</html>