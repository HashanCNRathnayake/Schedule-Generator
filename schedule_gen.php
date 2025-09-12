<?php
session_start();
date_default_timezone_set('Asia/Singapore'); // adjust if needed

// auth
if (!isset($_SESSION['user_id'])) {
  header("Location: ./login.php");
  exit;
}

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

// flash
$flash = $_SESSION['flash'] ?? null;
if ($flash && isset($flash['expires_at']) && $flash['expires_at'] <= time()) {
  // expired -> remove
  unset($_SESSION['flash']);
  $flash = null;
}


// state
$selected = [
  'course_id'     => trim($_POST['course_id'] ?? ''),
  'course_code'   => trim($_POST['course_code'] ?? ''),
  'module_code'   => trim($_POST['module_code'] ?? ''),
  'learning_mode' => trim($_POST['learning_mode'] ?? ''),
  'course_title'  => trim($_POST['course_title'] ?? ''),
  'cohort_code'   => trim($_POST['cohort_code'] ?? ''),
  'module_title'  => trim($_POST['module_title'] ?? ''),
];

//save module_code etc to session for later use
$_SESSION['selected'] = $selected;

$grid = $_SESSION['grid_rows'] ?? [];       // plain template rows [no,type,details,duration]
$generated = $_SESSION['generated'] ?? [];  // enriched table rows

/* Load template rows when "Load Template" clicked OR prefilled via GET */
if (isPost('loadTemplate') || ($selected['course_id'] && $selected['module_code'] && $selected['learning_mode'])) {
  if ($conn && $selected['course_id'] && $selected['module_code'] && $selected['learning_mode']) {

    // Pick the latest template by created_at for this user + keys
    $stmt = $conn->prepare("
            SELECT id FROM session_templates
            WHERE course_id=? AND module_code=? AND learning_mode=?
            ORDER BY created_at DESC
            LIMIT 1
        ");
    $stmt->bind_param(
      "sss",
      $selected['course_id'],
      $selected['module_code'],
      $selected['learning_mode']
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
      $_SESSION['flash'] = [
        'type' => 'success',
        'message' => "Loaded template #$templateId (rows: " . count($grid) . ").",
        'expires_at' => time() + 30
      ];
      $_SESSION['selected'] = $selected;
      // header('Location: ' . $_SERVER['PHP_SELF']); // bare
      // exit;
    } else {
      $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'No template found. Please create it on the Master page.',
        'expires_at' => time() + 30
      ];
      // header('Location: ' . $_SERVER['PHP_SELF']);
      // exit;
    }
  }
}

