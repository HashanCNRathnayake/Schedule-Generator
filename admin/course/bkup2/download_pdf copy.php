<?php
require __DIR__ . '\..\..\vendor\autoload.php';  // fixed path

use Dompdf\Dompdf;

session_start();

$generated = $_SESSION['generated'] ?? [];

ob_start();
?>
<h2>Session Plan</h2>
<table border="1" cellpadding="5" cellspacing="0" width="100%">
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
        <?php foreach ($generated as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['no']) ?></td>
                <td><?= htmlspecialchars($row['type']) ?></td>
                <td><?= htmlspecialchars($row['details']) ?></td>
                <td><?= htmlspecialchars($row['duration']) ?></td>
                <td><?= htmlspecialchars($row['faculty']) ?></td>
                <td><?= htmlspecialchars($row['date']) ?></td>
                <td><?= htmlspecialchars($row['day']) ?></td>
                <td><?= htmlspecialchars($row['time']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php
$html = ob_get_clean();

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("session-plan.pdf", ["Attachment" => true]);
