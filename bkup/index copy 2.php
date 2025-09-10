<?php
session_start();
date_default_timezone_set('Asia/Singapore'); // adjust if needed

require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/db.php';
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/navbar.php';
require __DIR__ . '/admin/course/schedule_lib.php';

$username = $_SESSION['username'] ?? '';
$userId   = $_SESSION['user_id'];
$selected = $_SESSION['selected'] ?? ['course_id' => '', 'course_code' => '', 'module_code' => '', 'learning_mode' => ''];

// auth
if (!isset($_SESSION['user_id'])) {
  header("Location: ./login.php");
  exit;
}

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


// state
$selected = [
  'course_id'     => trim($_GET['course_id'] ?? $_SESSION['course_id'] ?? ''),
  'course_code'   => trim($_GET['course_code'] ?? $_SESSION['course_code'] ?? ''),
  'module_code'   => trim($_GET['module_code'] ?? $_SESSION['module_code'] ?? ''),
  'learning_mode' => trim($_GET['learning_mode'] ?? $_SESSION['learning_mode'] ?? ''),
];

$grid = $_SESSION['grid_rows'] ?? [];       // plain template rows [no,type,details,duration]
$generated = $_SESSION['generated'] ?? [];  // enriched table rows

/* Load template rows when "Load Template" clicked OR prefilled via GET */
if (isPost('loadTemplate') || ($selected['course_id'] && $selected['module_code'] && $selected['learning_mode'])) {
  if ($conn && $selected['course_id'] && $selected['module_code'] && $selected['learning_mode']) {

    // Pick the latest template by created_at for this user + keys
    $stmt = $conn->prepare("
            SELECT id FROM session_templates
            WHERE course_id=? AND module_code=? AND learning_mode=? AND user_id=?
            ORDER BY created_at DESC
            LIMIT 1
        ");
    $stmt->bind_param(
      "sssi",
      $selected['course_id'],
      $selected['module_code'],
      $selected['learning_mode'],
      $userId
    );
    $stmt->execute();
    $stmt->bind_result($templateId);
    $templateId = null;
    if ($stmt->fetch()) {
    }
    $stmt->close();

    $grid = [];
    if ($templateId) {
      $stmt2 = $conn->prepare("
                SELECT session_no, session_type, session_details, duration_hr
                FROM session_template_rows
                WHERE template_id=?
                ORDER BY CAST(session_no AS UNSIGNED), session_no
            ");
      $stmt2->bind_param("i", $templateId);
      $stmt2->execute();
      $stmt2->bind_result($no, $type, $details, $dur);
      while ($stmt2->fetch()) {
        $grid[] = [$no, $type, $details, $dur];
      }
      $stmt2->close();
      $_SESSION['grid_rows'] = $grid;
      $generated = [];
      $_SESSION['generated'] = [];
      $_SESSION['flash'] = ['type' => 'success', 'message' => "Loaded template #$templateId (rows: " . count($grid) . ")."];
      $_SESSION['selected'] = $selected;
      header('Location: ' . $_SERVER['PHP_SELF']); // bare
      exit;
    } else {
      $_SESSION['flash'] = ['type' => 'danger', 'message' => 'No template found. Please create it on the Master page.'];
      header('Location: ' . $_SERVER['PHP_SELF']);
      exit;
    }
  }
}

/* Capture edits from the table (keep the latest in session) */
if (!empty($_POST['rows'])) {
  $_SESSION['generated'] = $generated = array_values($_POST['rows']);
}

/* Generate schedule using multi-country holidays */
if ($grid && isPost('generateSchedule')) {
  $startDate = trim($_POST['start_date'] ?? '');
  $days      = $_POST['days'] ?? [];
  $countries = $_POST['countries'] ?? []; // MULTI
  $slot      = trim($_POST['time_slot'] ?? '');
  $customStart = trim($_POST['custom_start'] ?? '');
  $customEnd   = trim($_POST['custom_end'] ?? '');
  $soc = trim($_POST['soc'] ?? ''); // Optional field (display/use as you like)

  if (!$startDate || !$days || !$countries) {
    $error = "Please provide Start Date, at least one Day, and at least one Country.";
  } else {
    $dates = generate_schedule($grid, $startDate, $days, (array)$countries);
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
    $_SESSION['soc'] = $soc; // keep if you need it for PDF or display
  }
}

if (isPost('clearCsv')) {

  unset($_SESSION['grid_rows'], $_SESSION['generated'], $_SESSION['template_loaded']);
}


/* Optional: Save generated plan to DB (same as your previous insert) */
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
  $_SESSION['selected'] = $selected;
  header('Location: ' . $_SERVER['PHP_SELF']); // bare
  exit;
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Generate Session Plan</title>
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

    #results {
      position: absolute;
      z-index: 1000;
      width: 100%;
    }

    #results .list-group-item {
      cursor: pointer;
    }

    .small-note {
      font-size: .9rem;
      opacity: .85;
    }
  </style>
