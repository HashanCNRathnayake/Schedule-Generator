<?php
// session_start();
date_default_timezone_set('Asia/Singapore'); // adjust if needed

require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/db.php';
require __DIR__ . '/auth/guard.php';
$me = $_SESSION['auth'] ?? null;
requireRole($conn, 'Admin'); // must be logged in + have Admin

require __DIR__ . '/components/header.php';
require __DIR__ . '/components/navbar.php';
require __DIR__ . '/admin/course/schedule_lib.php';

$userId   = $_SESSION['auth']['user_id'] ?? 0;

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- STATE (selected, grid, generated) --------------------------------------
$selected = [
  'course_id'     => trim($_POST['course_id'] ?? ($_SESSION['selected']['course_id'] ?? '')),
  'course_code'   => trim($_POST['course_code'] ?? ($_SESSION['selected']['course_code'] ?? '')),
  'module_code'   => '', // not used in new flow (we handle multiple)
  'learning_mode' => trim($_POST['learning_mode'] ?? ($_SESSION['selected']['learning_mode'] ?? '')),
  'course_title'  => trim($_POST['course_title'] ?? ($_SESSION['selected']['course_title'] ?? '')),
  'cohort_code'   => trim($_POST['cohort_code'] ?? ($_SESSION['selected']['cohort_code'] ?? '')),
  'module_title'  => '', // not used in new flow
];

$_SESSION['selected'] = $selected;

// grid is now: [ 'MODCODE' => [ [no,type,details,duration], ... ], ... ]
$grid = $_SESSION['grid_rows'] ?? [];             // templates per module
$generated = $_SESSION['generated'] ?? [];        // generated rows per module
$moduleTitles = $_SESSION['module_titles'] ?? []; // ['MODCODE' => 'Module Title']

// ---- ACTIONS ----------------------------------------------------------------

// CLEAR
if (isPost('clearCsv')) {
  unset($_SESSION['grid_rows'], $_SESSION['generated'], $_SESSION['selected'], $_SESSION['meta'], $_SESSION['module_titles']);
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}

// LOAD ALL TEMPLATES (for ordered modules list)
// if (isPost('loadAllTemplates')) {
//   $modulesOrdered = (array)($_POST['modules'] ?? []);          // ordered list of module codes
//   $learningMode   = trim($_POST['learning_mode'] ?? '');
//   $courseId       = trim($_POST['course_id'] ?? '');
//   $courseTitle    = trim($_POST['course_title'] ?? '');

//   if (!$courseId || !$learningMode || empty($modulesOrdered)) {
//     $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please select a course, a learning mode, and order the modules.'];
//     header('Location: ' . $_SERVER['PHP_SELF']);
//     exit;
//   }

//   $grid = [];
//   $moduleTitles = [];

//   // we’ll map module code -> title (for headers)
//   // if you don’t have a modules table, you can skip this fetch and rely on titles coming from JS.
//   $stmtTitles = $conn->prepare("SELECT module_code, module_title FROM modules WHERE course_id=?");
//   $stmtTitles->bind_param("s", $courseId);
//   $stmtTitles->execute();
//   $stmtTitles->bind_result($mc, $mt);
//   $allTitleMap = [];
//   while ($stmtTitles->fetch()) {
//     $allTitleMap[$mc] = $mt;
//   }
//   $stmtTitles->close();

//   foreach ($modulesOrdered as $modCode) {
//     $modCode = trim($modCode);
//     if ($modCode === '') continue;

//     // resolve title
//     $moduleTitles[$modCode] = $allTitleMap[$modCode] ?? $modCode;

//     // latest template id
//     $templateId = null;
//     $stmt = $conn->prepare("
//       SELECT id FROM session_templates
//       WHERE course_id=? AND module_code=? AND learning_mode=?
//       ORDER BY created_at DESC
//       LIMIT 1
//     ");
//     $stmt->bind_param("sss", $courseId, $modCode, $learningMode);
//     $stmt->execute();
//     $stmt->bind_result($templateId);
//     if ($stmt->fetch()) { /* got template */
//     }
//     $stmt->close();

