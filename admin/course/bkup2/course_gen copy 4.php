<?php

session_start();
date_default_timezone_set('Asia/Singapore'); // adjust if needed

// autoload & env
require_once __DIR__ . '/../../vendor/autoload.php';
if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
    $dotenv->safeLoad();
}
$baseUrl = $_ENV['BASE_URL'] ?? '/';

// DB
require __DIR__ . '/../../db.php';

// auth guard (adjust to your app)
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$username = $_SESSION['username'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// layout includes (optional – comment out if you don't use them)
if (is_file(__DIR__ . '/../../components/header.php')) require __DIR__ . '/../../components/header.php';
if (is_file(__DIR__ . '/../../components/navbar.php')) require __DIR__ . '/../../components/navbar.php';

function isPost($key)
{
    return isset($_POST[$key]);
}
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csv_to_rows(string $tmpPath, int $skipRows = 6): array
{
    $rows = [];
    if (($fh = fopen($tmpPath, 'r')) === false) return $rows;

    $need = [
        'session no'         => null,
        'session type - mode' => null,
        'session details'    => null,
        'duration hrs'       => null,
    ];


    $first = true;
    $skipped = 0;

    while (($cols = fgetcsv($fh)) !== false) {
        if ($first && isset($cols[0])) { // remove BOM once
            $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cols[0]);
            $first = false;
        }

        if ($skipped < $skipRows) { // optionally skip top N lines
            $skipped++;
            continue;
        }

        // skip fully empty rows
        if (count(array_filter($cols, fn($v) => trim((string)$v) !== '')) === 0) continue;

        $rows[] = $cols;
    }
    fclose($fh);
    return $rows;
}
function normalize_session_type($s)
{
    $x = strtolower(trim($s));
    if (in_array($x, ['ms-sync', 'ms sync', 'mssync'])) return 'MS-Sync';
    if (in_array($x, ['ms-async', 'ms async', 'msasync', 'ms-asyn', 'ms-asyn c'])) return 'MS-ASync';
    return $s;
}
function faculty_from_type($type)
{
    $t = normalize_session_type($type);
    return ($t === 'MS-Sync') ? 'Mentor' : (($t === 'MS-ASync') ? 'NA' : '');
}
function weekday_name(DateTime $d)
{
    return $d->format('D');
}
function ymd(DateTime $d)
{
    return $d->format('Y-m-d');
}

function upsert_courses_from_api(mysqli $conn): array
{
    $url = "https://ce.educlaas.com/product-app/views/courses/api/claas-ai-app/admin/get-course-information";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $http < 200 || $http >= 300) {
        return ['ok' => false, 'msg' => "API error ($http): " . ($err ?: 'unexpected response')];
    }

    $json = json_decode($res, true);
    if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
        return ['ok' => false, 'msg' => 'API payload missing or malformed'];
    }

    $stmt = $conn->prepare("
        INSERT INTO courses (course_id, course_code, course_title_external)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
          course_code = VALUES(course_code),
          course_title_external = VALUES(course_title_external)
    ");
    if (!$stmt) {
        return ['ok' => false, 'msg' => 'DB prepare failed: ' . $conn->error];
    }

    $count = 0;
    foreach ($json['data'] as $row) {
        $course_id  = (string)($row['course_id'] ?? '');
        $course_code = (string)($row['course_code'] ?? '');
        $title_ext   = (string)($row['course_title_external'] ?? '');
        if ($course_id === '' || $course_code === '' || $title_ext === '') continue;

        $stmt->bind_param("sss", $course_id, $course_code, $title_ext);
        if ($stmt->execute()) $count++;
    }
    $stmt->close();

    return ['ok' => true, 'msg' => "Courses saved/updated: $count", 'count' => $count];
}

