<?php
require __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;

session_start();

$rows    = $_POST['rows'] ?? ($_SESSION['generated'] ?? []);
$cohort  = $_POST['cohort_code'] ?? '';
$userId  = $_SESSION['user_id'] ?? '';

ob_start();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Session Plan</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 12px;
        }

        h2 {
            margin: 0 0 6px 0;
        }

        .meta {
            margin: 0 0 10px 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #333;
            padding: 6px 5px;
            text-align: center;
            vertical-align: middle;
        }

        thead th {
            background: #eee;
        }
    </style>
</head>

<body>
    <h2>Session Plan</h2>
    <p class="meta">
        <strong>Cohort Code:</strong> <?= htmlspecialchars($cohort) ?><br>
        <strong>User ID:</strong> <?= htmlspecialchars((string)$userId) ?>
    </p>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Type</th>
                <th>Details</th>
                <th>Duration</th>
                <th>Faculty</th>
                <th>Date</th>
                <th>Day</th>
                <th>Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['no'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['type'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['details'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['duration'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['faculty'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['date'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['day'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['time'] ?? '') ?></td>
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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("session-plan.pdf", ["Attachment" => true]);