//     $rows = [];
//     if ($templateId) {
//       $stmt2 = $conn->prepare("
//         SELECT session_no, session_type, session_details, duration_hr
//         FROM session_template_rows
//         WHERE template_id=?
//         ORDER BY CAST(session_no AS UNSIGNED), session_no
//       ");
//       $stmt2->bind_param("i", $templateId);
//       $stmt2->execute();
//       $stmt2->bind_result($no, $type, $details, $dur);
//       while ($stmt2->fetch()) {
//         $rows[] = [$no, $type, $details, $dur];
//       }
//       $stmt2->close();
//     } else {
//       // No template → create a few empty rows
//       $rows = [
//         [1, '', '', ''],
//         [2, '', '', ''],
//         [3, '', '', ''],
//       ];
//     }
//     $grid[$modCode] = $rows;
//   }

//   $_SESSION['grid_rows'] = $grid;
//   $_SESSION['module_titles'] = $moduleTitles;
//   $_SESSION['selected']['course_id'] = $courseId;
//   $_SESSION['selected']['learning_mode'] = $learningMode;
//   $_SESSION['selected']['course_title'] = $courseTitle;

//   $_SESSION['flash'] = ['type' => 'success', 'message' => 'Loaded templates for all modules in the chosen order.'];
//   header('Location: ' . $_SERVER['PHP_SELF']);
//   exit;
// }

/* Load ALL templates (ordered modules list) */
if (isPost('loadAllTemplates')) {
  $courseId     = trim($_POST['course_id'] ?? '');
  $learningMode = trim($_POST['learning_mode'] ?? '');
  $modules      = (array)($_POST['modules'] ?? []); // ordered codes
  $titles       = (array)($_POST['module_titles'] ?? []); // code => title

  $grid = [];
  $moduleTitles = [];

  foreach ($modules as $modCode) {
    $modCode = trim($modCode);
    if ($modCode === '') continue;

    $moduleTitles[$modCode] = $titles[$modCode] ?? $modCode;

    // Pick the latest template by created_at
    $stmt = $conn->prepare("
            SELECT id FROM session_templates
            WHERE course_id=? AND module_code=? AND learning_mode=?
            ORDER BY created_at DESC LIMIT 1
        ");
    $stmt->bind_param("sss", $courseId, $modCode, $learningMode);
    $stmt->execute();
    $stmt->bind_result($templateId);
    $templateId = null;
    if ($stmt->fetch()) {
    }
    $stmt->close();

    $rows = [];
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
        $rows[] = [$no, $type, $details, $dur];
      }
      $stmt2->close();
    } else {
      // no template → blank rows
      $rows = [
        [1, '', '', ''],
        [2, '', '', ''],
        [3, '', '', ''],
      ];
    }
    $grid[$modCode] = $rows;
  }

  $_SESSION['grid_rows']     = $grid;
  $_SESSION['module_titles'] = $moduleTitles;
  $_SESSION['selected']['course_id']     = $courseId;
  $_SESSION['selected']['learning_mode'] = $learningMode;

  $_SESSION['flash'] = [
    'type' => 'success',
    'message' => 'Loaded templates for all modules.'
  ];
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}