// Handle the Refresh button POST
if (isPost('refresh')) {
    // Optional: ensure only admins can refresh
    // if (($_SESSION['role'] ?? '') !== 'admin') {
    //     $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Access denied.'];
    //     header('Location: ' . $_SERVER['PHP_SELF']); exit;
    // }

    if (!$conn) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'DB not configured.'];
    } else {
        $r = upsert_courses_from_api($conn);
        $_SESSION['flash'] = [
            'type' => $r['ok'] ? 'success' : 'danger',
            'message' => $r['msg']
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


/* ======= Holidays (Nager.Date) ======= */
function fetch_public_holidays($countryCode, $year)
{
    $url = "https://date.nager.at/api/v3/PublicHolidays/$year/$countryCode";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $http < 200 || $http >= 300) return [];
    $data = json_decode($res, true);
    if (!is_array($data)) return [];
    $set = [];
    foreach ($data as $h) if (!empty($h['date'])) $set[$h['date']] = true;
    return $set;
}
function generate_schedule(array $rows, string $startDate, array $allowedDays, string $country)
{
    $start = new DateTime($startDate);
    $yearsNeeded = [(int)$start->format('Y'), (int)$start->format('Y') + 1];
    $holidaySets = [];
    foreach (array_unique($yearsNeeded) as $yr) $holidaySets[$yr] = fetch_public_holidays($country, $yr);

    $isHoliday = function (DateTime $d) use (&$holidaySets) {
        return isset($holidaySets[(int)$d->format('Y')][$d->format('Y-m-d')]);
    };

    $map = [
        'Monday' => 'Mon',
        'Tuesday' => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday' => 'Thu',
        'Friday' => 'Fri',
        'Saturday' => 'Sat',
        'Sunday' => 'Sun',
        'Mon' => 'Mon',
        'Tue' => 'Tue',
        'Wed' => 'Wed',
        'Thu' => 'Thu',
        'Fri' => 'Fri',
        'Sat' => 'Sat',
        'Sun' => 'Sun'
    ];
    $allowed = [];
    foreach ($allowedDays as $d) if (isset($map[$d])) $allowed[$map[$d]] = true;
    if (!$allowed) return [];

    $dates = [];
    $cursor = clone $start;
    $need = count($rows);
    while (count($dates) < $need) {
        if (isset($allowed[$cursor->format('D')]) && !$isHoliday($cursor)) $dates[] = clone $cursor;
        $cursor->modify('+1 day');
        if ((clone $cursor)->diff($start)->days > 550) break;
    }
    return $dates;
}

/* =========================
   Step 1 — CSV Upload
   ========================= */
$grid = $_SESSION['grid'] ?? null; // [no,type,details,duration]
if (isPost('uploadCsv') && isset($_FILES['csvFile']) && is_uploaded_file($_FILES['csvFile']['tmp_name'])) {
    $rows = csv_to_rows($_FILES['csvFile']['tmp_name']);
    if ($rows && preg_match('/session/i', $rows[0][0] ?? '')) array_shift($rows);
    $clean = [];
    foreach ($rows as $r) {
        $c0 = trim($r[0] ?? '');
        $c1 = trim($r[1] ?? '');
        $c2 = trim($r[2] ?? '');
        $c3 = trim($r[3] ?? '');
        if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '') continue;
        $clean[] = [$c0, $c1, $c2, $c3];
    }
    $_SESSION['grid'] = $grid = $clean;
}

/* =========================
   Step 2 — Generate (server state)
   ========================= */
$generated = $_SESSION['generated'] ?? [];

/* IMPORTANT: If user edited the table and submitted, capture it NOW */
if (!empty($_POST['rows'])) {
    // rows come as [index => ['no'=>..,'type'=>..,'details'=>..,'duration'=>..,'faculty'=>..,'date'=>..,'day'=>..,'time'=>..]]
    // Persist into session so both DB and PDF see the latest edits
    $_SESSION['generated'] = $generated = array_values($_POST['rows']);
}

if ($grid && isPost('generateSchedule')) {
    $startDate   = trim($_POST['start_date'] ?? '');
    $country     = trim($_POST['country'] ?? 'SG');
    $days        = $_POST['days'] ?? [];
    $slot        = trim($_POST['time_slot'] ?? '');
    $customStart = trim($_POST['custom_start'] ?? '');
    $customEnd   = trim($_POST['custom_end'] ?? '');

    if (!$startDate || !$days || !$country) {
        $error = "Please provide Start Date, at least one Day, and Country.";
    } else {
        $dates = generate_schedule($grid, $startDate, $days, $country);
        $generated = [];
        foreach ($grid as $i => $row) {
            [$no, $type, $details, $duration] = $row;
            $typeNorm  = normalize_session_type($type);
            $faculty   = faculty_from_type($typeNorm);
            $dateCell  = isset($dates[$i]) ? ymd($dates[$i]) : '';
            $dayCell   = isset($dates[$i]) ? weekday_name($dates[$i]) : '';
            if ($typeNorm === 'MS-ASync') {
                $time = 'Self-Paced Before Sync Session';
            } else {
                $time = ($customStart && $customEnd) ? ($customStart . ' - ' . $customEnd) : ($slot ?: '19:00 - 22:00');
            }
            $generated[] = [
                'no'       => $no,
                'type'     => $typeNorm,
                'details'  => $details,
                'duration' => $duration,
                'faculty'  => $faculty,
                'date'     => $dateCell,
                'day'      => $dayCell,
                'time'     => $time,
            ];
        }
        $_SESSION['generated'] = $generated;
    }
}

