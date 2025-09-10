<?php
// schedule_view.php
session_start();
date_default_timezone_set('Asia/Singapore');

require __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo "Invalid ID";
    exit;
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function fmt_dmy($s)
{
    $s = trim((string)$s);
    if ($s === '') return '';
    foreach (['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt && $dt->format($fmt) === $s) return $dt->format('d/m/Y');
    }
    $ts = strtotime($s);
    return $ts ? date('d/m/Y', $ts) : $s;
}

$stmt = $conn->prepare("SELECT * FROM session_plans WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo "Schedule not found.";
    exit;
}

$plan = json_decode($row['plan_json'] ?? '[]', true);
$meta = $plan['meta'] ?? [];

$courseTitle  = $row['course_title']  ?: ($meta['course_title']  ?? '');
$courseCode   = $row['course_code']   ?: ($meta['course_code']   ?? '');
$moduleCode   = $row['module_code']   ?: ($meta['module_code']   ?? '');
$cohort       = $row['cohort_code']   ?: ($meta['cohort_code']   ?? '');
$learningMode = $row['learning_mode'] ?: ($meta['learning_mode'] ?? '');
$startDate    = $meta['start_date']   ?? '';
$rowsData     = $plan['rows']         ?? [];
$userId       = $row['user_id'];
$createdAt    = $row['created_at'];
$updatedAt    = $row['last_updated'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Schedule #<?= (int)$id ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #111;
        }

        .title-bar {
            background: #941D63;
            color: #fff;
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: .5px;
            padding: 10px 0;
            margin-bottom: 8px;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            table-layout: fixed;
        }

        .meta-table th,
        .meta-table td {
            border: 1px solid #fff;
            padding: 6px;
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

        table.schedule {
            width: 100%;
            border-collapse: collapse;
        }

        table.schedule th,
        table.schedule td {
            border: 1px solid #000;
            padding: 6px 5px;
            text-align: center;
            vertical-align: middle;
        }

        table.schedule thead th {
            background: #f0f0f0;
            font-weight: 700;
        }

        td.details {
            text-align: left;
        }

        tbody tr:last-child {
            background: #FFF7B2;
        }

        .toolbar {
            display: flex;
            gap: .5rem;
            align-items: center;
            margin: 12px 0;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            @page {
                size: A4 portrait;
                margin: 10mm;
            }

            body {
                font-size: 10px;
            }
        }
    </style>
</head>

<body class="bg-white">

    <div class="container-fluid py-3">

        <div class="no-print toolbar">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Back</a>

            <!-- Download buttons -->
            <a class="btn btn-primary btn-sm" href="download_pdf.php?id=<?= (int)$id ?>">Download PDF</a>
            <a class="btn btn-success btn-sm" href="download_excel.php?id=<?= (int)$id ?>">Download Excel</a>

            <span class="ms-2 text-muted">
                Schedule ID: <strong>#<?= (int)$id ?></strong>
                • Created: <?= h($createdAt) ?>
                • Updated: <?= h($updatedAt) ?>
            </span>
        </div>

        <div class="title-bar">CLASS SCHEDULE</div>

        <table class="meta-table">
            <tr>
                <th>Module Name:</th>
                <td><?= h($moduleCode) ?></td>
            </tr>
            <tr>
                <th>Course Name:</th>
                <td><?= h($courseTitle) ?></td>
            </tr>
            <tr>
                <th>Cohort Code:</th>
                <td><?= h($cohort) ?></td>
            </tr>
            <tr>
                <th>Mode :</th>
                <td><?= h($learningMode) ?></td>
            </tr>
            <tr>
                <th>SOC :</th>
                <td><?php $ts = strtotime($startDate);
                    echo $ts ? date('d-M-y', $ts) : h($startDate); ?></td>
            </tr>
        </table>

        <table class="schedule">
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
                <?php foreach ($rowsData as $r): ?>
                    <tr>
                        <td><?= h($r['no'] ?? '') ?></td>
                        <td><?= h($r['type'] ?? '') ?></td>
                        <td class="details"><?= h($r['details'] ?? '') ?></td>
                        <td><?= h($r['duration'] ?? '') ?></td>
                        <td><?= h($r['faculty'] ?? '') ?></td>
                        <td><?= h(fmt_dmy($r['date'] ?? '')) ?></td>
                        <td><?= h($r['day'] ?? '') ?></td>
                        <td><?= h($r['time'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>
</body>

</html>