<?php
// index.php
session_start();
date_default_timezone_set('Asia/Singapore');

require_once __DIR__ . '/vendor/autoload.php';
if (class_exists(\Dotenv\Dotenv::class)) {
  $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
  $dotenv->safeLoad();
}
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/db.php';
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/navbar.php';


// OPTIONAL auth guard
// if (!isset($_SESSION['user_id'])) { header("Location: {$baseUrl}login.php"); exit; }

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$filters = [
  'id'           => trim($_GET['id']           ?? ''),
  'user_id'      => trim($_GET['user_id']      ?? ''),
  'cohort_code'  => trim($_GET['cohort_code']  ?? ''),
  'course_id'    => trim($_GET['course_id']    ?? ''),
  'course_code'  => trim($_GET['course_code']  ?? ''),
  'module_code'  => trim($_GET['module_code']  ?? ''),
  'learning_mode' => trim($_GET['learning_mode'] ?? ''),
  'course_title' => trim($_GET['course_title'] ?? ''),
  'created_from' => trim($_GET['created_from'] ?? ''),
  'created_to'   => trim($_GET['created_to']   ?? ''),
  'q'            => trim($_GET['q']            ?? ''), // global search
];

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(5, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types  = '';

if ($filters['id'] !== '') {
  $where[] = 'id = ?';
  $types .= 'i';
  $params[] = (int)$filters['id'];
}
if ($filters['user_id'] !== '') {
  $where[] = 'user_id = ?';
  $types .= 'i';
  $params[] = (int)$filters['user_id'];
}
if ($filters['cohort_code'] !== '') {
  $where[] = 'cohort_code LIKE ?';
  $types .= 's';
  $params[] = '%' . $filters['cohort_code'] . '%';
}
if ($filters['course_id'] !== '') {
  $where[] = 'course_id LIKE ?';
  $types .= 's';
  $params[] = '%' . $filters['course_id'] . '%';
}
if ($filters['course_code'] !== '') {
  $where[] = 'course_code LIKE ?';
  $types .= 's';
  $params[] = '%' . $filters['course_code'] . '%';
}
if ($filters['module_code'] !== '') {
  $where[] = 'module_code LIKE ?';
  $types .= 's';
  $params[] = '%' . $filters['module_code'] . '%';
}
if ($filters['learning_mode'] !== '') {
  $where[] = 'learning_mode LIKE ?';
  $types .= 's';
  $params[] = '%' . $filters['learning_mode'] . '%';
}
if ($filters['course_title'] !== '') {
  $where[] = 'course_title LIKE ?';
  $types .= 's';
  $params[] = '%' . $filters['course_title'] . '%';
}

if ($filters['created_from'] !== '') {
  $where[] = 'DATE(created_at) >= ?';
  $types .= 's';
  $params[] = $filters['created_from'];
}
if ($filters['created_to']   !== '') {
  $where[] = 'DATE(created_at) <= ?';
  $types .= 's';
  $params[] = $filters['created_to'];
}

if ($filters['q'] !== '') {
  $where[] = '(cohort_code LIKE ? OR course_code LIKE ? OR module_code LIKE ? OR learning_mode LIKE ? OR course_title LIKE ?)';
  $types  .= 'sssss';
  $q = '%' . $filters['q'] . '%';
  array_push($params, $q, $q, $q, $q, $q);
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// total count
$sqlCount = "SELECT COUNT(*) FROM session_plans {$whereSql}";
$stmt = $conn->prepare($sqlCount);
if ($types) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();

$pages = max(1, (int)ceil($totalRows / $limit));

// fetch page
$sql = "SELECT id, user_id, cohort_code, course_id, course_code, module_code, learning_mode, course_title, plan_json, created_at, last_updated
        FROM session_plans
        {$whereSql}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($types) {
  $bindTypes = $types . 'ii';
  $bindVals  = array_merge($params, [$limit, $offset]);
  $stmt->bind_param($bindTypes, ...$bindVals);
} else {
  $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// helper to count schedule rows from JSON
function count_schedule_rows($json)
{
  $data = json_decode($json, true);
  if (is_array($data)) {
    if (isset($data['meta']['counts']['rows'])) return (int)$data['meta']['counts']['rows'];
    if (isset($data['rows']) && is_array($data['rows'])) return count($data['rows']);
  }
  return 0;
}
?>

<style>
  .tbl th,
  .tbl td {
    white-space: nowrap;
  }

  .sticky-head thead th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    z-index: 1;
  }

  .filter-row input {
    width: 100%;
  }

  .purple {
    color: #941D63;
    font-weight: 700;
  }

  .muted {
    color: #6c757d;
    font-size: .9rem;
  }
</style>
<link rel="icon" href="images/favicon.ico" type="image/ico">

</head>

<body class="bg-light">
  <div class="container-fluid py-3">

    <div class="row mb-3">
      <!-- <h3 class="mb-0 purple">Schedules</h3> -->

      <form class="row">
        <div class="row justify-content-start align-items-center g-2">
          <!-- <div class="col-md-1"><input name="id" value="<?= h($filters['id']) ?>" class="form-control form-control-sm" placeholder="ID"></div>
                <div class="col-md-1"><input name="user_id" value="<?= h($filters['user_id']) ?>" class="form-control form-control-sm" placeholder="User"></div>
                <div class="col-md-2"><input name="cohort_code" value="<?= h($filters['cohort_code']) ?>" class="form-control form-control-sm" placeholder="Cohort Code"></div>
                <div class="col-md-2"><input name="course_code" value="<?= h($filters['course_code']) ?>" class="form-control form-control-sm" placeholder="Course Code"></div>
                <div class="col-md-2"><input name="module_code" value="<?= h($filters['module_code']) ?>" class="form-control form-control-sm" placeholder="Module Code"></div>
                <div class="col-md-2"><input name="learning_mode" value="<?= h($filters['learning_mode']) ?>" class="form-control form-control-sm" placeholder="Learning Mode"></div> -->
          <div class="col-4 mt-2"><input name="course_title" value="<?= h($filters['course_title']) ?>" class="form-control form-control-sm" placeholder="Course Title"></div>
          <!-- <div class="col-1"> <a class="btn btn-sm" href="<?= h(basename(__FILE__)) ?>">x</a>
          </div> -->
          <!-- <div class="col-md-2 mt-2"><input type="date" name="created_from" value="<?= h($filters['created_from']) ?>" class="form-control form-control-sm" placeholder="From"></div>
                <div class="col-md-2 mt-2"><input type="date" name="created_to" value="<?= h($filters['created_to']) ?>" class="form-control form-control-sm" placeholder="To"></div>
                <div class="col-md-3 mt-2"><input name="q" value="<?= h($filters['q']) ?>" class="form-control form-control-sm" placeholder="Global search"></div>
                <div class="col-md-2 mt-2">
                    <select name="limit" class="form-select form-select-sm">
                        <?php foreach ([10, 20, 50, 100] as $opt): ?>
                            <option value="<?= $opt ?>" <?= $opt == $limit ? 'selected' : '' ?>><?= $opt ?> / page</option>
                        <?php endforeach; ?>
                    </select>
                </div> -->
          <div class="col-2"><button class="btn btn-primary btn-sm w-100">Search</button></div>
          <div class="ms-3 mt-2 muted col-1">(<?= (int)$totalRows ?> total)</div>

        </div>

      </form>

    </div>

    <div class="card">
      <div class="table-responsive sticky-head">
        <table class="table table-sm table-bordered align-middle tbl mb-0">
          <thead>
            <tr class="table-light">
              <th>ID</th>
              <th>User</th>
              <th>Cohort Code</th>
              <th>Course Code</th>
              <th>Module Code</th>
              <th>Learning Mode</th>
              <th>Course Title</th>
              <th>Rows</th>
              <th>Created</th>
              <th>Updated</th>
              <th style="min-width:120px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="11" class="text-center text-muted py-4">No schedules found.</td>
              </tr>
              <?php else: foreach ($rows as $r):
                $rowCount = count_schedule_rows($r['plan_json']); ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= (int)$r['user_id'] ?></td>
                  <td><?= h($r['cohort_code']) ?></td>
                  <td><?= h($r['course_code']) ?></td>
                  <td><?= h($r['module_code']) ?></td>
                  <td><?= h($r['learning_mode']) ?></td>
                  <td class="text-truncate" style="max-width:340px"><?= h($r['course_title']) ?></td>
                  <td><?= (int)$rowCount ?></td>
                  <td><?= h($r['created_at']) ?></td>
                  <td><?= h($r['last_updated']) ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-primary" href="schedule_view.php?id=<?= $r['id'] ?>">View</a>
                  </td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="card-body">
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <?php
              // keep query params except page
              $qs = $_GET;
              unset($qs['page']);
              $qsBase = http_build_query($qs);
              $mk = fn($p) => '?' . $qsBase . '&page=' . $p . '&limit=' . $limit;
              ?>
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $mk(max(1, $page - 1)) ?>">&laquo;</a></li>
              <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p == $page ? 'active' : '' ?>"><a class="page-link" href="<?= $mk($p) ?>"><?= $p ?></a></li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $mk(min($pages, $page + 1)) ?>">&raquo;</a></li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
    </div>

  </div>
</body>

</html>