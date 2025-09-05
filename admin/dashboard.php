<?php
date_default_timezone_set('Asia/Singapore');
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
};

$username = $_SESSION['username'] ?? '';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/navbar.php';

// Inputs
$q    = trim($_GET['q'] ?? '');
$cat  = $_GET['category_id'] ?? '';
$act  = $_GET['active'] ?? '';
$sort = $_GET['sort'] ?? 'last_updated';
$dir  = strtoupper($_GET['dir'] ?? 'DESC');
$dir  = ($dir === 'ASC') ? 'ASC' : 'DESC';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

// Sort whitelist
$columns = [
    'question'       => 'faqs.question',
    'category'       => 'categories.name',
    'active'         => 'faqs.active',
    'last_updated'   => 'faqs.last_updated',
    'person_or_group' => 'faqs.person_or_group'
];
$orderBy = $columns[$sort] ?? 'faqs.last_updated';

// WHERE builder
$where = [];
$types = '';
$vals  = [];

if ($q !== '') {
    $where[] = "(faqs.question LIKE ? OR faqs.answer LIKE ? OR faqs.keywords LIKE ?)";
    $types .= 'sss';
    $wild = "%$q%";
    array_push($vals, $wild, $wild, $wild);
}
if ($cat !== '') {
    $where[] = "faqs.category_id = ?";
    $types .= 'i';
    $vals[] = (int)$cat;
}
if ($act !== '') {
    $where[] = "faqs.active = ?";
    $types .= 's';
    $vals[] = ($act === 'FALSE') ? 'FALSE' : 'TRUE';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count
$countSql = "SELECT COUNT(*) AS c
             FROM faqs JOIN categories ON categories.id = faqs.category_id
             $whereSql";
$stmt = $conn->prepare($countSql);
if ($types !== '') {
    $stmt->bind_param($types, ...$vals);
}
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$pages = max(1, (int)ceil($total / $perPage));

// Data
$listSql = "SELECT faqs.*, categories.name AS category_name, categories.color
            FROM faqs
            JOIN categories ON categories.id = faqs.category_id
            $whereSql
            ORDER BY $orderBy $dir
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$stmt = $conn->prepare($listSql);
if ($types !== '') {
    $stmt->bind_param($types, ...$vals);
}
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($r = $result->fetch_assoc()) $rows[] = $r;
$stmt->close();

// Categories for filter
$catsRes = $conn->query("SELECT id, name FROM categories ORDER BY name");
$cats = [];
while ($c = $catsRes->fetch_assoc()) $cats[] = $c;

function sortLink($key, $label, $currentSort, $currentDir)
{
    $dir = ($currentSort === $key && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $query = $_GET;
    $query['sort'] = $key;
    $query['dir'] = $dir;
    return '<a href="?' . http_build_query($query) . '">' . $label . ($currentSort === $key ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '') . '</a>';
}
?>

<div class="">
    <div class="card shadow-sm">
        <div class="card-header"><strong>FAQs</strong></div>
        <div class="card-body">
            <form class="row g-2 mb-3" method="get">
                <div class="col-md-4">
                    <input name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Search question/answer/keywords">
                </div>
                <div class="col-md-3">
                    <select name="category_id" class="form-select">
                        <option value="">All categories</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ($cat == (string)$c['id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="active" class="form-select">
                        <option value="">Active: All</option>
                        <option value="TRUE" <?= $act === 'TRUE' ? 'selected' : '' ?>>TRUE</option>
                        <option value="FALSE" <?= $act === 'FALSE' ? 'selected' : '' ?>>FALSE</option>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-primary">Apply</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th><?= sortLink('question', 'Question', $sort, $dir) ?></th>
                            <th><?= sortLink('answers', 'Answers', $sort, $dir) ?></th>
                            <th><?= sortLink('category', 'Category', $sort, $dir) ?></th>
                            <th>Keywords</th>
                            <th><?= sortLink('person_or_group', 'Person/Group', $sort, $dir) ?></th>
                            <th><?= sortLink('active', 'Active', $sort, $dir) ?></th>
                            <th><?= sortLink('last_updated', 'Updated', $sort, $dir) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): $colorClass = 'cat-' . htmlspecialchars($r['color']); ?>
                            <tr>
                                <td style="min-width:280px"><?= nl2br(htmlspecialchars($r['question'])) ?></td>
                                <td style="min-width:280px"><?= nl2br(htmlspecialchars($r['answer'])) ?></td>
                                <td><span class="cat-pill <?= $colorClass ?>"><?= htmlspecialchars($r['category_name']) ?></span></td>
                                <td><?= htmlspecialchars($r['keywords'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['person_or_group'] ?? '') ?></td>
                                <td><span class="badge  <?= $r['active'] === 'TRUE' ? 'cat-pill cat-green' : 'cat-pill cat-red' ?>"><?= $r['active'] ?></span></td>
                                <td><?= htmlspecialchars($r['last_updated']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No records.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $pages; $i++): $q2 = $_GET;
                            $q2['page'] = $i; ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query($q2) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>

</html>