/* Generate schedule using multi-country holidays */
if ($grid && isPost('generateSchedule')) {
  // --- gather & sanitize inputs
  $startDate   = trim($_POST['start_date'] ?? '');
  $daysRaw     = (array)($_POST['days'] ?? []);
  $countriesRaw = (array)($_POST['countries'] ?? []); // MULTI
  $customStart = trim($_POST['custom_start'] ?? '');
  $customEnd   = trim($_POST['custom_end'] ?? '');
  $cohortCode  = trim($_POST['cohort_code'] ?? '');

  // normalize days to 3-letter names (Mon..Sun), unique, keep order
  $days = [];
  foreach ($daysRaw as $d) {
    $k = substr(trim($d), 0, 3);
    if ($k && !in_array($k, $days, true)) $days[] = $k;
  }

  // normalize countries (uppercase codes), unique
  $countries = [];
  foreach ($countriesRaw as $c) {
    $k = strtoupper(trim($c));
    if ($k && !in_array($k, $countries, true)) $countries[] = $k;
  }

  // validate
  if (!$startDate || empty($days) || empty($countries)) {
    $error = "Please provide Start Date, at least one Day, and at least one Country.";
  } else {
    try {
      // ask schedule_lib.php to compute the dates while skipping the union of holidays
      $dates = generate_schedule($grid, $startDate, $days, $countries);

      // build enriched rows
      $generated = [];
      foreach ($grid as $i => $row) {
        [$no, $type, $details, $duration] = $row;
        $typeNorm  = normalize_session_type($type);
        $faculty   = faculty_from_type($typeNorm);
        $dateCell  = isset($dates[$i]) ? ymd($dates[$i]) : '';
        $dayCell   = isset($dates[$i]) ? weekday_name($dates[$i]) : '';

        // time: custom if both set, else dropdown, else default
        if ($typeNorm === 'MS-ASync') {
          $time = 'Self-Paced Before Sync Session';
        } else {
          $time = ($customStart && $customEnd) ? ($customStart . ' - ' . $customEnd)
            : ($slot ?: '19:00 - 22:00');
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

      // persist for PDF/Save
      $_SESSION['generated']                 = $generated;
      $_SESSION['selected']['cohort_code']   = $cohortCode;
      $_SESSION['meta'] = [
        'start_date'   => $startDate,
        'days'         => $days,
        'countries'    => $countries,
        'custom_start' => $customStart,
        'custom_end'   => $customEnd,
      ];
    } catch (Throwable $e) {
      $error = 'Failed to generate schedule: ' . $e->getMessage();
    }
  }
}


if (isPost('clearCsv')) {
  unset($_SESSION['grid_rows'], $_SESSION['generated'], $_SESSION['selected'], $_SESSION['meta']);
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}
?>

<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Generate Session Plan</title>
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
  </style>
</head>

<body class="py-4">
  <div class="container m-0 px-0 w-100" style="max-width:1436px;">
    <!-- <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mt-2"><?= h($flash['message'] ?? '') ?></div>

    <?php endif; ?> -->

    <?php if ($flash): ?>
      <div class="d-flex flex-inline justify-content-between alert alert-<?= htmlspecialchars($flash['type']) ?> js-flash"
        role="alert"
        data-expire="<?= (int)$flash['expires_at'] * 1000 ?>">
        <div><?= htmlspecialchars($flash['message']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <!-- // echo all session Contents like selected -->
    <!-- <?php print_r($_SESSION['selected'] ?? []); ?> -->

    <h4 class="mb-3">Generate Schedule</h4>

    <!-- 1) Pick Course / Module / Mode (loads template rows) -->
    <form method="post" class="mb-4">
      <div class="row g-1">
        <div class="col-5 position-relative">
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

        <!-- hidden fields to carry selection -->
        <input type="hidden" name="course_id" id="course_id" value="<?= h($selected['course_id']) ?>">
        <input type="hidden" name="course_code" id="course_code" value="<?= h($selected['course_code']) ?>">
        <input type="hidden" name="course_title" id="course_title" value="">
        <input type="hidden" name="module_title" value="<?= h($selected['module_title'] ?? '') ?>">

        <div class="col-md-4">
          <label class="form-label">Learning Modules</label>
          <select id="moduleSelect" name="module_code" class="form-select" required>
            <?php if ($selected['module_code']): ?>
              <option value="<?= h($selected['module_code']) ?>" selected><?= h($selected['module_code']) ?></option>
            <?php else: ?>
              <option value="">Select a module...</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="col-md-2">
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



        <!-- <div class="col-md-6">
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
        </div> -->

        <div class="col-1">
          <button class="btn btn-primary" name="loadTemplate" type="submit">Load Template</button>
          <!-- <a class="btn btn-outline-secondary" href="/admin/course/master_temp.php">Create/Edit Templates</a> -->
        </div>
      </div>
    </form>

    <!-- 2) Inputs + Generate -->
    <div class="card mb-4">
      <div class="card-body">
        <!-- <h5 class="card-title">Step 2 — Inputs</h5> -->
        <form method="post" class="row g-3">
          <!-- carry selection -->
          <input type="hidden" name="course_id" value="<?= h($selected['course_id']) ?>">
          <input type="hidden" name="course_code" value="<?= h($selected['course_code']) ?>">
          <input type="hidden" name="module_code" value="<?= h($selected['module_code']) ?>">
          <input type="hidden" name="learning_mode" value="<?= h($selected['learning_mode']) ?>">
          <input type="hidden" name="course_title" value="<?= h($selected['course_title']) ?>">
          <input type="hidden" name="module_title" value="<?= h($selected['module_title'] ?? '') ?>">



          <!-- Cohort code builder -->
          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label class="form-label">Cohort Suffix</label>
              <input type="text" id="cohort_suffix" class="form-control" placeholder="MMYY (0825)">
            </div>
            <div class="col-md-4">
              <label class="form-label">Cohort Code (ModuleCode + Suffix)</label>
              <input type="text" id="cohort_code_display" class="form-control" placeholder="auto-generated" readonly>
              <input type="hidden" name="cohort_code" id="cohort_code_hidden">
            </div>
          </div>

          <div class="col-md-3">
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

          <div class="col-md-5">
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
                  <label class="form-check">
                    <input class="form-check-input country-check" type="checkbox" value="SG" data-label="Singapore">
                    <span class="form-check-label">Singapore</span>
                  </label>
                  <label class="form-check">
                    <input class="form-check-input country-check" type="checkbox" value="IN" data-label="India">
                    <span class="form-check-label">India</span>
                  </label>
                  <label class="form-check">
                    <input class="form-check-input country-check" type="checkbox" value="LK" data-label="Sri Lanka">
                    <span class="form-check-label">Sri Lanka</span>
                  </label>
                  <label class="form-check">
                    <input class="form-check-input country-check" type="checkbox" value="BD" data-label="Bangladesh">
                    <span class="form-check-label">Bangladesh</span>
                  </label>
                  <label class="form-check">
                    <input class="form-check-input country-check" type="checkbox" value="MM" data-label="Myanmar">
                    <span class="form-check-label">Myanmar</span>
                  </label>
                  <label class="form-check">
                    <input class="form-check-input country-check" type="checkbox" value="PH" data-label="Philippines">
                    <span class="form-check-label">Philippines</span>
                  </label>
                  <label class="form-check">
                    <input class="form-check-input country-check" type="checkbox" value="MY" data-label="Malaysia">
                    <span class="form-check-label">Malaysia</span>
                  </label>
                </div>


                <div class="d-flex gap-2 mt-2">
                  <button type="button" class="btn btn-sm btn-light" id="selectAllCountries">Select all</button>
                  <button type="button" class="btn btn-sm btn-light" id="clearCountries">Clear</button>
                  <!-- <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="dropdown">Done</button> -->
                </div>
              </div>
            </div>

            <!-- badges of selected countries -->
            <div class="mt-2 d-flex flex-wrap gap-2" id="selectedCountryBadges"></div>

            <!-- hidden inputs that actually submit with the form -->
            <div id="countriesHidden"></div>

            <div class="small-note mt-1">
              We’ll skip any holiday that appears in <em>any</em> selected country.
            </div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Start Time & End Time (Sync rows)</label>
            <div class="input-group">
              <input type="time" class="form-control" name="custom_start">
              <span class="input-group-text">to</span>
              <input type="time" class="form-control" name="custom_end">
            </div>
            <div class="small-note">Set both Start and End Time</div>
          </div>


          <div class="col-12">
            <button class="btn btn-primary" name="generateSchedule" type="submit">Generate</button>
            <?php if (isset($error)): ?><span class="text-danger ms-3"><?= h($error) ?></span><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- 3) Editable table + Actions -->
    <!-- <?= empty($grid) && empty($generated) ? 'd-none' : '' ?> -->
    <div class="card ">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="card-title mb-0">Session Plan</h5>
          <div class="flex d-flex flex-inline gap-2">

            <form method="post" class="">
              <button type="submit" name="clearCsv" class="btn btn-secondary">Clear Generated</button>
            </form>
          </div>

        </div>

        <form method="post" id="saveForm">

          <!-- carry day pattern & countries so PHP has them on reflow -->
          <?php foreach (($_SESSION['meta']['days'] ?? []) as $d): ?>
            <input type="hidden" name="days[]" value="<?= h($d) ?>">
          <?php endforeach; ?>
          <?php foreach (($_SESSION['meta']['countries'] ?? []) as $c): ?>
            <input type="hidden" name="countries[]" value="<?= h($c) ?>">
          <?php endforeach; ?>

          <!-- carry selection -->
          <input type="hidden" name="course_id" value="<?= h($selected['course_id']) ?>">
          <input type="hidden" name="course_code" value="<?= h($selected['course_code']) ?>">
          <input type="hidden" name="module_code" value="<?= h($selected['module_code']) ?>">
          <input type="hidden" name="learning_mode" value="<?= h($selected['learning_mode']) ?>">
          <input type="hidden" name="course_title" value="<?= h($selected['course_title']) ?>">
          <input type="hidden" name="cohort_code" value="<?= h($_POST['cohort_code'] ?? ($_SESSION['selected']['cohort_code'] ?? '')) ?>">
          <input type="hidden" name="module_title" value="<?= h($selected['module_title'] ?? '') ?>">




          <div class="table-responsive">

            <div class="alert alert-light border d-flex flex-wrap gap-3 align-items-center mb-3">
              <div><strong>Course:</strong> <?= h($selected['course_title'] ?? '-') ?></div>
              <div><strong>Module:</strong> <?= h($selected['module_title'] ?? '-') ?></div> <!-- NEW -->
              <div><strong>Code:</strong> <?= h($selected['course_code'] ?? '-') ?></div>
              <div><strong>Mode:</strong> <?= h($selected['learning_mode'] ?? '-') ?></div>
              <div><strong>Cohort:</strong> <?= h($_SESSION['selected']['cohort_code'] ?? '-') ?></div>
            </div>


            <table class="table table-sm table-bordered align-middle ">
              <thead>
                <tr>
                  <th class="">Session<br>No</th>
                  <th class="short_col2">Session Type-<br>Mode</th>
                  <th class="details_col">Session Details</th>
                  <th class="">Duration<br>Hr</th>
                  <th class="short_col">Faculty Name</th>
                  <th class="">Date</th>
                  <th class="short_col">Day</th>
                  <th class="">Time</th>
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
                                        <td class="details_col">' . h($details) . '</td>
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

                                        <td>
                                        <input type="text" 
                                        id="row-date-' . $i . '" 
                                        name="rows[' . $i . '][date]" 
                                        class="form-control form-control-sm date-input" 
                                        
                                        value="' . h($r['date']) . '" 
                                        data-day-target="day-' . $i . '"
                                        data-index="' . $i . '"
                                        placeholder="dd/mm/yyyy">
                                        </td>


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

          <button class="btn btn-danger" formaction="download_pdf.php" formmethod="post">Download PDF</button>
          <button class="btn btn-primary" formaction="download_excel.php" formmethod="post">Download Excel</button>
          <button class="btn btn-primary" formaction="" formmethod="post">View HTML</button>
          <button class="btn btn-primary" formaction="" formmethod="post">Copy to Messages</button>

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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Flatpickr JS -->
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <script>
    // --- Course search & details ---
    const searchInput = document.getElementById('search');
    const resultsBox = document.getElementById('results');
    const clearBtn = document.getElementById('clearSearch');
    const courseTitle = document.getElementById('courseTitle');
    const moduleSelect = document.getElementById('moduleSelect');
    const modeSelect = document.getElementById('modeSelect');
    const modeDetails = document.getElementById('modeDetails');

    let lastCourseData = null;
    // ADD once, near your helpers
    function debounce(fn, ms = 250) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
      };
    }

    // REPLACE your searchInput input listener with:
    searchInput?.addEventListener('input', debounce(async () => {
      const q = searchInput.value.trim();
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
    }, 250));

    clearBtn?.addEventListener('click', () => {
      searchInput.value = '';
      resultsBox.innerHTML = '';
      searchInput.focus();
    });

    // helper and update your event handlers
    function setAll(name, value) {
      document.querySelectorAll(`input[name="${name}"]`).forEach(el => el.value = value ?? '');
    }


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
      // set ALL hidden course_title fields
      setAll('course_title', courseTitle.textContent.trim());

      moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
        (data.data.modules || []).map(m =>
          `<option value="${m.module_code}" data-title="${m.module_title}">
       ${m.module_title} [${m.module_code}]
     </option>`
        ).join('');
      modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
        (data.data.master_learning_modes || []).map((m, i) => `<option value="${i}">${m.mode}</option>`).join('');
      setAll('learning_mode', '');

      modeDetails.innerHTML = '';
    });
    modeSelect?.addEventListener('change', function() {
      if (!lastCourseData || this.value === "") {
        // Clear everywhere if nothing selected
        setAll('learning_mode', '');
        modeDetails.innerHTML = "";
        return;
      }
      const m = lastCourseData.data.master_learning_modes[this.value];
      setAll('learning_mode', m?.mode || '');
      modeDetails.innerHTML = `<div class="card card-body p-2">
        <div><b>Mode:</b> ${m?.mode || ''} |
        <b>Duration:</b> ${m?.course_duration || ''} |
        <b>Days/Week:</b> ${m?.days_per_week || ''} |
        <b>Hours/Day:</b> ${m?.hours_per_day || ''} |
        <b>Hours/Week:</b> ${m?.hours_per_week || ''}</div>
      </div>`;
    });

    document.addEventListener('DOMContentLoaded', () => {
      const s = document.querySelector('input[name="custom_start"]');
      const e = document.querySelector('input[name="custom_end"]');
      const slot = document.querySelector('select[name="time_slot"]'); // may not exist (you commented it)

      function refreshTimeHint() {
        if (!slot) return;
        const hasCustom = (s?.value && e?.value);
        slot.disabled = !!hasCustom;
      }

      s?.addEventListener('input', refreshTimeHint);
      e?.addEventListener('input', refreshTimeHint);
      refreshTimeHint();
    });

    // Cohort code builder
    const cohortSuffixInput = document.getElementById('cohort_suffix');
    const cohortCodeDisplay = document.getElementById('cohort_code_display');
    const cohortCodeHidden = document.getElementById('cohort_code_hidden');

    function updateCohortCode() {
      const mcode = (moduleSelect.value || 'mcode').trim();
      const suff = (cohortSuffixInput.value || '').trim();
      const code = (mcode + '-' + suff) || '-';
      cohortCodeDisplay.value = code;
      cohortCodeHidden.value = code;
    }
    moduleSelect?.addEventListener('change', function() {
      const opt = this.selectedOptions?.[0];
      const moduleCode = opt?.value || '';
      const moduleTitle = opt?.dataset.title || '';

      // you already have setAll helper from earlier steps
      setAll('module_code', moduleCode);
      setAll('module_title', moduleTitle);

      updateCohortCode();
    });
    cohortSuffixInput?.addEventListener('input', updateCohortCode);

    //if cohort suffix has no value, clear cohort code
    cohortSuffixInput?.addEventListener('input', function() {
      if (!this.value.trim()) {
        cohortCodeDisplay.value = '';
        cohortCodeHidden.value = '';
      } else {
        updateCohortCode();
      }
    });

    // ---- Countries UX (multi-select with badges + search) ----
    (function() {
      // Use prior selection if present, else default ["SG"]
      const preselectedCountries = <?= json_encode($_POST['countries'] ?? ($_SESSION['meta']['countries'] ?? ['SG'])) ?>;

      const checks = Array.from(document.querySelectorAll('.country-check'));
      const badgesBox = document.getElementById('selectedCountryBadges');
      const hiddenBox = document.getElementById('countriesHidden');
      const summaryEl = document.getElementById('countriesSummary');
      const countEl = document.getElementById('countriesCount');
      const searchEl = document.getElementById('countrySearch');

      // Pre-check
      checks.forEach(c => c.checked = preselectedCountries.includes(c.value));

      function getSelected() {
        return checks.filter(c => c.checked).map(c => ({
          code: c.value,
          label: c.dataset.label
        }));
      }

      function renderSelected() {
        const selected = getSelected();

        // badges
        badgesBox.innerHTML = selected.map(s => `
          <span class="badge text-bg-primary d-inline-flex align-items-center">
            ${s.label}
            <button type="button" class="btn-close btn-close-white btn-sm ms-2 remove-country" data-code="${s.code}" aria-label="Remove"></button>
          </span>
        `).join('');

        // hidden inputs
        hiddenBox.innerHTML = selected.map(s => `
          <input type="hidden" name="countries[]" value="${s.code}">
        `).join('');

        // summary + count
        if (selected.length === 0) {
          summaryEl.textContent = 'Select countries…';
        } else if (selected.length === 1) {
          summaryEl.textContent = selected[0].label;
        } else {
          summaryEl.textContent = `${selected[0].label} + ${selected.length - 1} more`;
        }
        countEl.textContent = selected.length.toString();
      }

      // Initial paint
      renderSelected();

      // Toggle handlers
      checks.forEach(c => c.addEventListener('change', renderSelected));

      // Remove from badge
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

      // Search/filter
      searchEl.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('#countriesList .form-check').forEach(label => {
          const text = label.textContent.toLowerCase();
          label.style.display = text.includes(q) ? '' : 'none';
        });
      });

      // Select all / Clear
      document.getElementById('selectAllCountries')?.addEventListener('click', () => {
        checks.forEach(c => c.checked = true);
        renderSelected();
      });
      document.getElementById('clearCountries')?.addEventListener('click', () => {
        checks.forEach(c => c.checked = false);
        renderSelected();
      });
    })();

    document.addEventListener('DOMContentLoaded', () => {
      flatpickr("#start_date", {
        altInput: true,
        altInputClass: 'form-control', // keep Bootstrap styling
        altFormat: "d/m/Y", // what user sees
        dateFormat: "Y-m-d", // what you POST to PHP
        allowInput: true
      });

      flatpickr(".date-input", {
        altInput: true,
        altInputClass: 'form-control',
        altFormat: "d/m/Y",
        dateFormat: "Y-m-d",
        allowInput: true,
        onChange(selectedDates, dateStr, instance) {
          // (you already update the Day cell here)
          const dayTargetId = instance.input.dataset.dayTarget;
          if (dayTargetId && selectedDates?.[0]) {
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            document.getElementById(dayTargetId).value = days[selectedDates[0].getDay()];
          }
          // NEW: reflow from this row downward
          const idx = parseInt(instance.input.dataset.index, 10);
          if (!Number.isNaN(idx) && selectedDates?.[0]) {
            reflowFollowingRows(idx, selectedDates[0]);
          }
        },
        onValueUpdate(selectedDates, dateStr, instance) {
          // This fires when the user types in the alt input; also reflow.
          const idx = parseInt(instance.input.dataset.index, 10);
          if (!Number.isNaN(idx) && selectedDates?.[0]) {
            reflowFollowingRows(idx, selectedDates[0]);
          }
        },
        onReady(selectedDates, dateStr, instance) {
          // pre-fill Day cell on load (you already do this)
          const dayTargetId = instance.input.dataset.dayTarget;
          if (dayTargetId && instance.selectedDates?.[0]) {
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            document.getElementById(dayTargetId).value = days[instance.selectedDates[0].getDay()];
          }
        }
      });

    });

    // Use flatpickr’s built-in formatting/parsing to avoid Safari issues
    function toYMD(dateObj) {
      return window.flatpickr.formatDate(dateObj, "Y-m-d");
    }

    let suppressReflow = 0; // keep this guard globally (add if you don't have it)

    function reflowFollowingRows(rowIndex, newDateObj) {
      const ymd = window.flatpickr.formatDate(newDateObj, "Y-m-d");

      // ✅ Get the chosen day pattern from the HIDDEN carries inside the Save form
      let dayVals = Array.from(
        document.querySelectorAll('#saveForm input[name="days[]"]')
      ).map(el => el.value);

      // Fallback (only if hidden carries aren't present): use checked checkboxes
      if (!dayVals.length) {
        dayVals = Array.from(
          document.querySelectorAll('input[name="days[]"]:checked')
        ).map(el => el.value);
      }
      const uniqDays = [...new Set(dayVals)];

      // ✅ Countries: ONLY the hidden selected ones inside the Save form
      const uniqCountries = [...new Set(
        Array.from(document.querySelectorAll('#saveForm input[name="countries[]"]'))
        .map(el => el.value)
      )];

      const fd = new FormData();
      fd.append('index', rowIndex);
      fd.append('new_date', ymd);
      uniqDays.forEach(d => fd.append('days[]', d));
      uniqCountries.forEach(c => fd.append('countries[]', c));

      suppressReflow++;
      fetch('/schedule_gen/admin/course/reflow_schedule.php', {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(json => {
          if (!json.ok) {
            console.error('Reflow failed:', json.msg);
            return;
          }

          // Apply updates WITHOUT triggering onChange
          json.updates.forEach(u => {
            const inp = document.getElementById('row-date-' + u.index);
            const fp = inp?._flatpickr;
            if (fp) fp.setDate(u.date, /*triggerChange*/ false, "Y-m-d");
            else if (inp) inp.value = u.date;

            const dayEl = document.getElementById('day-' + u.index);
            if (dayEl) dayEl.value = u.day;
          });
        })
        .catch(err => console.error(err))
        .finally(() => {
          suppressReflow--;
        });
    }
  </script>

</body>

</html>