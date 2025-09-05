<?php
// session_plan.php
session_start();
date_default_timezone_set('Asia/Singapore');

// autoload & .env
require_once __DIR__ . '/../../vendor/autoload.php';
if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
    $dotenv->safeLoad();
}
$baseUrl = $_ENV['BASE_URL'] ?? '/';

// DB
require __DIR__ . '/../../db.php';

// auth (adjust as needed)
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
$username = $_SESSION['username'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// helpers
function isPost($k)
{
    return isset($_POST[$k]);
}
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function csv_to_rows($tmpPath)
{
    $rows = [];
    if (($fh = fopen($tmpPath, 'r')) !== false) {
        while (($cols = fgetcsv($fh)) !== false) {
            if (count(array_filter($cols, fn($v) => trim((string)$v) !== '')) === 0) continue;
            $rows[] = $cols;
        }
        fclose($fh);
    }
    return $rows;
}
function normalize_session_type($s)
{
    $x = strtolower(trim((string)$s));
    if (in_array($x, ['ms-sync', 'ms sync', 'mssync'])) return 'MS-Sync';
    if (in_array($x, ['ms-async', 'ms async', 'msasync', 'ms-asyn', 'ms-asyn c'])) return 'MS-ASync';
    return trim((string)$s);
}

/** ========== TEMPLATES (NO COHORT) ========== **/
function save_csv_template(mysqli $conn, array $meta, array $rows): array
{
    // $meta: course_id, course_code, module_code, learning_mode, user_id
    $conn->begin_transaction();
    try {
        $tpl = $conn->prepare("
            INSERT INTO session_templates
            (course_id, course_code, module_code, learning_mode, user_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$tpl) throw new Exception("template prepare failed: " . $conn->error);
        $tpl->bind_param(
            "ssssi",
            $meta['course_id'],
            $meta['course_code'],
            $meta['module_code'],
            $meta['learning_mode'],
            $meta['user_id']
        );
        if (!$tpl->execute()) throw new Exception("template insert failed");
        $template_id = $tpl->insert_id;
        $tpl->close();

        $rowStmt = $conn->prepare("
            INSERT INTO session_template_rows
            (template_id, session_no, session_type, session_details, duration_hr)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              session_type=VALUES(session_type),
              session_details=VALUES(session_details),
              duration_hr=VALUES(duration_hr)
        ");
        if (!$rowStmt) throw new Exception("rows prepare failed: " . $conn->error);

        foreach ($rows as $r) {
            $c0 = trim((string)($r[0] ?? ''));
            $c1 = normalize_session_type($r[1] ?? '');
            $c2 = trim((string)($r[2] ?? ''));
            $c3 = trim((string)($r[3] ?? ''));
            if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '') continue;

            $rowStmt->bind_param("issss", $template_id, $c0, $c1, $c2, $c3);
            if (!$rowStmt->execute()) throw new Exception("row insert failed");
        }
        $rowStmt->close();

        $conn->commit();
        return ['ok' => true, 'template_id' => $template_id];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

function fetch_latest_template_rows(mysqli $conn, array $keys): array
{
    // $keys: course_id, module_code, learning_mode, user_id
    $sql = "SELECT id FROM session_templates
          WHERE course_id=? AND module_code=? AND learning_mode=? AND user_id=?
          ORDER BY created_at DESC LIMIT 1";
    $s = $conn->prepare($sql);
    $s->bind_param("sssi", $keys['course_id'], $keys['module_code'], $keys['learning_mode'], $keys['user_id']);
    $s->execute();
    $s->bind_result($tid);
    if (!$s->fetch()) {
        $s->close();
        return [];
    }
    $s->close();

    $rows = [];
    $r = $conn->prepare("
        SELECT session_no, session_type, session_details, duration_hr
        FROM session_template_rows
        WHERE template_id=?
        ORDER BY CAST(session_no AS UNSIGNED), session_no
    ");
    $r->bind_param("i", $tid);
    $r->execute();
    $res = $r->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $r->close();
    return $rows;
}

/** ========== CSV Upload -> Save template (without cohort) ========== **/
$grid = $_SESSION['grid'] ?? null; // show immediately after upload
if (isPost('uploadCsv') && isset($_FILES['csvFile']) && is_uploaded_file($_FILES['csvFile']['tmp_name'])) {
    $rows = csv_to_rows($_FILES['csvFile']['tmp_name']);
    if ($rows && preg_match('/session/i', $rows[0][0] ?? '')) array_shift($rows);

    $clean = [];
    foreach ($rows as $r) {
        $c0 = trim((string)($r[0] ?? ''));
        $c1 = trim((string)($r[1] ?? ''));
        $c2 = trim((string)($r[2] ?? ''));
        $c3 = trim((string)($r[3] ?? ''));
        if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '') continue;
        $clean[] = [$c0, $c1, $c2, $c3];
    }

    // meta from hidden inputs
    $course_id    = trim($_POST['course_id']    ?? '');
    $course_code  = trim($_POST['course_code']  ?? '');
    $module_code  = trim($_POST['module_code']  ?? '');
    $learning_mode = trim($_POST['learning_mode'] ?? '');

    if ($clean && $conn && $course_id && $module_code && $learning_mode) {
        $meta = [
            'course_id' => $course_id,
            'course_code' => $course_code,
            'module_code' => $module_code,
            'learning_mode' => $learning_mode,
            'user_id' => $userId,
        ];
        // $res = save_csv_template($conn, $meta, $clean);
        if ($res['ok']) {
            $_SESSION['grid'] = $grid = $clean;
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Template saved. Rows: ' . count($clean)];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Save failed: ' . $res['msg']];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Missing Course/Module/Mode selection or DB.'];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Session Plan (Templates without Cohort)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        table thead th {
            position: sticky;
            top: 0;
            background: #0e1627;
            color: #fff;
            z-index: 2;
        }

        .table td,
        .table th {
            text-align: center;
            vertical-align: middle;
        }

        #results {
            position: absolute;
            z-index: 1000;
            width: 100%;
        }

        #results .list-group-item {
            cursor: pointer;
        }
    </style>
</head>

<body class="py-4">
    <div class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['message'] ?? '') ?></div>
        <?php endif; ?>

        <!-- Search & Refresh -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="mb-3 flex-grow-1 me-3 position-relative">
                <div class="input-group">
                    <input type="text" id="search" class="form-control" placeholder="Search courses...">
                    <button type="button" id="clearSearch" class="btn btn-outline-secondary" style="display:none;">&times;</button>
                </div>
                <div id="results" class="list-group"></div>
            </div>
            <form method="post" class="mb-3">
                <button type="submit" name="refresh" class="btn btn-primary">Refresh Course List</button>
            </form>
        </div>

        <div id="courseDetails" class="mt-2">
            <h6 id="courseTitle"></h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Learning Modules</label>
                    <select id="moduleSelect" class="form-select mb-2">
                        <option value="">Select a module...</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Learning Modes</label>
                    <select id="modeSelect" class="form-select mb-2">
                        <option value="">Select a mode...</option>
                    </select>
                    <div id="modeDetails"></div>
                </div>
            </div>
        </div>

        <!-- Step 1: CSV upload (NO cohort in template) -->
        <div class="card my-4">
            <div class="card-body">
                <h5 class="card-title">Upload CSV (first 4 columns only)</h5>
                <p class="mb-2">Columns: <strong>Session No</strong>, <strong>Session Type</strong>, <strong>Session Details</strong>, <strong>Duration Hr</strong>.</p>
                <form method="post" enctype="multipart/form-data" class="row g-3" id="csvForm">
                    <div class="col-md-6">
                        <input class="form-control" type="file" name="csvFile" accept=".csv" required>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-warning" name="uploadCsv" type="submit">Upload & Save Template</button>
                    </div>

                    <!-- hidden meta (NO cohort) -->
                    <input type="hidden" name="course_id" id="course_id_hidden">
                    <input type="hidden" name="course_code" id="course_code_hidden">
                    <input type="hidden" name="module_code" id="module_code_hidden">
                    <input type="hidden" name="learning_mode" id="learning_mode_hidden">
                </form>
            </div>
        </div>

        <!-- Session Plan table (editable) -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">Session Plan (from Template)</h5>
                <form method="post" id="saveForm">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle text-nowrap">
                            <thead>
                                <tr>
                                    <th>Session<br>No</th>
                                    <th>Session Type-<br>Mode</th>
                                    <th>Session Details</th>
                                    <th>Duration<br>Hr</th>
                                    <th>Faculty Name</th>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="planBody">
                                <?php
                                // if just uploaded show immediately
                                if ($grid) {
                                    foreach ($grid as $i => $r) {
                                        [$no, $type, $det, $dur] = $r;
                                        echo '<tr>
                        <td><input name="rows[' . $i . '][no]" class="form-control form-control-sm" value="' . h($no) . '"></td>
                        <td><input name="rows[' . $i . '][type]" class="form-control form-control-sm" value="' . h(normalize_session_type($type)) . '"></td>
                        <td><textarea name="rows[' . $i . '][details]" class="form-control form-control-sm" rows="2">' . h($det) . '</textarea></td>
                        <td><input name="rows[' . $i . '][duration]" class="form-control form-control-sm" value="' . h($dur) . '"></td>
                        <td><input name="rows[' . $i . '][faculty]" class="form-control form-control-sm" value=""></td>
                        <td><input name="rows[' . $i . '][date]" class="form-control form-control-sm" value=""></td>
                        <td><input name="rows[' . $i . '][day]" class="form-control form-control-sm" value=""></td>
                        <td><input name="rows[' . $i . '][time]" class="form-control form-control-sm" value=""></td>
                      </tr>';
                                    }
                                } else {
                                    for ($i = 0; $i < 5; $i++) echo '<tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        let lastCourseData = null;

        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const clearSearch = document.getElementById('clearSearch');
        const courseTitle = document.getElementById('courseTitle');
        const moduleSelect = document.getElementById('moduleSelect');
        const modeSelect = document.getElementById('modeSelect');
        const modeDetails = document.getElementById('modeDetails');
        const planBody = document.getElementById('planBody');

        const hidCourseId = document.getElementById('course_id_hidden');
        const hidCourseCode = document.getElementById('course_code_hidden');
        const hidModule = document.getElementById('module_code_hidden');
        const hidMode = document.getElementById('learning_mode_hidden');

        // search
        searchInput.addEventListener('input', async () => {
            const q = searchInput.value.trim();
            clearSearch.style.display = q ? 'block' : 'none';
            if (!q) {
                resultsBox.innerHTML = '';
                return;
            }
            const res = await fetch('search_courses.php?q=' + encodeURIComponent(q));
            const rows = await res.json();
            resultsBox.innerHTML = rows.map(r => `
    <button class="list-group-item list-group-item-action"
            data-id="${r.course_id}" data-code="${r.course_code}">
      [${r.course_code}] ${r.course_title_external}
    </button>`).join('');
        });
        clearSearch.addEventListener('click', () => {
            searchInput.value = '';
            resultsBox.innerHTML = '';
            clearSearch.style.display = 'none';
            searchInput.focus();
        });

        // pick course
        resultsBox.addEventListener('click', async e => {
            const btn = e.target.closest('button');
            if (!btn) return;
            resultsBox.innerHTML = '';
            searchInput.value = btn.textContent.trim();

            const cid = btn.dataset.id;
            const res = await fetch('get_course_details.php?id=' + cid);
            const data = await res.json();
            lastCourseData = data;

            courseTitle.textContent = btn.textContent;

            // hidden fields for upload meta
            hidCourseId.value = cid;
            hidCourseCode.value = btn.dataset.code || '';

            // modes
            modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
                (data.data.master_learning_modes || []).map((m, i) => `<option value="${i}">${m.mode}</option>`).join('');
            modeDetails.innerHTML = '';

            // modules
            moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
                (data.data.modules || []).map(m => `<option value="${m.module_code}">${m.module_title} [${m.module_code}]</option>`).join('');

            hidModule.value = '';
            hidMode.value = '';
            planBody.innerHTML = ''; // clear table
        });

        // set module
        moduleSelect.addEventListener('change', () => {
            hidModule.value = moduleSelect.value || '';
            tryLoadSavedTemplate(); // cohort not needed
        });

        // set mode
        modeSelect.addEventListener('change', () => {
            if (!lastCourseData) {
                hidMode.value = '';
                modeDetails.innerHTML = '';
                return;
            }
            const idx = modeSelect.value;
            if (idx === "") {
                hidMode.value = '';
                modeDetails.innerHTML = '';
                return;
            }
            const mode = lastCourseData.data.master_learning_modes[idx] || {};
            hidMode.value = mode.mode || '';
            modeDetails.innerHTML = mode.mode ? `<div class="card card-body mb-2">
    <p><b>Mode:</b> ${mode.mode || ''} |
    <b>Course Duration:</b> ${mode.course_duration || ''} |
    <b>Days per Week:</b> ${mode.days_per_week || ''} |
    <b>Hours per Day:</b> ${mode.hours_per_day || ''} |
    <b>Hours per Week:</b> ${mode.hours_per_week || ''}</p>
  </div>` : '';
            tryLoadSavedTemplate();
        });

        // fetch latest template by Course+Module+Mode+User
        async function tryLoadSavedTemplate() {
            const courseId = hidCourseId.value.trim();
            const moduleCode = hidModule.value.trim();
            const learningMode = hidMode.value.trim();
            if (!courseId || !moduleCode || !learningMode) return;

            const url = `get_template.php?course_id=${encodeURIComponent(courseId)}&module_code=${encodeURIComponent(moduleCode)}&learning_mode=${encodeURIComponent(learningMode)}`;
            const r = await fetch(url);
            const j = await r.json();
            if (!j.ok) return;

            if (j.rows.length === 0) {
                planBody.innerHTML = '';
                return;
            }

            planBody.innerHTML = j.rows.map((row, i) => `
    <tr>
      <td><input name="rows[${i}][no]" class="form-control form-control-sm" value="${row.session_no ?? ''}"></td>
      <td><input name="rows[${i}][type]" class="form-control form-control-sm" value="${row.session_type ?? ''}"></td>
      <td><textarea name="rows[${i}][details]" class="form-control form-control-sm" rows="2">${row.session_details ?? ''}</textarea></td>
      <td><input name="rows[${i}][duration]" class="form-control form-control-sm" value="${row.duration_hr ?? ''}"></td>
      <td><input name="rows[${i}][faculty]" class="form-control form-control-sm" value=""></td>
      <td><input name="rows[${i}][date]" class="form-control form-control-sm" value=""></td>
      <td><input name="rows[${i}][day]" class="form-control form-control-sm" value=""></td>
      <td><input name="rows[${i}][time]" class="form-control form-control-sm" value=""></td>
    </tr>
  `).join('');
        }
    </script>
</body>

</html>