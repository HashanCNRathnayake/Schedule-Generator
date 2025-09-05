<?php
session_start();

require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
};

$username = $_SESSION['username'] ?? '';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require __DIR__ . '/components/header.php';
require __DIR__ . '/components/navbar.php';

// header("Location: index.php");
// exit;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>CSV Import — Sessions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>

<body class="bg-light">
  <div class="container py-4">

    <h1 class="mb-3">Import Session CSV (A:D)</h1>

    <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>

    <form class="card card-body mb-4" action="import_csv.php" method="post" enctype="multipart/form-data">
      <div class="mb-3">
        <label class="form-label">CSV file</label>
        <input class="form-control" type="file" name="csv" accept=".csv,text/csv" required>
        <div class="form-text">We’ll look for headers: <code>Session No</code>,
          <code>Session Type - Mode</code>, <code>Session Details</code>, <code>Duration Hrs</code>.
        </div>
      </div>
      <button class="btn btn-primary">Upload & Import</button>
    </form>

    <h2 class="mb-3">Saved Sessions</h2>
    <?php
    $res = $conn->query("SELECT session_no, session_type_mode, session_details, duration_hrs
                       FROM sessions ORDER BY session_no ASC, id ASC");
    if ($res->num_rows === 0): ?>
      <div class="alert alert-info">No data yet — upload a CSV above.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:100px">Session No</th>
              <th style="width:180px">Session Type - Mode</th>
              <th>Session Details</th>
              <th style="width:130px">Duration Hrs</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $res->fetch_assoc()): ?>
              <tr>
                <td><?= (int)$row['session_no'] ?></td>
                <td><?= htmlspecialchars($row['session_type_mode']) ?></td>
                <td><?= nl2br(htmlspecialchars($row['session_details'])) ?></td>
                <td><?= rtrim(rtrim(number_format((float)$row['duration_hrs'], 2, '.', ''), '0'), '.') ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</body>

</html>