// GENERATE (for all modules in $grid, using current logic)
if ($grid && isPost('generateSchedule')) {
  $startDate    = trim($_POST['start_date'] ?? '');
  $daysRaw      = (array)($_POST['days'] ?? []);
  $countriesRaw = (array)($_POST['countries'] ?? []);
  $customStart  = trim($_POST['custom_start'] ?? '');
  $customEnd    = trim($_POST['custom_end'] ?? '');
  $cohortCode   = trim($_POST['cohort_code'] ?? '');

  // normalize days (Mon..Sun)
  $days = [];
  foreach ($daysRaw as $d) {
    $k = substr(trim($d), 0, 3);
    if ($k && !in_array($k, $days, true)) $days[] = $k;
  }

  // normalize countries (uppercase)
  $countries = [];
  foreach ($countriesRaw as $c) {
    $k = strtoupper(trim($c));
    if ($k && !in_array($k, $countries, true)) $countries[] = $k;
  }

  if (!$startDate || empty($days) || empty($countries)) {
    $error = "Please provide Start Date, at least one Day, and at least one Country.";
  } else {
    try {
      $generated = [];

      // IMPORTANT: we call your existing generate_schedule() per module.
      // If you want dates to CONTINUE across modules, you can thread "last date" into the next call.
      // For now, we preserve the current per-grid behavior (module-by-module).
      foreach ($grid as $moduleCode => $rows) {
        $dates = generate_schedule($rows, $startDate, $days, $countries);

        foreach ($rows as $i => $row) {
          [$no, $type, $details, $duration] = $row;
          $typeNorm  = normalize_session_type($type);
          $faculty   = faculty_from_type($typeNorm);
          $dateCell  = isset($dates[$i]) ? ymd($dates[$i]) : '';
          $dayCell   = isset($dates[$i]) ? weekday_name($dates[$i]) : '';

          if ($typeNorm === 'MS-ASync') {
            $time = 'Self-Paced Before Sync Session';
          } else {
            $time = ($customStart && $customEnd) ? ($customStart . ' - ' . $customEnd) : '19:00 - 22:00';
          }

          $generated[$moduleCode][] = [
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
      }

      // persist for PDF/Save
      $_SESSION['generated']               = $generated;
      $_SESSION['selected']['cohort_code'] = $cohortCode;
      $_SESSION['meta'] = [
        'start_date'   => $startDate,
        'days'         => $days,
        'countries'    => $countries,
        'custom_start' => $customStart,
        'custom_end'   => $customEnd,
      ];

      $_SESSION['flash'] = ['type' => 'success', 'message' => "Generated Session Plan for all modules."];
    } catch (Throwable $e) {
      $error = 'Failed to generate schedule: ' . $e->getMessage();
    }
  }
}
?>

<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Generate Session Plan (Multi-Module)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Flatpickr CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    table thead th {
      position: sticky;
      text-align: center;
      vertical-align: middle;
      top: 0;
      background: #0e1627;
      color: #fff;
      z-index: 2;
    }

    table tbody tr {
      height: 58px;
      width: auto;
    }

    .short_col {
      width: 100px !important;
    }

    .short_col2 {
      width: 150px !important;
    }

    .details_col {
      width: 450px;
      white-space: normal;
      word-break: break-word;
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

    .drag-btn {
      min-width: 2.25rem;
    }
  </style>
</head>

<body class="py-4">
  <div class="container m-0 px-0 w-100" style="max-width:1436px;">
    <?php if ($flash): ?>
      <div class="d-flex flex-inline justify-content-between alert alert-<?= h($flash['type'] ?? 'info') ?> mt-2">
        <?= h($flash['message'] ?? '') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <h4 class="mb-3">Generate Schedule (All Modules)</h4>

    <!-- (1) Course Search + Mode + Modules Order + Load -->
    <form method="post" class="mb-4">
      <div class="row g-2 justify-content-start align-items-start">
        <div class="col-6 position-relative">
          <label class="form-label">Search Courses</label>
          <div class="input-group">
            <input type="text" id="search" class="form-control" placeholder="<?= $selected['course_title'] ? h($selected['course_title']) : 'Search ...' ?>">
            <button type="button" id="clearSearch" class="btn btn-outline-secondary">&times;</button>
          </div>
          <div id="results" class="list-group"></div>
          <div id="courseTitle" class="fw-semibold ms-2">
            <?php if ($selected['course_title']): ?>
              <?= h($selected['course_title']) ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- hidden selection carries -->
        <input type="hidden" name="course_id" id="course_id" value="<?= h($selected['course_id']) ?>">
        <input type="hidden" name="course_code" id="course_code" value="<?= h($selected['course_code']) ?>">
        <input type="hidden" name="course_title" id="course_title" value="<?= h($selected['course_title']) ?>">

        <div class="col-md-3">
          <label class="form-label">Learning Mode</label>
          <select id="modeSelect" class="form-select" name="learning_mode" required>
            <?php if ($selected['learning_mode']): ?>
              <option value="<?= h($selected['learning_mode']) ?>" selected><?= h($selected['learning_mode']) ?></option>
            <?php else: ?>
              <option value="">Select a mode...</option>
            <?php endif; ?>
          </select>
          <div id="modeDetails" class="small"></div>
        </div>

        <div class="col-12">
          <label class="form-label mb-1">Modules (arrange in desired order)</label>
          <ul id="modulesList" class="list-group w-100">
            <!-- JS will populate li items (module title + code + hidden input name="modules[]") -->
          </ul>
          <div class="form-text">Use ↑ / ↓ to order modules. The list order decides loading & generation order.</div>
        </div>

        <div class="col-12 d-flex justify-content-end mt-2">
          <button class="btn btn-primary" name="loadAllTemplates" value="1" type="submit">Load</button>
        </div>
      </div>
    </form>

    <!-- (2) Inputs + Generate (unchanged logic) -->
    <div class="card mb-4">
      <div class="card-body">
        <form method="post" class="row g-3">
          <!-- carries -->
          <input type="hidden" name="course_id" value="<?= h($selected['course_id']) ?>">
          <input type="hidden" name="course_code" value="<?= h($selected['course_code']) ?>">
          <input type="hidden" name="learning_mode" value="<?= h($selected['learning_mode']) ?>">
          <input type="hidden" name="course_title" value="<?= h($selected['course_title']) ?>">

          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label for="start_date" class="form-label">Start Date</label>
              <input type="text" id="start_date" name="start_date" class="form-control" placeholder="dd/mm/yyyy" required>
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

            <div class="col-md-4">
              <label class="form-label">Start Time & End Time (Sync rows)</label>
              <div class="input-group">
                <input type="time" class="form-control" name="custom_start">
                <span class="input-group-text">to</span>
                <input type="time" class="form-control" name="custom_end">
              </div>
              <div class="small-note">Set both Start and End Time for sync rows; async rows will show a note.</div>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Countries (Public Holidays)</label>
            <div class="dropdown w-100">
              <button class="btn btn-outline-primary w-100 d-flex justify-content-between align-items-center"
                type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="countriesDropdown" aria-expanded="false">
                <span id="countriesSummary">Select countries…</span>
                <span class="badge text-bg-secondary" id="countriesCount">0</span>
              </button>

              <div class="dropdown-menu p-3 w-100 shadow">
                <input type="text" class="form-control form-control-sm mb-2" id="countrySearch" placeholder="Search country…">
                <div id="countriesList" class="d-grid gap-2" style="max-height:220px; overflow:auto;">
                  <?php
                  $countriesPreset = [
                    'SG' => 'Singapore',
                    'IN' => 'India',
                    'LK' => 'Sri Lanka',
                    'BD' => 'Bangladesh',
                    'MM' => 'Myanmar',
                    'PH' => 'Philippines',
                    'MY' => 'Malaysia'
                  ];
                  foreach ($countriesPreset as $code => $label): ?>
                    <label class="form-check">
                      <input class="form-check-input country-check" type="checkbox" value="<?= $code ?>" data-label="<?= $label ?>">
                      <span class="form-check-label"><?= $label ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="d-flex gap-2 mt-2">
                  <button type="button" class="btn btn-sm btn-light" id="selectAllCountries">Select all</button>
                  <button type="button" class="btn btn-sm btn-light" id="clearCountries">Clear</button>
                </div>
              </div>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-2" id="selectedCountryBadges"></div>
            <div id="countriesHidden"></div>
            <div class="small-note mt-1">We’ll skip any holiday that appears in <em>any</em> selected country.</div>
          </div>

          <!-- Cohort code (optional) -->
          <div class="row g-3">
            <div class="col-md-2">
              <label class="form-label">Cohort Suffix</label>
              <input type="text" id="cohort_suffix" class="form-control" placeholder="MMYY (0825)">
            </div>
            <div class="col-md-4">
              <label class="form-label">Cohort Code</label>
              <input type="text" id="cohort_code_display" class="form-control" placeholder="auto-generated" readonly>
              <input type="hidden" name="cohort_code" id="cohort_code_hidden" value="<?= h($selected['cohort_code']) ?>">
            </div>
          </div>

          <div class="col-12 d-flex justify-content-end align-items-end">
            <button class="btn btn-primary" name="generateSchedule" type="submit">Generate</button>
            <?php if (isset($error)): ?><span class="text-danger ms-3"><?= h($error) ?></span><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- (3) Accordion with per-module tables (grid or generated) -->
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="card-title mb-0">Session Plan (All Modules)</h5>
          <form method="post">
            <button type="submit" name="clearCsv" class="btn btn-secondary">Clear Generated</button>
          </form>
        </div>

        <?php
        $showAll = !empty($generated) ? $generated : $grid; // if generated exists, show it; else show raw grid
        if (!empty($showAll)):
        ?>
          <div class="alert alert-light border d-flex flex-wrap gap-3 align-items-center mb-3">
            <div><strong>Course:</strong> <?= h($selected['course_title'] ?? '-') ?></div>
            <div><strong>Code:</strong> <?= h($selected['course_code'] ?? '-') ?></div>
            <div><strong>Mode:</strong> <?= h($selected['learning_mode'] ?? '-') ?></div>
            <div><strong>Cohort:</strong> <?= h($_SESSION['selected']['cohort_code'] ?? '-') ?></div>
          </div>

          <div class="accordion" id="modulesAccordion">
            <?php
            $accIndex = 0;
            foreach ($showAll as $modCode => $rows):
              $accIndex++;
              $modId = 'mod-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $modCode) . '-' . $accIndex;
              $title = $moduleTitles[$modCode] ?? $modCode;
            ?>
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading-<?= h($modId) ?>">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                    data-bs-target="#collapse-<?= h($modId) ?>" aria-expanded="false"
                    aria-controls="collapse-<?= h($modId) ?>">
                    <?= h($title) ?> [<?= h($modCode) ?>]
                  </button>
                </h2>
                <div id="collapse-<?= h($modId) ?>" class="accordion-collapse collapse" aria-labelledby="heading-<?= h($modId) ?>" data-bs-parent="#modulesAccordion">
                  <div class="accordion-body">
                    <div class="table-responsive">
                      <table class="table table-sm table-bordered align-middle">
                        <thead>
                          <tr>
                            <th>Session<br>No</th>
                            <th class="short_col2">Session Type-Mode</th>
                            <th class="details_col">Session Details</th>
                            <th>Duration<br>Hr</th>
                            <th class="short_col">Faculty Name</th>
                            <th>Date</th>
                            <th class="short_col">Day</th>
                            <th>Time</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php
                          if (!empty($generated)) {
                            // show generated rows with inputs (still editable)
                            foreach ($rows as $i => $r) {
                              echo '<tr>
                        <td>' . h($r['no']) . '</td>
                        <td>' . h($r['type']) . '</td>
                        <td class="details_col">' . h($r['details']) . '</td>
                        <td>' . h($r['duration']) . '</td>
                        <td><input class="form-control form-control-sm" value="' . h($r['faculty']) . '" readonly></td>
                        <td><input class="form-control form-control-sm" value="' . h($r['date']) . '" readonly></td>
                        <td><input class="form-control form-control-sm" value="' . h($r['day']) . '" readonly></td>
                        <td><input class="form-control form-control-sm" value="' . h($r['time']) . '" readonly></td>
                      </tr>';
                            }
                          } else {
                            // show grid rows (no dates yet)
                            foreach ($rows as $r) {
                              [$no, $type, $details, $dur] = $r;
                              echo '<tr>
                        <td>' . h($no) . '</td>
                        <td>' . h(normalize_session_type($type)) . '</td>
                        <td class="details_col">' . h($details) . '</td>
                        <td>' . h($dur) . '</td>
                        <td>' . h(faculty_from_type($type)) . '</td>
                        <td></td><td></td><td></td>
                      </tr>';
                            }
                          }
                          ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-muted">Load a course, arrange modules, then click <strong>Load</strong> to see module tables here.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Flatpickr JS -->
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <script>
    // Auto-dismiss flash
    setTimeout(() => {
      document.querySelectorAll('.alert').forEach(alert => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
      });
    }, 3000);

    // ----- Helpers -----
    function debounce(fn, ms = 250) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
      };
    }

    function setAll(name, value) {
      document.querySelectorAll(`input[name="${name}"]`).forEach(el => el.value = value ?? '');
    }

    // ----- Course search -----
    const searchInput = document.getElementById('search');
    const resultsBox = document.getElementById('results');
    const clearBtn = document.getElementById('clearSearch');
    const courseTitle = document.getElementById('courseTitle');
    const modeSelect = document.getElementById('modeSelect');
    const modeDetails = document.getElementById('modeDetails');
    const modulesList = document.getElementById('modulesList');

    let lastCourseData = null;

    searchInput?.addEventListener('input', debounce(async () => {
      const q = searchInput.value.trim();
      if (!q) {
        resultsBox.innerHTML = '';
        return;
      }
      const res = await fetch('/schedule_gen/admin/course/search_courses.php?q=' + encodeURIComponent(q));
      const rows = await res.json();
      resultsBox.innerHTML = rows.map(r => `
      <button class="list-group-item list-group-item-action" data-id="${r.course_id}" data-code="${r.course_code}">
        [${r.course_code}] ${r.course_title_external}
      </button>
    `).join('');
    }, 250));

    clearBtn?.addEventListener('click', () => {
      searchInput.value = '';
      resultsBox.innerHTML = '';
      searchInput.focus();
    });

    resultsBox?.addEventListener('click', async e => {
      const btn = e.target.closest('button');
      if (!btn) return;
      resultsBox.innerHTML = '';
      searchInput.value = btn.textContent.trim();
      setAll('course_id', btn.dataset.id);
      setAll('course_code', btn.dataset.code);

      const res = await fetch('/schedule_gen/admin/course/get_course_details.php?id=' + btn.dataset.id);
      const data = await res.json();
      lastCourseData = data;

      courseTitle.textContent = btn.textContent;
      setAll('course_title', courseTitle.textContent.trim());

      // Fill modes
      modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
        (data.data.master_learning_modes || []).map((m, i) => `<option value="${m.mode}">${m.mode}</option>`).join('');
      setAll('learning_mode', '');

      modeDetails.innerHTML = '';
      // Render module ORDER list
      const mods = data.data.modules || [];
      if (!mods.length) {
        modulesList.innerHTML = '<li class="list-group-item text-muted">No modules found for this course.</li>';
        return;
      }
      modulesList.innerHTML = mods.map(m => `
      <li class="list-group-item d-flex align-items-center justify-content-between" data-code="${m.module_code}" data-title="${m.module_title}">
        <div class="d-flex align-items-center justify-content-start me-2">
          <div class="">[${m.module_code}]&nbsp;</div>
          <div class="">${m.module_title}</div>
          <input type="hidden" name="modules[]" value="${m.module_code}">
        </div>
        <div class="btn-group">
          <button type="button" class="btn btn-outline-secondary btn-sm drag-btn move-up" title="Move up">↑</button>
          <button type="button" class="btn btn-outline-secondary btn-sm drag-btn move-down" title="Move down">↓</button>
        </div>
      </li>
    `).join('');
    });

    modeSelect?.addEventListener('change', function() {
      if (!lastCourseData || this.value === "") {
        setAll('learning_mode', '');
        modeDetails.innerHTML = "";
        return;
      }
      const m = (lastCourseData.data.master_learning_modes || []).find(x => x.mode === this.value);
      setAll('learning_mode', m?.mode || '');
      if (m) {
        modeDetails.innerHTML = `<div class="card card-body p-2">
        <div><b>Mode:</b> ${m.mode} |
          <b>Duration:</b> ${m.course_duration ?? ''} |
          <b>Days/Week:</b> ${m.days_per_week ?? ''} |
          <b>Hours/Day:</b> ${m.hours_per_day ?? ''} |
          <b>Hours/Week:</b> ${m.hours_per_week ?? ''}
        </div>
      </div>`;
      } else {
        modeDetails.innerHTML = '';
      }
    });

    // Modules order buttons
    modulesList?.addEventListener('click', e => {
      const li = e.target.closest('li');
      if (!li) return;
      if (e.target.classList.contains('move-up')) {
        const prev = li.previousElementSibling;
        if (prev) li.parentNode.insertBefore(li, prev);
      } else if (e.target.classList.contains('move-down')) {
        const next = li.nextElementSibling;
        if (next) li.parentNode.insertBefore(next, li);
      }
      // re-sync hidden inputs order
      Array.from(modulesList.querySelectorAll('li')).forEach((node, idx) => {
        const hidden = node.querySelector('input[name="modules[]"]');
        if (hidden) hidden.value = node.dataset.code;
      });
      // auto-update cohort code using first module code, if any
      updateCohortCode();
    });

    // Countries UX
    (function() {
      const preselectedCountries = <?= json_encode($_POST['countries'] ?? ($_SESSION['meta']['countries'] ?? ['SG'])) ?>;
      const checks = Array.from(document.querySelectorAll('.country-check'));
      const badgesBox = document.getElementById('selectedCountryBadges');
      const hiddenBox = document.getElementById('countriesHidden');
      const summaryEl = document.getElementById('countriesSummary');
      const countEl = document.getElementById('countriesCount');
      const searchEl = document.getElementById('countrySearch');

      checks.forEach(c => c.checked = preselectedCountries.includes(c.value));

      function getSelected() {
        return checks.filter(c => c.checked).map(c => ({
          code: c.value,
          label: c.dataset.label
        }));
      }

      function renderSelected() {
        const selected = getSelected();
        badgesBox.innerHTML = selected.map(s => `
        <span class="badge text-bg-primary d-inline-flex align-items-center">
          ${s.label}
          <button type="button" class="btn-close btn-close-white btn-sm ms-2 remove-country" data-code="${s.code}"></button>
        </span>
      `).join('');
        hiddenBox.innerHTML = selected.map(s => `<input type="hidden" name="countries[]" value="${s.code}">`).join('');
        if (selected.length === 0) summaryEl.textContent = 'Select countries…';
        else if (selected.length === 1) summaryEl.textContent = selected[0].label;
        else summaryEl.textContent = `${selected[0].label} + ${selected.length - 1} more`;
        countEl.textContent = String(selected.length);
      }
      renderSelected();

      checks.forEach(c => c.addEventListener('change', renderSelected));
      badgesBox.addEventListener('click', e => {
        const btn = e.target.closest('.remove-country');
        if (!btn) return;
        const code = btn.dataset.code;
        const target = checks.find(c => c.value === code);
        if (target) {
          target.checked = false;
          renderSelected();
        }
      });
      searchEl?.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('#countriesList .form-check').forEach(label => {
          const text = label.textContent.toLowerCase();
          label.style.display = text.includes(q) ? '' : 'none';
        });
      });
      document.getElementById('selectAllCountries')?.addEventListener('click', () => {
        checks.forEach(c => c.checked = true);
        renderSelected();
      });
      document.getElementById('clearCountries')?.addEventListener('click', () => {
        checks.forEach(c => c.checked = false);
        renderSelected();
      });
    })();

    // Flatpickr
    document.addEventListener('DOMContentLoaded', () => {
      flatpickr("#start_date", {
        altInput: true,
        altInputClass: 'form-control',
        altFormat: "d/m/Y",
        dateFormat: "Y-m-d",
        allowInput: true
      });
    });

    // Cohort code builder (uses FIRST module in the ordered list)
    const cohortSuffixInput = document.getElementById('cohort_suffix');
    const cohortCodeDisplay = document.getElementById('cohort_code_display');
    const cohortCodeHidden = document.getElementById('cohort_code_hidden');

    function firstModuleCode() {
      const firstLi = document.querySelector('#modulesList li');
      return firstLi?.dataset.code || '';
    }

    function updateCohortCode() {
      const mcode = (firstModuleCode() || 'mcode').trim();
      const suff = (cohortSuffixInput?.value || '').trim();
      const code = (mcode && suff) ? (mcode + '-' + suff) : '';
      if (cohortCodeDisplay) cohortCodeDisplay.value = code || '';
      if (cohortCodeHidden) cohortCodeHidden.value = code || '';
    }
    cohortSuffixInput?.addEventListener('input', updateCohortCode);
  </script>
</body>

</html>