</head>

<body class="py-4">
  <div class="container">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mt-2"><?= h($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <h4 class="mb-3">Generate Schedule</h4>

    <!-- 1) Pick Course / Module / Mode (loads template rows) -->
    <form method="post" class="mb-4">
      <div class="row g-3">
        <div class="col-12 position-relative">
          <label class="form-label">Search Courses</label>
          <div class="input-group">
            <input type="text" id="search" class="form-control" placeholder="Type to search...">
            <button type="button" id="clearSearch" class="btn btn-outline-secondary" style="display:none;">&times;</button>
          </div>
          <div id="results" class="list-group"></div>
          <div id="courseTitle" class="fw-semibold mt-2">
            <?php if ($selected['course_code']): ?>
              [<?= h($selected['course_code']) ?>]
            <?php endif; ?>
          </div>
        </div>

        <!-- hidden fields to carry selection -->
        <input type="hidden" name="course_id" id="course_id" value="<?= h($selected['course_id']) ?>">
        <input type="hidden" name="course_code" id="course_code" value="<?= h($selected['course_code']) ?>">

        <div class="col-md-6">
          <label class="form-label">Learning Modules</label>
          <select id="moduleSelect" name="module_code" class="form-select" required>
            <?php if ($selected['module_code']): ?>
              <option value="<?= h($selected['module_code']) ?>" selected><?= h($selected['module_code']) ?></option>
            <?php else: ?>
              <option value="">Select a module...</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Learning Modes</label>
          <select id="modeSelect" class="form-select" required>
            <?php if ($selected['learning_mode']): ?>
              <option selected><?= h($selected['learning_mode']) ?></option>
            <?php else: ?>
              <option value="">Select a mode...</option>
            <?php endif; ?>
          </select>
          <input type="hidden" name="learning_mode" id="learning_mode" value="<?= h($selected['learning_mode']) ?>">
          <div id="modeDetails" class="small mt-2"></div>
        </div>

        <div class="col-12">
          <button class="btn btn-primary" name="loadTemplate" type="submit">Load Template</button>
          <a class="btn btn-outline-secondary" href="/admin/course/master_temp.php">Create/Edit Templates</a>
        </div>
      </div>
    </form>

    <!-- 2) Inputs + Generate -->
    <div class="card mb-4 <?= !$grid ? 'd-none' : '' ?>">
      <div class="card-body">
        <h5 class="card-title">Step 2 — Inputs</h5>
        <form method="post" class="row g-3">
          <!-- carry selection -->
          <input type="hidden" name="course_id" value="<?= h($selected['course_id']) ?>">
          <input type="hidden" name="course_code" value="<?= h($selected['course_code']) ?>">
          <input type="hidden" name="module_code" value="<?= h($selected['module_code']) ?>">
          <input type="hidden" name="learning_mode" value="<?= h($selected['learning_mode']) ?>">

          <div class="col-md-3">
            <label class="form-label">Start Date</label>
            <input type="date" class="form-control" name="start_date" required>
          </div>

          <div class="col-md-4">
            <label class="form-label d-block">Day Pattern (choose days)</label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
                <label class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="days[]" value="<?= $d ?>">
                  <span class="form-check-label"><?= $d ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-md-5">
            <label class="form-label">Countries (Public Holidays — multi-select)</label>
            <select class="form-select" name="countries[]" multiple required>
              <option value="SG">Singapore</option>
              <option value="IN">India</option>
              <option value="LK" selected>Sri Lanka</option>
            </select>
            <div class="small-note mt-1">We’ll skip any holiday that appears in <em>any</em> selected country.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label">Time Slot</label>
            <select class="form-select" name="time_slot">
              <option value="09:00 - 10:00">09:00 - 10:00</option>
              <option value="10:00 - 12:00">10:00 - 12:00</option>
              <option value="14:00 - 16:00">14:00 - 16:00</option>
              <option value="19:00 - 22:00" selected>19:00 - 22:00</option>
            </select>
            <div class="small-note">Used for <em>MS-Sync</em> rows; async rows show a self-paced note.</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">OR Custom Time (Sync rows)</label>
            <div class="input-group">
              <input type="time" class="form-control" name="custom_start">
              <span class="input-group-text">to</span>
              <input type="time" class="form-control" name="custom_end">
            </div>
            <div class="small-note">If both set, overrides the dropdown slot.</div>
          </div>

          <div class="col-md-5">
            <label class="form-label">SOC (optional)</label>
            <input type="text" class="form-control" name="soc" placeholder="Any extra label you want to carry">
          </div>

          <div class="col-12">
            <button class="btn btn-primary" name="generateSchedule" type="submit">Generate</button>
            <?php if (isset($error)): ?><span class="text-danger ms-3"><?= h($error) ?></span><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- 3) Editable table + Actions -->
    <div class="card <?= empty($grid) && empty($generated) ? 'd-none' : '' ?>">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="card-title mb-0">Session Plan</h5>
          <form method="post" class="mt-2">
            <button type="submit" name="clearCsv" class="btn btn-secondary">Clear Uploaded CSV</button>
          </form>
        </div>

        <form method="post" id="saveForm">
          <!-- carry selection -->
          <input type="hidden" name="course_id" value="<?= h($selected['course_id']) ?>">
          <input type="hidden" name="course_code" value="<?= h($selected['course_code']) ?>">
          <input type="hidden" name="module_code" value="<?= h($selected['module_code']) ?>">
          <input type="hidden" name="learning_mode" value="<?= h($selected['learning_mode']) ?>">

          <!-- Cohort code builder -->
          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label class="form-label">Cohort Suffix</label>
              <input type="text" id="cohort_suffix" class="form-control" placeholder="e.g., 2025A">
            </div>
            <div class="col-md-4">
              <label class="form-label">Cohort Code (ModuleCode + Suffix)</label>
              <input type="text" id="cohort_code_display" class="form-control" placeholder="auto-generated" readonly>
              <input type="hidden" name="cohort_code" id="cohort_code_hidden">
            </div>
          </div>

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
                                        <td>' . h($r['no']) . '<input type="hidden" name="rows[' . $i . '][no]" value="' . h($r['no']) . '"></td>
                                        <td>' . h($r['type']) . '<input type="hidden" name="rows[' . $i . '][type]" value="' . h($r['type']) . '"></td>
                                        <td>' . h($r['details']) . '<input type="hidden" name="rows[' . $i . '][details]" value="' . h($r['details']) . '"></td>
                                        <td>' . h($r['duration']) . '<input type="hidden" name="rows[' . $i . '][duration]" value="' . h($r['duration']) . '"></td>

                                        <td><input name="rows[' . $i . '][faculty]" class="form-control form-control-sm" value="' . h($r['faculty']) . '"></td>

                                        <td><input type="date" name="rows[' . $i . '][date]" class="form-control form-control-sm date-input" value="' . h($r['date']) . '" data-day-target="day-' . $i . '"></td>

                                        <td><input id="day-' . $i . '" name="rows[' . $i . '][day]" class="form-control form-control-sm" value="' . h($r['day']) . '" readonly></td>

                                        <td><input name="rows[' . $i . '][time]" class="form-control form-control-sm" value="' . h($r['time']) . '"></td>
                                    </tr>';
                  }
                } else {
                  for ($i = 0; $i < 5; $i++) echo '<tr><td colspan="8">&nbsp;</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div>

          <?php if (!empty($_SESSION['generated'])): ?>
            <div class="d-flex gap-2 mt-3">
              <button class="btn btn-success" name="saveToDb" type="submit">Save to Database</button>
              <button class="btn btn-danger" formaction="download_pdf.php" formmethod="post">Download PDF</button>
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

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script>
    // --- Course search & details ---
    const searchInput = document.getElementById('search');
    const resultsBox = document.getElementById('results');
    const clearBtn = document.getElementById('clearSearch');
    const courseTitle = document.getElementById('courseTitle');
    const moduleSelect = document.getElementById('moduleSelect');
    const modeSelect = document.getElementById('modeSelect');
    const modeDetails = document.getElementById('modeDetails');
    const courseIdInp = document.getElementById('course_id');
    const courseCodeInp = document.getElementById('course_code');
    const learningModeInp = document.getElementById('learning_mode');

    let lastCourseData = null;

    searchInput?.addEventListener('input', async () => {
      const q = searchInput.value.trim();
      clearBtn.style.display = q ? 'block' : 'none';
      if (!q) {
        resultsBox.innerHTML = '';
        return;
      }
      const res = await fetch('/schedule_gen/admin/course/search_courses.php?q=' + encodeURIComponent(q));
      const rows = await res.json();
      resultsBox.innerHTML = rows.map(r => `
        <button class="list-group-item list-group-item-action"
                data-id="${r.course_id}" data-code="${r.course_code}">
            [${r.course_code}] ${r.course_title_external}
        </button>
    `).join('');
    });
    clearBtn?.addEventListener('click', () => {
      searchInput.value = '';
      resultsBox.innerHTML = '';
      clearBtn.style.display = 'none';
      searchInput.focus();
    });

    resultsBox?.addEventListener('click', async e => {
      const btn = e.target.closest('button');
      if (!btn) return;
      resultsBox.innerHTML = '';
      searchInput.value = btn.textContent.trim();
      courseIdInp.value = btn.dataset.id;
      courseCodeInp.value = btn.dataset.code;

      const res = await fetch('/schedule_gen/admin/course/get_course_details.php?id=' + btn.dataset.id);
      const data = await res.json();
      lastCourseData = data;

      courseTitle.textContent = btn.textContent;
      moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
        (data.data.modules || []).map(m => `<option value="${m.module_code}">${m.module_title} [${m.module_code}]</option>`).join('');
      modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
        (data.data.master_learning_modes || []).map((m, i) => `<option value="${i}">${m.mode}</option>`).join('');
      learningModeInp.value = '';
      modeDetails.innerHTML = '';
    });
    modeSelect?.addEventListener('change', function() {
      if (!lastCourseData || this.value === "") {
        learningModeInp.value = "";
        modeDetails.innerHTML = "";
        return;
      }
      const m = lastCourseData.data.master_learning_modes[this.value];
      learningModeInp.value = m.mode || '';
      modeDetails.innerHTML = `<div class="card card-body p-2">
        <div><b>Mode:</b> ${m.mode || ''} |
        <b>Course Duration:</b> ${m.course_duration || ''} |
        <b>Days/Week:</b> ${m.days_per_week || ''} |
        <b>Hours/Day:</b> ${m.hours_per_day || ''} |
        <b>Hours/Week:</b> ${m.hours_per_week || ''}</div>
    </div>`;
    });

    // Auto Day when Date changes
    document.querySelectorAll('.date-input').forEach(input => {
      input.addEventListener('change', function() {
        const dateStr = this.value;
        const dayTargetId = this.dataset.dayTarget;
        if (!dateStr || !dayTargetId) return;
        const d = new Date(dateStr);
        if (isNaN(d)) return;
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        document.getElementById(dayTargetId).value = days[d.getDay()];
      });
    });

    // Disable slot if custom times filled
    $(function() {
      function refreshTimeHint() {
        const hasCustom = $('input[name="custom_start"]').val() && $('input[name="custom_end"]').val();
        $('select[name="time_slot"]').prop('disabled', !!hasCustom);
      }
      $('input[name="custom_start"], input[name="custom_end"]').on('input', refreshTimeHint);
      refreshTimeHint();
    });

    // Cohort code builder
    const moduleSelectEl = document.getElementById('moduleSelect');
    const cohortSuffixInput = document.getElementById('cohort_suffix');
    const cohortCodeDisplay = document.getElementById('cohort_code_display');
    const cohortCodeHidden = document.getElementById('cohort_code_hidden');

    function updateCohortCode() {
      const mcode = (moduleSelectEl.value || '').trim();
      const suff = (cohortSuffixInput.value || '').trim();
      const code = (mcode && suff) ? (mcode + '-' + suff) : (mcode || '');
      cohortCodeDisplay.value = code;
      cohortCodeHidden.value = code;
    }
    moduleSelectEl?.addEventListener('change', updateCohortCode);
    cohortSuffixInput?.addEventListener('input', updateCohortCode);
  </script>
</body>

</html>