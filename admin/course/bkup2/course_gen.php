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
require __DIR__ . '/schedule_lib.php';



// Refresh courses from API
if (isPost('refresh')) {
    if (!$conn) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'DB not configured.'];
    } else {
        $r = upsert_courses_from_api($conn);
        $_SESSION['flash'] = ['type' => $r['ok'] ? 'success' : 'danger', 'message' => $r['msg']];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Save template + rows
if (isPost('saveTemplate')) {
    $course_id     = trim($_POST['course_id'] ?? '');
    $course_code   = trim($_POST['course_code'] ?? '');
    $module_code   = trim($_POST['module_code'] ?? '');
    $learning_mode = trim($_POST['learning_mode_text'] ?? '');

    if ($course_id === '' || $course_code === '' || $module_code === '' || $learning_mode === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please select Course, Module, and Mode before saving.'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!isset($_FILES['csvFile']) || !is_uploaded_file($_FILES['csvFile']['tmp_name'])) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please upload a CSV file.'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $rows = csv_to_rows($_FILES['csvFile']['tmp_name']);
    if (!$rows) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'No rows detected in CSV.'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Insert template header
        $stmt = $conn->prepare("
            INSERT INTO session_templates
                (course_id, course_code, module_code, learning_mode, user_id)
            VALUES (?,?,?,?,?)
        ");
        $stmt->bind_param("ssssi", $course_id, $course_code, $module_code, $learning_mode, $userId);
        $stmt->execute();
        $templateId = (int)$conn->insert_id;
        $stmt->close();

        // Insert rows
        $stmt2 = $conn->prepare("
            INSERT INTO session_template_rows
                (template_id, session_no, session_type, session_details, duration_hr)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                session_type = VALUES(session_type),
                session_details = VALUES(session_details),
                duration_hr = VALUES(duration_hr)
        ");
        foreach ($rows as $r) {
            [$no, $type, $details, $dur] = $r;
            $type = normalize_session_type($type);
            $stmt2->bind_param("issss", $templateId, $no, $type, $details, $dur);
            $stmt2->execute();
        }
        $stmt2->close();

        $conn->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Template saved (#$templateId) with " . count($rows) . " rows."];

        // Optional: pass selection to Generate page via GET
        $to = "../session_plans/generate.php?course_id=" . urlencode($course_id)
            . "&course_code=" . urlencode($course_code)
            . "&module_code=" . urlencode($module_code)
            . "&learning_mode=" . urlencode($learning_mode);
        $_SESSION['selected'] = [
            'course_id' => $course_id,
            'course_code' => $course_code,
            'module_code' => $module_code,
            'learning_mode' => $learning_mode
        ];
        header('Location: ' . $_SERVER['PHP_SELF'] . '/../course/schedule.php');
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Save failed: ' . $e->getMessage()];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <title>Schedule Generator</title> -->
    <title>Session Templates — Master</title>
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
    </style>
</head>

<body class="py-4">
    <div class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mt-2"><?= h($flash['message'] ?? '') ?></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Master: Create Session Template</h4>
            <form method="post"><button class="btn btn-primary" name="refresh" type="submit">Refresh Course List</button></form>
        </div>

        <!-- Course search -->
        <div class="mb-3 position-relative">
            <label class="form-label">Search Courses</label>
            <div class="input-group">
                <input type="text" id="search" class="form-control" placeholder="Type to search...">
                <button type="button" id="clearSearch" class="btn btn-outline-secondary" style="display:none;">&times;</button>
            </div>
            <div id="results" class="list-group"></div>
            <div class="form-text">Select a course to load its modules and modes.</div>
        </div>

        <form method="post" enctype="multipart/form-data" class="row g-3">
            <!-- Hidden selected course info -->
            <input type="hidden" name="course_id" id="course_id">
            <input type="hidden" name="course_code" id="course_code">
            <div class="col-12">
                <div id="courseTitle" class="fw-semibold"></div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Learning Modules</label>
                <select id="moduleSelect" name="module_code" class="form-select" required>
                    <option value="">Select a module...</option>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Learning Modes</label>
                <select id="modeSelect" class="form-select" required>
                    <option value="">Select a mode...</option>
                </select>
                <input type="hidden" name="learning_mode_text" id="learning_mode_text">
                <div id="modeDetails" class="small mt-2"></div>
            </div>

            <div class="col-md-8">
                <label class="form-label">Upload CSV (first 4 columns)</label>
                <input class="form-control" type="file" name="csvFile" accept=".csv" required>
                <div class="form-text">Expected columns: Session No, Session Type, Session Details, Duration Hr</div>
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-success w-100" name="saveTemplate" type="submit">Save Template</button>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script>
        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const clearBtn = document.getElementById('clearSearch');
        const courseTitle = document.getElementById('courseTitle');
        const moduleSelect = document.getElementById('moduleSelect');
        const modeSelect = document.getElementById('modeSelect');
        const modeDetails = document.getElementById('modeDetails');
        const courseIdInp = document.getElementById('course_id');
        const courseCodeInp = document.getElementById('course_code');
        const lmTextInp = document.getElementById('learning_mode_text');

        let lastCourseData = null;

        searchInput.addEventListener('input', async () => {
            const q = searchInput.value.trim();
            clearBtn.style.display = q ? 'block' : 'none';
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

        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            resultsBox.innerHTML = '';
            clearBtn.style.display = 'none';
            searchInput.focus();
        });
        resultsBox.addEventListener('click', async e => {
            const btn = e.target.closest('button');
            if (!btn) return;
            resultsBox.innerHTML = '';
            searchInput.value = btn.textContent.trim();
            courseIdInp.value = btn.dataset.id;
            courseCodeInp.value = btn.dataset.code;

            const res = await fetch('get_course_details.php?id=' + btn.dataset.id);
            const data = await res.json();
            lastCourseData = data;

            courseTitle.textContent = btn.textContent;

            moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
                (data.data.modules || []).map(m => `<option value="${m.module_code}">${m.module_title} [${m.module_code}]</option>`).join('');

            modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
                (data.data.master_learning_modes || []).map((m, i) => `<option value="${i}">${m.mode}</option>`).join('');
            modeDetails.innerHTML = '';
            lmTextInp.value = '';
        });
        modeSelect.addEventListener('change', function() {
            if (!lastCourseData || this.value === "") {
                modeDetails.innerHTML = "";
                lmTextInp.value = "";
                return;
            }
            const m = lastCourseData.data.master_learning_modes[this.value];
            modeDetails.innerHTML = `<div class="card card-body p-2">
                <div><b>Mode:</b> ${m.mode || ''} |
                <b>Duration:</b> ${m.course_duration || ''} |
                <b>Days/Week:</b> ${m.days_per_week || ''} |
                <b>Hours/Day:</b> ${m.hours_per_day || ''} |
                <b>Hours/Week:</b> ${m.hours_per_week || ''}</div>
            </div>`;
            lmTextInp.value = m.mode || '';
        });
    </script>
</body>

</html>