<?php
// upload_and_show.php
declare(strict_types=1);
session_start();

// ---------- DB ----------
$pdo = new PDO(
    'mysql:host=localhost;dbname=schedule_gen;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ---------- Helpers ----------
function normalize_header(string $h): string
{
    $h = trim($h);
    $h = preg_replace('/\s+/', ' ', $h);
    return mb_strtolower($h);
}

function parse_excelish_date(?string $s): ?string
{
    // Accepts 25-Aug-25, 1-Sep-2025, 2025-08-25, etc.
    if (!$s) return null;
    $s = trim($s);
    // Common day-mon-yr
    $try = DateTime::createFromFormat('d-M-y', $s) ?: DateTime::createFromFormat('d-M-Y', $s);
    if (!$try) {
        // Fallbacks
        $try = date_create($s);
    }
    return $try ? $try->format('Y-m-d') : null;
}

// Map the headers we expect → keys we’ll use
$wanted = [
    'session no'            => 'session_no',
    'session type - mode'   => 'session_type',
    'session details'       => 'details',
    'duration hrs'          => 'duration_hrs',
    'faculty name'          => 'faculty_name',
    'date'                  => 'session_date',
    'day'                   => 'day_name',
    'time'                  => 'time_str',
    'session| week end| day #' => 'week_day_no', // If your CSV literally contains this text; adjust to your real header
];

// ---------- Handle upload ----------
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    if ($_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $flash = 'Upload error.';
    } else {
        $tmp = $_FILES['csv']['tmp_name'];

        // Open CSV
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $flash = 'Cannot open uploaded file.';
        } else {
            $row = 0;
            $headerIndex = [];
            $headerRowNumber = 7; // A7..D7 in your note

            // read until header row
            while (($cols = fgetcsv($fh)) !== false) {
                $row++;

                // Skip empty lines
                if ($row < $headerRowNumber) continue;

                if (empty($headerIndex)) {
                    // Build header index map
                    foreach ($cols as $i => $h) {
                        $norm = normalize_header($h);
                        $headerIndex[$norm] = $i;
                    }
                    // Make sure we can find at least the must-have columns
                    foreach (['session no', 'session type - mode', 'session details', 'duration hrs'] as $must) {
                        if (!array_key_exists($must, $headerIndex)) {
                            $flash = "Missing header: {$must}. Check your CSV export (row {$headerRowNumber}).";
                        }
                    }
                    if ($flash) break;
                    continue; // move to first data row
                }

                // Stop if the row is completely empty
                $allBlank = true;
                foreach ($cols as $c) {
                    if (trim((string)$c) !== '') {
                        $allBlank = false;
                        break;
                    }
                }
                if ($allBlank) {
                    continue;
                }

                // Extract wanted fields safely by header name
                $data = [];
                foreach ($wanted as $headerText => $key) {
                    if (isset($headerIndex[$headerText])) {
                        $val = $cols[$headerIndex[$headerText]] ?? null;
                    } else {
                        $val = null; // column not present
                    }
                    $data[$key] = is_string($val) ? trim($val) : $val;
                }

                // Convert date field
                $data['session_date'] = parse_excelish_date($data['session_date']);

                // Duration numeric
                if ($data['duration_hrs'] !== null && $data['duration_hrs'] !== '') {
                    $data['duration_hrs'] = (float) str_replace(',', '.', $data['duration_hrs']);
                } else {
                    $data['duration_hrs'] = 0.0;
                }

                // Optional: attach logged-in user id
                $data['user_id'] = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

                // Insert
                $sql = "INSERT INTO sessions
                        (session_no, session_type, details, duration_hrs, faculty_name, session_date, day_name, time_str, week_day_no, user_id)
                        VALUES (:session_no, :session_type, :details, :duration_hrs, :faculty_name, :session_date, :day_name, :time_str, :week_day_no, :user_id)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':session_no'   => $data['session_no'],
                    ':session_type' => $data['session_type'],
                    ':details'      => $data['details'],
                    ':duration_hrs' => $data['duration_hrs'],
                    ':faculty_name' => $data['faculty_name'] ?: null,
                    ':session_date' => $data['session_date'],
                    ':day_name'     => $data['day_name'] ?: null,
                    ':time_str'     => $data['time_str'] ?: null,
                    ':week_day_no'  => $data['week_day_no'] ?: null,
                    ':user_id'      => $data['user_id'],
                ]);
            }
            fclose($fh);

            if (!$flash) $flash = 'Import complete.';
        }
    }
}

// ---------- Fetch latest rows to show ----------
$rows = $pdo->query("SELECT * FROM sessions ORDER BY id DESC LIMIT 200")->fetchAll();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Session Import</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="p-4">
    <h3 class="mb-3">Import Sessions (CSV)</h3>

    <?php if ($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="mb-4">
        <div class="mb-2">
            <label class="form-label">CSV File (header at row 7)</label>
            <input type="file" name="csv" class="form-control" accept=".csv,text/csv">
        </div>
        <button class="btn btn-primary">Upload & Import</button>
    </form>

    <h5>Latest Imported Rows</h5>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Session No</th>
                    <th>Session Type</th>
                    <th>Details</th>
                    <th>Duration (hrs)</th>
                    <th>Faculty</th>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Week Day #</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars($r['session_no']) ?></td>
                        <td><?= htmlspecialchars($r['session_type']) ?></td>
                        <td style="max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?= htmlspecialchars($r['details']) ?>
                        </td>
                        <td><?= htmlspecialchars($r['duration_hrs']) ?></td>
                        <td><?= htmlspecialchars($r['faculty_name']) ?></td>
                        <td><?= htmlspecialchars($r['session_date']) ?></td>
                        <td><?= htmlspecialchars($r['day_name']) ?></td>
                        <td><?= htmlspecialchars($r['time_str']) ?></td>
                        <td><?= htmlspecialchars($r['week_day_no']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10" class="text-muted">No data yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>

</html>