/* =========================
   Save to DB (uses latest edited rows)
   ========================= */
if (isPost('saveToDb')) {
    $payload     = $_SESSION['generated'] ?? [];
    $cohortCode  = trim($_POST['cohort_code'] ?? '');
    $saved = 0;
    $errMsg = '';

    if ($payload && $conn) {
        $stmt = $conn->prepare(
            "INSERT INTO session_plans
             (cohort_code, user_id, session_no, session_type, session_details, duration_hr, faculty_name, date, day, time_slot)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        if ($stmt) {
            foreach ($payload as $r) {
                $stmt->bind_param(
                    'sissssssss',
                    $cohortCode,
                    $userId,
                    $r['no'],
                    $r['type'],
                    $r['details'],
                    $r['duration'],
                    $r['faculty'],
                    $r['date'],
                    $r['day'],
                    $r['time']
                );
                if ($stmt->execute()) $saved++;
            }
            $stmt->close();
        } else {
            $errMsg = 'DB insert prepare failed.';
        }
    } else {
        $errMsg = $conn ? 'Nothing to save.' : 'DB not configured; skipping save.';
    }
    $_SESSION['save_msg'] = $saved ? "Saved $saved rows." : ($errMsg ?: 'Nothing saved.');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Session Plan Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-check-inline .form-check-input {
            margin-top: 6px;
        }

        table thead th {
            position: sticky;
            top: 0;
            background: #0e1627;
            color: #fff;
            z-index: 2;
        }

        .small-note {
            font-size: .9rem;
            opacity: .85;
        }

        #results {
            position: absolute;
            z-index: 1000;
            width: 100%;
        }

        #results .list-group-item {
            cursor: pointer;
        }

        .table td,
        .table th {
            text-align: center;
            vertical-align: middle;
        }
    </style>
</head>

