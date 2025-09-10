<?php

session_start();
date_default_timezone_set('Asia/Colombo'); // adjust if needed

require_once __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ./login.php");
};

$username = $_SESSION['username'] ?? '';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require __DIR__ . '/../../components/header.php';
require __DIR__ . '/../../components/navbar.php';


/* ======= Helpers ======= */
function isPost($key)
{
    return isset($_POST[$key]);
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
            // skip completely empty rows
            if (count(array_filter($cols, fn($v) => trim($v) !== '')) === 0) continue;
            $rows[] = $cols;
        }
        fclose($fh);
    }
    return $rows;
}
function normalize_session_type($s)
{
    // tolerate minor casing/spacing variations
    $x = strtolower(trim($s));
    if ($x === 'ms-sync' || $x === 'ms sync' || $x === 'mssync') return 'MS-Sync';
    if ($x === 'ms-async' || $x === 'ms async' || $x === 'msasync' || $x === 'ms-asyn' || $x === 'ms-asyn c') return 'MS-ASync';
    return $s; // as-is if unknown
}
function faculty_from_type($type)
{
    $t = normalize_session_type($type);
    return ($t === 'MS-Sync') ? 'Mentor' : (($t === 'MS-ASync') ? 'NA' : '');
}
function weekday_name(DateTime $d)
{
    return $d->format('D');
} // Mon/Tue/...
function ymd(DateTime $d)
{
    return $d->format('Y-m-d');
}

/* ======= Holiday Fetching (Nager.Date — no API key needed) ======= */
/* Standard, public, and widely used: https://date.nager.at */
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

    if ($err || $http < 200 || $http >= 300) {
        // If API is unreachable, return empty — you can add a local fallback list here if desired.
        return [];
    }
    $data = json_decode($res, true);
    if (!is_array($data)) return [];
    // return set of Y-m-d strings for quick membership tests
    $set  = [];
    foreach ($data as $h) {
        if (!empty($h['date'])) $set[$h['date']] = true;
    }
    return $set;
}

/* ======= Generate schedule dates skipping holidays ======= */
function generate_schedule(array $rows, string $startDate, array $allowedDays, string $country)
{
    // Build holiday sets for the necessary years (could span two years)
    $start = new DateTime($startDate);
    $yearsNeeded = [$start->format('Y')];
    // Conservative: add the next year just in case schedule crosses year boundary
    $yearsNeeded[] = (string)((int)$yearsNeeded[0] + 1);
    $holidaySets = [];
    foreach (array_unique($yearsNeeded) as $yr) {
        $holidaySets[$yr] = fetch_public_holidays($country, (int)$yr);
    }
    // Helper to test holiday
    $isHoliday = function (DateTime $d) use (&$holidaySets) {
        $yr = $d->format('Y');
        $key = $d->format('Y-m-d');
        return isset($holidaySets[$yr][$key]);
    };

    // Normalize allowedDays to PHP "D" format (Mon, Tue, ... Sun)
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
    foreach ($allowedDays as $d) {
        if (isset($map[$d])) $allowed[$map[$d]] = true;
    }
    if (!$allowed) return []; // nothing allowed

    // Iterate calendar days from startDate, collect as many valid dates as rows.
    $dates = [];
    $cursor = clone $start;
    $need = count($rows);
    while (count($dates) < $need) {
        $dow = $cursor->format('D');         // Mon/Tue/...
        $ymd = $cursor->format('Y-m-d');
        if (isset($allowed[$dow]) && !$isHoliday($cursor)) {
            $dates[] = clone $cursor;
        }
        $cursor->modify('+1 day');
        // Guard: avoid infinite loop
        if ((clone $cursor)->diff($start)->days > 550) break;
    }
    return $dates;
}

/* =========================
   Step 1 — CSV Upload
   ========================= */
