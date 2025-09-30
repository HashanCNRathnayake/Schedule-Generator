<?php
require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;

session_start();

require __DIR__ . '/db.php';

// ---- NEW: allow export by id directly from DB ----


$rows    = $_POST['rows'] ?? ($_SESSION['generated'] ?? []);
$cohort  = trim($_POST['cohort_code'] ?? ($_SESSION['selected']['cohort_code'] ?? ''));
$userId  = (int)($_SESSION['auth']['user_id'] ?? 0);

$courseId     = $_POST['course_id']     ?? ($_SESSION['selected']['course_id']     ?? '');
$courseCode   = $_POST['course_code']   ?? ($_SESSION['selected']['course_code']   ?? '');
$moduleCode   = $_POST['module_code']   ?? ($_SESSION['selected']['module_code']   ?? '');
$learningMode = $_POST['learning_mode'] ?? ($_SESSION['selected']['learning_mode'] ?? '');
$courseTitle  = $_SESSION['selected']['course_title']  ?? '';
$moduleTitle  = $_POST['module_title']  ?? ($_SESSION['selected']['module_title']  ?? '');


$startDate    = $_POST['start_date']    ?? ($_SESSION['meta']['start_date']   ?? '');
$days         = $_POST['days']          ?? ($_SESSION['meta']['days']         ?? []);
$countries    = $_POST['countries']     ?? ($_SESSION['meta']['countries']    ?? []);
$timeSlot     = $_POST['time_slot']     ?? ($_SESSION['meta']['time_slot']    ?? '');
$customStart  = $_POST['custom_start']  ?? ($_SESSION['meta']['custom_start'] ?? '');
$customEnd    = $_POST['custom_end']    ?? ($_SESSION['meta']['custom_end']   ?? '');


if (isset($_GET['id'])) {
    $exportId = (int)$_GET['id'];

    $stmt = $conn->prepare("SELECT * FROM session_plans WHERE id = ?");
    $stmt->bind_param('i', $exportId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rowDb = $res->fetch_assoc();
    $stmt->close();

    if ($rowDb) {
        $plan = json_decode($rowDb['plan_json'] ?? '[]', true) ?: [];
        $meta = $plan['meta'] ?? [];

        $rows        = $plan['rows'] ?? [];
        $userId      = (int)($rowDb['user_id'] ?? ($meta['user_id'] ?? 0));
        $cohort      = $rowDb['cohort_code']   ?: ($meta['cohort_code']   ?? '');
        $courseId    = $rowDb['course_id']     ?: ($meta['course_id']     ?? '');
        $courseCode  = $rowDb['course_code']   ?: ($meta['course_code']   ?? '');
        $moduleCode  = $rowDb['module_code']   ?: ($meta['module_code']   ?? '');
        $learningMode = $rowDb['learning_mode'] ?: ($meta['learning_mode'] ?? '');
        $courseTitle = $rowDb['course_title']  ?: ($meta['course_title']  ?? '');
        $moduleTitle = $rowDb['module_title']  ?: ($meta['module_title']  ?? '');


        $startDate   = $meta['start_date']   ?? '';
        $days        = $meta['days']         ?? [];
        $countries   = $meta['countries']    ?? [];
        $timeSlot    = $meta['time']['slot'] ?? '';
        $customStart = $meta['time']['custom_start'] ?? '';
        $customEnd   = $meta['time']['custom_end']   ?? '';
    } else {
        http_response_code(404);
        exit('Schedule not found.');
    }
}

// Format any reasonable date string to dd/mm/yyyy for the PDF
function fmt_dmy($s)
{
    $s = trim((string)$s);
    if ($s === '') return '';
    // Try common input formats first
    foreach (['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y'] as $inFmt) {
        $dt = DateTime::createFromFormat($inFmt, $s);
        if ($dt && $dt->format($inFmt) === $s) {
            return $dt->format('d/m/Y');
        }
    }
    // Fallback for ISO/timestamps like "2025-09-08T00:00:00"
    $ts = strtotime($s);
    return $ts ? date('d/m/Y', $ts) : $s;
}


ob_start();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Class Schedule</title>
    <style>
        /* Page + base typography */
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 12mm 10mm;
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #111;
        }

        /* Header bar */
        .title-bar {
            background: #941D63;
            color: #fff;
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: .5px;
            padding: 8px 0;
            border: 1px solid #ffffffff;
            border-bottom: 0;

        }

        /* Meta table (like screenshot) */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
            table-layout: fixed;
        }

        .meta-table th,
        .meta-table td {
            /* border: 1px solid #ddd; */
            border: 1px solid #ffffffff;

            padding: 6px 6px;
            vertical-align: middle;
        }

        .meta-table th {
            width: 26%;
            background: #941D63;
            color: #fff;
            text-align: left;
            font-weight: 700;
        }

        .meta-table td {
            background: #ddd;
            text-align: left;
        }

        /* Main table */
        table.schedule {
            width: 100%;
            border-collapse: collapse;
            /* table-layout: fixed; */
        }

        table.schedule th,
        table.schedule td {
            border: 1px solid #000000ff;
            padding: 3px 2px;
            text-align: center;
            vertical-align: middle;
            /* word-break: break-word;
            overflow-wrap: anywhere;
            white-space: normal; */
        }

        table.schedule thead th {
            background: #f0f0f0;
            font-weight: 700;
        }

        /* Smaller text in dense columns */
        .auto_col {
            width: auto;
        }

        .short_col {
            width: auto;
        }

        .long_col {
            width: 250px;
        }

        .short_col2 {
            width: 80px;
        }

        td.details {
            font-size: 9.5px;
            line-height: 1.25;
            text-align: left;
        }

        td.time,
        td.faculty,
        td.type {
            font-size: 9.5px;
        }

        /* Highlight the LAST row */
        tbody tr:last-child {
            background: #FFF7B2;
        }

        /* soft yellow */
    </style>