<body class="py-4">
    <div class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mt-2">
                <?= h($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>


        <!-- Search + Refresh (from your existing UI) -->
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

        <!-- Course / Module / Mode -->
        <div id="courseDetails" class="mt-2">
            <h6 id="courseTitle"></h6>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Learning Modules</label>
                    <select id="moduSelect2" class="form-select mb-2">
                        <option value="">Select a module...</option>
                    </select>
                    <!-- hidden field to hold selected module code for cohort -->
                    <input type="hidden" id="module_code_hidden" value="">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Learning Modes</label>
                    <select id="modeSelect" class="form-select mb-2">
                        <option value="">Select a mode...</option>
                    </select>
                    <div id="modeDetails"></div>
                </div>
            </div>

            <!-- NEW: Cohort inputs (under module selection) -->
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Cohort Suffix (Your text)</label>
                    <input type="text" id="cohort_suffix" class="form-control" placeholder="e.g., 2025A">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cohort Code (ModuleCode + Suffix)</label>
                    <input type="text" id="cohort_code_display" class="form-control" placeholder="auto-generated" readonly>
                </div>
            </div>
        </div>

        <!-- Step 1: CSV -->
        <div class="card my-4">
            <div class="card-body">
                <h5 class="card-title">Step 1 — Upload CSV (first 4 columns)</h5>
                <p class="small-note mb-2">Expected: <strong>Session No</strong>, <strong>Session Type</strong> (MS-Sync / MS-ASync), <strong>Session Details</strong>, <strong>Duration Hr</strong>.</p>
                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <div class="col-md-6">
                        <input class="form-control" type="file" name="csvFile" accept=".csv" required>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary" name="uploadCsv" type="submit">Upload CSV</button>
                    </div>
                    <?php if ($grid): ?>
                        <div class="col-md-12">
                            <div class="alert alert-success py-2">CSV uploaded. Rows detected: <strong><?= count($grid) ?></strong></div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Step 2: Inputs + Generate -->
        <div id="pdfArea">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Step 2 — Inputs</h5>
                    <form method="post" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" <?php if (!$grid) echo 'disabled'; ?> required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block">Day Pattern (choose days)</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
                                    <label class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="days[]" value="<?= $d ?>" <?= !$grid ? 'disabled' : '' ?>>
                                        <span class="form-check-label"><?= $d ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Country (for Holidays)</label>
                            <select class="form-select" name="country" <?php if (!$grid) echo 'disabled'; ?> required>
                                <option value="SG">Singapore</option>
                                <option value="IN">India</option>
                                <option value="LK" selected>Sri Lanka</option>
                            </select>
                            <div class="small-note mt-1">Uses Nager.Date public-holidays API.</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Time Slot</label>
                            <select class="form-select" name="time_slot" <?php if (!$grid) echo 'disabled'; ?>>
                                <option value="09:00 - 10:00">09:00 - 10:00</option>
                                <option value="10:00 - 12:00">10:00 - 12:00</option>
                                <option value="14:00 - 16:00">14:00 - 16:00</option>
                                <option value="19:00 - 22:00" selected>19:00 - 22:00</option>
                            </select>
                            <div class="small-note">Used for <em>MS-Sync</em> only.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">OR Custom Time (Sync rows)</label>
                            <div class="input-group">
                                <input type="time" class="form-control" name="custom_start" <?php if (!$grid) echo 'disabled'; ?>>
                                <span class="input-group-text">to</span>
                                <input type="time" class="form-control" name="custom_end" <?php if (!$grid) echo 'disabled'; ?>>
                            </div>
                            <div class="small-note">If both set, overrides the dropdown.</div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" name="generateSchedule" type="submit" <?php if (!$grid) echo 'disabled'; ?>>Generate Schedule</button>
                            <?php if (isset($error)): ?><span class="text-danger ms-3"><?= h($error) ?></span><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Editable Table + Actions in ONE form so we can post edits -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Session Plan</h5>

                    <form method="post" id="saveForm">
                        <!-- hidden cohort_code (computed) -->
                        <input type="hidden" name="cohort_code" id="cohort_code_hidden" required>

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
                                <tbody>
                                    <?php
                                    $show = $generated ?: [];
                                    if (!$show && $grid) {
                                        foreach ($grid as $r) {
                                            [$no, $type, $details, $dur] = $r;
                                            echo '<tr>
                                        <td>' . h($no) . '</td>
                                        <td>' . h(normalize_session_type($type)) . '</td>
                                        <td>' . h($details) . '</td>
                                        <td>' . h($dur) . '</td>
                                        <td>' . h(faculty_from_type($type)) . '</td>
                                        <td></td><td></td><td></td>
                                    </tr>';
                                        }
                                    } elseif ($show) {
                                        foreach ($show as $i => $r) {
                                            echo '<tr>
                                        <td><input name="rows[' . $i . '][no]" class="form-control form-control-sm" value="' . h($r['no']) . '"></td>
                                        <td><input name="rows[' . $i . '][type]" class="form-control form-control-sm" value="' . h($r['type']) . '"></td>
                                        <td><textarea name="rows[' . $i . '][details]" class="form-control form-control-sm" rows="2">' . h($r['details']) . '</textarea></td>
                                        <td><input name="rows[' . $i . '][duration]" class="form-control form-control-sm" value="' . h($r['duration']) . '"></td>
                                        <td><input name="rows[' . $i . '][faculty]" class="form-control form-control-sm" value="' . h($r['faculty']) . '"></td>
                                        <td><input name="rows[' . $i . '][date]" class="form-control form-control-sm" value="' . h($r['date']) . '"></td>
                                        <td><input name="rows[' . $i . '][day]" class="form-control form-control-sm" value="' . h($r['day']) . '"></td>
                                        <td><input name="rows[' . $i . '][time]" class="form-control form-control-sm" value="' . h($r['time']) . '"></td>
                                    </tr>';
                                        }
                                    } else {
                                        for ($i = 1; $i <= 5; $i++) {
                                            echo '<tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($_SESSION['generated'])): ?>
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-success" name="saveToDb" type="submit">Save to Database</button>

                                <!-- Download PDF: post the SAME edited rows & cohort_code to download_pdf.php -->
                                <button class="btn btn-danger"
                                    formaction="download_pdf.php"
                                    formmethod="post">Download PDF</button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($_SESSION['save_msg'])): ?>
                            <div class="alert alert-info mt-3 py-2">
                                <?= $_SESSION['save_msg'];
                                unset($_SESSION['save_msg']); ?>
                            </div>
                        <?php endif; ?>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <!-- JS -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        // Disable slot if custom times filled
        $(function() {
            function refreshTimeHint() {
                const hasCustom = $('input[name="custom_start"]').val() && $('input[name="custom_end"]').val();
                $('select[name="time_slot"]').prop('disabled', !!hasCustom);
            }
            $('input[name="custom_start"], input[name="custom_end"]').on('input', refreshTimeHint);
            refreshTimeHint();
        });

        // ---- Search, select course, load details (your existing endpoints) ----
        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const clearSearchBtn = document.getElementById('clearSearch');
        const courseTitle = document.getElementById('courseTitle');
        const modeSelect = document.getElementById('modeSelect');
        const modeDetails = document.getElementById('modeDetails');
        const moduleSelect = document.getElementById('moduSelect2');
        const moduleCodeHidden = document.getElementById('module_code_hidden');

        let lastCourseData = null;

        searchInput.addEventListener('input', async () => {
            const q = searchInput.value.trim();
            clearSearchBtn.style.display = q ? 'block' : 'none';
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
            </button>
        `).join('');
        });
        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            resultsBox.innerHTML = '';
            clearSearchBtn.style.display = 'none';
            searchInput.focus();
        });
        resultsBox.addEventListener('click', async e => {
            const btn = e.target.closest('button');
            if (!btn) return;
            searchInput.value = btn.textContent.trim();
            resultsBox.innerHTML = '';
            const cid = btn.dataset.id;
            const res = await fetch('get_course_details.php?id=' + cid);
            const data = await res.json();
            lastCourseData = data;

            courseTitle.textContent = btn.textContent;

            modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
                (data.data.master_learning_modes || []).map((mode, i) =>
                    `<option value="${i}">${mode.mode}</option>`).join('');
            modeDetails.innerHTML = '';

            moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
                (data.data.modules || []).map(m =>
                    `<option value="${m.module_code}">${m.module_title} [${m.module_code}]</option>`).join('');

            // reset cohort fields
            moduleCodeHidden.value = '';
            updateCohortCode();
        });
        modeSelect.addEventListener('change', function() {
            if (!lastCourseData) return;
            const idx = this.value;
            modeDetails.innerHTML = (idx === "") ? "" :
                (function(mode) {
                    return `<div class="card card-body mb-2">
                    <p><b>Mode:</b> ${mode.mode || ''} |
                    <b>Course Duration:</b> ${mode.course_duration || ''} |
                    <b>Days per Week:</b> ${mode.days_per_week || ''} |
                    <b>Hours per Day:</b> ${mode.hours_per_day || ''} |
                    <b>Hours per Week:</b> ${mode.hours_per_week || ''}</p>
                </div>`;
                })(lastCourseData.data.master_learning_modes[idx]);
        });

        // ---- Cohort code builder: module_code + user suffix ----
        const cohortSuffixInput = document.getElementById('cohort_suffix');
        const cohortCodeDisplay = document.getElementById('cohort_code_display');
        const cohortCodeHidden = document.getElementById('cohort_code_hidden');

        moduleSelect.addEventListener('change', function() {
            moduleCodeHidden.value = this.value || '';
            updateCohortCode();
        });
        cohortSuffixInput.addEventListener('input', updateCohortCode);

        function updateCohortCode() {
            const mcode = moduleCodeHidden.value.trim();
            const suff = (cohortSuffixInput.value || '').trim();
            const code = (mcode && suff) ? (mcode + '-' + suff) : (mcode || '');
            cohortCodeDisplay.value = code;
            cohortCodeHidden.value = code;
        }
    </script>
</body>

</html>