$grid = $_SESSION['grid'] ?? null; // array of rows [session_no, type, details, duration]
if (isPost('uploadCsv') && isset($_FILES['csvFile']) && is_uploaded_file($_FILES['csvFile']['tmp_name'])) {
    $rows = csv_to_rows($_FILES['csvFile']['tmp_name']);

    // Expect header? We’ll detect if first row has text headers and drop it.
    if ($rows && preg_match('/session/i', $rows[0][0] ?? '')) {
        array_shift($rows);
    }
    // Validate/reshape to 4 columns
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
   Step 2 — Generate Schedule
   ========================= */
$generated = [];
if ($grid && isPost('generateSchedule')) {
    $startDate = trim($_POST['start_date'] ?? '');
    $country   = trim($_POST['country'] ?? 'SG'); // SG, IN, LK
    $days      = $_POST['days'] ?? [];
    $slot      = trim($_POST['time_slot'] ?? '');
    $customStart = trim($_POST['custom_start'] ?? '');
    $customEnd   = trim($_POST['custom_end'] ?? '');

    // Defensive checks
    if (!$startDate || !$days || !$country) {
        $error = "Please provide Start Date, at least one Day, and Country.";
    } else {
        $dates = generate_schedule($grid, $startDate, $days, $country);

        foreach ($grid as $i => $row) {
            [$no, $type, $details, $duration] = $row;
            $typeNorm  = normalize_session_type($type);
            $faculty   = faculty_from_type($typeNorm);

            $dateCell  = isset($dates[$i]) ? ymd($dates[$i]) : '';
            $dayCell   = isset($dates[$i]) ? weekday_name($dates[$i]) : '';

            // Time rule:
            // MS-ASync → "Self-Paced Before Sync Session"
            // MS-Sync  → dropdown slot or custom start-end if provided
            if ($typeNorm === 'MS-ASync') {
                $time = 'Self-Paced Before Sync Session';
            } else {
                if ($customStart && $customEnd) {
                    $time = $customStart . ' - ' . $customEnd;
                } else {
                    $time = $slot ?: '19:00 - 22:00';
                }
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
   (Optional) Save to DB
   ========================= */
if (isPost('saveToDb')) {
    $payload = $_SESSION['generated'] ?? [];
    $saved = 0;
    $errMsg = '';
    if ($payload && $mysqli) {
        /* Example schema:
        CREATE TABLE session_plans (
          id INT AUTO_INCREMENT PRIMARY KEY,
          session_no VARCHAR(16),
          session_type VARCHAR(32),
          session_details TEXT,
          duration_hr VARCHAR(8),
          faculty_name VARCHAR(64),
          date DATE,
          day VARCHAR(16),
          time_slot VARCHAR(64),
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        */
        $stmt = $mysqli->prepare(
            "INSERT INTO session_plans
             (session_no, session_type, session_details, duration_hr, faculty_name, date, day, time_slot)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        if ($stmt) {
            foreach ($payload as $r) {
                $stmt->bind_param(
                    'ssssssss',
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
        $errMsg = $mysqli ? 'Nothing to save.' : 'DB not configured; skipping save.';
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
            z-index: 2;
        }

        .badge-holiday {
            background: #b23;
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
        <div id="courseDetails" class="mt-4">
            <h6 id="courseTitle"></h6>

            <h6>Learning Modules</h6>
            <select id="moduSelect2" class="form-select mb-2">
                <option value="">Select a module...</option>
            </select>

            <h6>Learning Modes</h6>
            <select id="modeSelect" class="form-select mb-2">
                <option value="">Select a mode...</option>
            </select>
            <div id="modeDetails"></div>

        </div>


        <!-- Step 1: CSV Upload -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Step 1 — Upload CSV (first 4 columns)</h5>
                <p class="small-note mb-2">Expected columns (in order): <strong>Session No</strong>, <strong>Session Type</strong> (use <em>MS-Sync</em> / <em>MS-ASync</em>), <strong>Session Details</strong>, <strong>Duration Hr</strong>.</p>
                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <div class="col-md-6">
                        <input class="form-control" type="file" name="csvFile" accept=".csv" required>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-warning" name="uploadCsv" type="submit">Upload CSV</button>
                    </div>
                    <?php if ($grid): ?>
                        <div class="col-md-12">
                            <div class="alert alert-success py-2">CSV uploaded. Rows detected: <strong><?= count($grid) ?></strong></div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div id="pdfArea">
            <!-- Step 2: Inputs + Generate -->
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
                            <!-- <button type="button" class="btn btn-sm btn-outline-primary mb-2" id="toggleWeekdays">Select Weekdays (Mon–Fri)</button> -->
                            <div class="d-flex flex-wrap gap-3">
                                <?php
                                $daysAll = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                foreach ($daysAll as $d) {
                                    echo '<label class="form-check form-check-inline">
                                <input class="form-check-input day-allow" type="checkbox" name="days[]" value="' . $d . '" ' . (!$grid ? 'disabled' : '') . '>
                                <span class="form-check-label">' . $d . '</span>
                                </label>';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Country (for Holidays)</label>
                            <select class="form-select" name="country" <?php if (!$grid) echo 'disabled'; ?> required>
                                <option value="SG">Singapore</option>
                                <option value="IN">India</option>
                                <option value="LK">Sri Lanka</option>
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
                            <div class="small-note">Used for <em>MS-Sync</em> rows only.</div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">OR Custom Time (Sync rows)</label>
                            <div class="input-group">
                                <input type="time" class="form-control" name="custom_start" <?php if (!$grid) echo 'disabled'; ?>>
                                <span class="input-group-text">to</span>
                                <input type="time" class="form-control" name="custom_end" <?php if (!$grid) echo 'disabled'; ?>>
                            </div>
                            <div class="small-note">If both set, overrides the dropdown for Sync sessions.</div>
                        </div>

                        <div class="col-12">
                            <button class="btn btn-primary" name="generateSchedule" type="submit" <?php if (!$grid) echo 'disabled'; ?>>Generate Schedule</button>
                            <?php if (isset($error)): ?>
                                <span class="text-danger ms-3"><?= h($error) ?></span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table: Empty or Generated -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Session Plan</h5>
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
                                $show = $generated ?: []; // if none, show empty or the CSV-only rows
                                if (!$show && $grid) {
                                    // Show CSV rows with Faculty filled, but no date/day/time yet
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
                          <td><input class="form-control form-control-sm" value="' . h($r['no']) . '"></td>
                          <td><input class="form-control form-control-sm" value="' . h($r['type']) . '"></td>
                          <td><textarea class="form-control form-control-sm" rows="2">' . h($r['details']) . '</textarea></td>
                          <td><input class="form-control form-control-sm" value="' . h($r['duration']) . '"></td>
                          <td><input class="form-control form-control-sm" value="' . h($r['faculty']) . '"></td>
                          <td><input class="form-control form-control-sm" value="' . h($r['date']) . '"></td>
                          <td><input class="form-control form-control-sm" value="' . h($r['day']) . '"></td>
                          <td><input class="form-control form-control-sm" value="' . h($r['time']) . '"></td>
                        </tr>';
                                    }
                                } else {
                                    // Completely empty skeleton
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo '<tr>
                          <td></td><td></td><td></td><td></td>
                          <td></td><td></td><td></td><td></td>
                        </tr>';
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($_SESSION['generated'])): ?>
                        <form method="post" class="mt-3">
                            <button class="btn btn-success" name="saveToDb" type="submit">Save to Database</button>
                            <!-- <span class="small-note ms-2">Editable fields above will not round-trip into DB unless you also wire JS to capture edits. (Lightweight demo keeps server state from generation.)</span> -->
                        </form>

                        <!-- <button class="btn btn-danger mt-3" onclick="downloadPDF()">Download PDF</button> -->
                        <a href="download_pdf.php" class="btn btn-danger mt-3">Download PDF</a>



                    <?php endif; ?>

                    <?php if (!empty($_SESSION['save_msg'])): ?>
                        <div class="alert alert-info mt-3 py-2"><?= $_SESSION['save_msg'];
                                                                unset($_SESSION['save_msg']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-4 small-note">
                <!-- <strong>Holiday source:</strong> Nager.Date API (no key). If you ever need a key-based provider, use Calendarific or Google Calendar Public Holiday ICS as alternatives. -->
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        // If user enters custom start & end, we could dim the dropdown (visual cue)
        $(function() {
            function refreshTimeHint() {
                const hasCustom = $('input[name="custom_start"]').val() && $('input[name="custom_end"]').val();
                $('select[name="time_slot"]').prop('disabled', !!hasCustom);
            }
            $('input[name="custom_start"], input[name="custom_end"]').on('input', refreshTimeHint);
            refreshTimeHint();
        });

        // ----- DOM refs (existing from your page) -----
        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const clearSearchBtn = document.getElementById('clearSearch');

        const courseTitle = document.getElementById('courseTitle');

        const modeSelect = document.getElementById('modeSelect');
        const modeDetails = document.getElementById('modeDetails');
        const moduleSelect = document.getElementById('moduSelect2');


        // Keep the last fetched course details so we can recalc without refetch
        let lastCourseData = null;
        let lastModuleIdx = "";

        // ----- search -----
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

        // ----- after selecting a course result -----
        resultsBox.addEventListener('click', async e => {
            if (!e.target.closest('button')) return;
            const btn = e.target.closest('button');
            searchInput.value = btn.textContent.trim();
            resultsBox.innerHTML = '';

            const cid = btn.dataset.id;
            const res = await fetch('get_course_details.php?id=' + cid);
            const data = await res.json();
            lastCourseData = data;

            // fill top info
            courseTitle.textContent = btn.textContent;

            // learning modes
            modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
                (data.data.master_learning_modes || []).map((mode, i) =>
                    `<option value="${i}">${mode.mode}</option>`).join('');
            modeDetails.innerHTML = '';

            // modules
            moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
                (data.data.modules || []).map((m, i) =>
                    `<option value="${m.module_code}">${m.module_title} [${m.module_code}]</option>`).join('');

            // reset everything for a new selection
            lastModuleIdx = "";
            showHolidayNote();
        });

        // ----- learning mode detail toggle -----
        modeSelect.addEventListener('change', function() {
            if (!lastCourseData) return;
            const idx = this.value;
            if (idx === "") {
                modeDetails.innerHTML = "";
                return;
            }
            const mode = lastCourseData.data.master_learning_modes[idx];
            modeDetails.innerHTML = `
    <div class="card card-body mb-2">
        <p><b>Mode:</b> ${mode.mode || ''}|
            <b>Course Duration:</b> ${mode.course_duration || ''}|
            <b>Days per Week:</b> ${mode.days_per_week || ''}|
            <b>Hours per Day:</b> ${mode.hours_per_day || ''}|
            <b>Hours per Week:</b> ${mode.hours_per_week || ''}
        </p>
    </div>`;
        });
    </script>
</body>

</html>