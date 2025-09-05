<?php
// import_csv.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require __DIR__ . '/db.php';

function set_flash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    set_flash('danger', 'No file uploaded or upload error.');
    header('Location: index.php');
    exit;
}

$path = $_FILES['csv']['tmp_name'];
$srcName = basename($_FILES['csv']['name']);

// Basic MIME check (CSV mimes vary)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($path);
$allowed = ['text/plain', 'text/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
if (!in_array($mime, $allowed, true)) {
    set_flash('danger', 'Please upload a CSV file.');
    header('Location: index.php');
    exit;
}

$fh = fopen($path, 'r');
if (!$fh) {
    set_flash('danger', 'Unable to open uploaded file.');
    header('Location: index.php');
    exit;
}

// Normalize header strings for robust matching
$need = [
    'session no'         => null,
    'session type - mode' => null,
    'session details'    => null,
    'duration hrs'       => null,
];

function norm($s)
{
    $s = (string)$s;
    $s = str_replace("\xC2\xA0", ' ', $s); // non-breaking space
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

// 1) Find the header row (don’t assume it’s line 7)
$headerMap = null;
$line = 0;
while (($row = fgetcsv($fh, 0, ',')) !== false) {
    $line++;
    if (!$row) continue;
    $map = [];
    foreach ($row as $i => $col) {
        $map[norm($col)] = $i;
    }
    if (
        isset($map['session no']) &&
        (isset($map['session type - mode']) || isset($map['session type-mode'])) &&
        isset($map['session details']) &&
        (isset($map['duration hrs']) || isset($map['duration hours']))
    ) {

        $headerMap = [
            'session no'          => $map['session no'],
            'session type - mode' => $map['session type - mode'] ?? $map['session type-mode'],
            'session details'     => $map['session details'],
            'duration hrs'        => $map['duration hrs'] ?? $map['duration hours'],
        ];
        break;
    }
}
if (!$headerMap) {
    set_flash('danger', 'Could not find the required headers in the file.');
    header('Location: index.php');
    exit;
}

// 2) Read data rows after the header row and insert
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "INSERT INTO sessions
           (session_no, session_type_mode, session_details, duration_hrs, source_file, row_no)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $rowNo = $line; // header line number
    $inserted = 0;

    while (($row = fgetcsv($fh, 0, ',')) !== false) {
        $rowNo++;

        // Skip completely empty lines
        $nonEmpty = array_filter($row, fn($v) => trim((string)$v) !== '');
        if (!$nonEmpty) continue;

        $session_no        = (int)($row[$headerMap['session no']] ?? 0);
        $session_type_mode = trim((string)($row[$headerMap['session type - mode']] ?? ''));
        $session_details   = trim((string)($row[$headerMap['session details']] ?? ''));
        $duration_hrs_raw  = (string)($row[$headerMap['duration hrs']] ?? '0');

        // Accept "3", "3.0", "3 hrs", etc.
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $duration_hrs_raw, $m)) {
            $duration_hrs = (float)$m[1];
        } else {
            $duration_hrs = 0.0;
        }

        // If the row doesn’t have at least details, skip
        if ($session_no === 0 && $session_type_mode === '' && $session_details === '') {
            continue;
        }

        $stmt->bind_param(
            'issdsi',
            $session_no,
            $session_type_mode,
            $session_details,
            $duration_hrs,
            $srcName,
            $rowNo
        );
        $stmt->execute();
        $inserted++;
    }

    $conn->commit();
    fclose($fh);

    set_flash('success', "Imported {$inserted} row(s) from {$srcName}.");
    header('Location: index.php');
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    if (is_resource($fh)) fclose($fh);
    set_flash('danger', 'Import failed: ' . $e->getMessage());
    header('Location: index.php');
    exit;
}