</head>

<body>

    <div class="title-bar">CLASS SCHEDULE</div>

    <table class="meta-table">
        <tr>
            <th>Module Name:</th>
            <td><?= htmlspecialchars('[' . $moduleCode . '] ' . $moduleTitle) ?></td>
        </tr>
        <tr>
            <th>Course Name:</th>
            <td><?= htmlspecialchars($courseTitle) ?></td>
        </tr>
        <tr>
            <th>Cohort Code:</th>
            <td><?= htmlspecialchars($cohort) ?></td>
        </tr>
        <tr>
            <th>Mode :</th>
            <td><?= htmlspecialchars($learningMode) ?></td>
        </tr>
        <tr>
            <th>SOC :</th>
            <td>
                <?php
                // e.g. 25-Aug-25 to mimic screenshot
                $soc = trim($startDate ?? '');
                if ($soc) {
                    $ts = strtotime($soc);
                    echo $ts ? date('d-M-y', $ts) : htmlspecialchars($soc);
                }
                ?>
            </td>
        </tr>
    </table>


    <table class="schedule">
        <thead>
            <tr>
                <th class="no short_col">Session<br>No</th>
                <th class="type short_col2">Session Type-<br>Mode</th>
                <th class="details long_col">Session Details</th>
                <th class="dur auto_col">Duration<br>Hr</th>
                <th class="faculty auto_col">Faculty Name</th>
                <th class="date auto_col">Date</th>
                <th class="day auto_col">Day</th>
                <th class="time auto_col">Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="no"><?= htmlspecialchars($r['no'] ?? '') ?></td>
                    <td class="type"><?= htmlspecialchars($r['type'] ?? '') ?></td>
                    <td class="details"><?= htmlspecialchars($r['details'] ?? '') ?></td>
                    <td class="dur"><?= htmlspecialchars($r['duration'] ?? '') ?></td>
                    <td class="faculty"><?= htmlspecialchars($r['faculty'] ?? '') ?></td>
                    <td class="date"><?= htmlspecialchars(fmt_dmy($r['date'] ?? '')) ?></td>
                    <td class="day"><?= htmlspecialchars($r['day'] ?? '') ?></td>
                    <td class="time"><?= htmlspecialchars($r['time'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>

</html>
<?php
$html = ob_get_clean();

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');

if ($conn && $cohort && $rows) {
    $plan = [
        'meta' => [
            'user_id'       => $userId,
            'cohort_code'   => $cohort,
            'course_id'     => $courseId,
            'course_code'   => $courseCode,
            'module_code'   => $moduleCode,
            'module_title'  => $moduleTitle,
            'learning_mode' => $learningMode,
            'course_title'  => $courseTitle,
            'start_date'    => $startDate,
            'days'          => array_values((array)$days),
            'countries'     => array_values((array)$countries),
            'time'          => [
                'slot'         => $timeSlot,
                'custom_start' => $customStart,
                'custom_end'   => $customEnd,
            ],
            'counts'        => ['rows' => count($rows)],
        ],
        'rows' => array_values($rows),
    ];
    $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $sql = "INSERT INTO session_plans
           (user_id, cohort_code, course_id, course_code, module_code, module_title, learning_mode, course_title, plan_json)
          VALUES (?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            course_id=VALUES(course_id),
            course_code=VALUES(course_code),
            module_code=VALUES(module_code),
            module_title=VALUES(module_title),
            learning_mode=VALUES(learning_mode),
            course_title=VALUES(course_title),
            plan_json=VALUES(plan_json),
            last_updated=CURRENT_TIMESTAMP";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param(
            'issssssss',
            $userId,       // i
            $cohort,       // s
            $courseId,     // s
            $courseCode,   // s
            $moduleCode,   // s
            $moduleTitle,  // s
            $learningMode, // s
            $courseTitle,  // s
            $json          // s  ← bind JSON as a normal string
        );
        $stmt->send_long_data(8, $json);
        $stmt->execute();
        $stmt->close();
        $_SESSION['save_msg'] = "Saved plan for cohort {$cohort}.";
    } else {
        $_SESSION['save_msg'] = "DB prepare failed while saving plan.";
    }
}

$dompdf->render();
$dompdf->stream("session-plan.pdf", ["Attachment" => true]);
