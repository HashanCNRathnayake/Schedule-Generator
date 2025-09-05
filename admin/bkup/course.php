<?php

declare(strict_types=1);

const COURSE_LIST_URL   = 'https://ce.educlaas.com/product-app/views/courses/api/claas-ai-app/admin/get-course-information';
const COURSE_DETAIL_URL = 'https://ce.educlaas.com/product-app/views/courses/api/claas-ai-app/admin/get-course-details?id=';

// ---------------- HTTP helper ----------------
function http_get_json(string $url, int $timeout = 20): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: CourseBrowser/1.1 (+PHP)',
            ],
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("HTTP request failed: $err");
        if ($code < 200 || $code >= 300) throw new RuntimeException("Unexpected HTTP status: $code");
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'header'  => "Accept: application/json\r\nUser-Agent: CourseBrowser/1.1 (+PHP)\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new RuntimeException('HTTP request failed (file_get_contents).');
    }
    $json = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
    }
    return $json;
}

// ---------------- Rendering helpers ----------------
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_data(mixed $data, string $path = ''): string
{
    if ($data === null) return '<span class="text-muted">null</span>';
    if (is_scalar($data)) return '<code>' . e((string)$data) . '</code>';

    if (is_array($data)) {
        $isAssoc = array_keys($data) !== range(0, count($data) - 1);
        if ($isAssoc) {
            $out = '<div class="ms-2 border-start ps-3">';
            foreach ($data as $key => $val) {
                $sectionId = 'sec_' . md5($path . '.' . (string)$key . serialize($val));
                $label = e((string)$key);
                if (is_array($val)) {
                    $out .= <<<HTML
<div class="mb-2">
  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#{$sectionId}">
    {$label}
  </button>
  <div id="{$sectionId}" class="collapse show mt-2">
    {rendered}
  </div>
</div>
HTML;
                    $out = str_replace('{rendered}', render_data($val, $path . '.' . $key), $out);
                } else {
                    $out .= '<div class="d-flex gap-2 mb-1"><span class="fw-semibold">'
                        . $label . ':</span> ' . render_data($val, $path . '.' . $key) . '</div>';
                }
            }
            $out .= '</div>';
            return $out;
        } else {
            $out = '<ol class="ms-4">';
            foreach ($data as $idx => $val) {
                $out .= '<li class="mb-2">' . render_data($val, $path . '[' . $idx . ']') . '</li>';
            }
            $out .= '</ol>';
            return $out;
        }
    }
    if (is_object($data)) return render_data((array)$data, $path);
    return '<span class="text-muted">[unrenderable]</span>';
}

function pretty_json(mixed $data): string
{
    return e(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ---------------- Controller ----------------
$id        = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$mode      = $id === '' ? 'list' : 'detail';
$error     = null;
$listResp  = [];
$detailResp = [];

try {
    if ($mode === 'list') {
        $listResp = http_get_json(COURSE_LIST_URL);
    } else {
        $detailResp = http_get_json(COURSE_DETAIL_URL . urlencode($id));
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

// For List API: gather rows and union of keys
$listRows = [];
$listKeys = [];
if ($mode === 'list' && !$error) {
    $listRows = $listResp['data'] ?? [];
    if (!is_array($listRows)) $listRows = [];
    foreach ($listRows as $row) {
        if (is_array($row)) {
            foreach (array_keys($row) as $k) {
                $listKeys[$k] = true;
            }
        }
    }
    $listKeys = array_keys($listKeys);
    sort($listKeys);
}

// Prepare details payload (the actual course object/array) + raw
$detailsPayload = $detailResp;
if ($mode === 'detail' && isset($detailResp['data']) && (is_array($detailResp['data']) || is_object($detailResp['data']))) {
    $detailsPayload = $detailResp['data'];
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Course API Explorer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #0b0f14;
            color: #e8edf2;
        }

        .card {
            background: #111827;
            border-color: #1f2937;
            color: #e8edf2;

        }

        code,
        pre {
            color: #93c5fd;
        }

        .border-start {
            border-color: #334155 !important;
        }

        .badge-key {
            background: #1f2937;
            border: 1px solid #334155;
            color: #e5e7eb;
        }
    </style>
</head>

<body>
    <div class="container py-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h1 class="h4 m-0">Course API Explorer</h1>
            <?php if ($mode === 'detail'): ?>
                <a class="btn btn-secondary btn-sm" href="<?= e($_SERVER['PHP_SELF']) ?>">← Back to Course List</a>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($mode === 'list' && !$error): ?>
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-end justify-content-between mb-2">
                                <h2 class="h5 m-0">Available Courses (List API)</h2>
                                <small class="text-muted">Source: Get Course List API</small>
                            </div>

                            <?php if (empty($listRows)): ?>
                                <div class="text-muted">No courses found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th>Course Code</th>
                                                <th>External Title</th>
                                                <th>Internal Title</th>
                                                <th>Type</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($listRows as $i => $row): ?>
                                                <?php
                                                $courseId = $row['course_id'] ?? '';
                                                $code     = $row['course_code'] ?? '';
                                                $ext      = $row['course_title_external'] ?? '';
                                                $int      = $row['course_title_internal'] ?? '';
                                                $type     = $row['type_of_course'] ?? '';
                                                $detailUrl = e($_SERVER['PHP_SELF']) . '?id=' . urlencode((string)$courseId);
                                                $collapseId = 'row_all_' . $i;
                                                ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= e($code) ?></td>
                                                    <td><?= e($ext) ?></td>
                                                    <td><?= e($int) ?></td>
                                                    <td><span class="badge text-bg-info"><?= e($type) ?></span></td>
                                                    <td class="d-flex gap-2">
                                                        <a class="btn btn-sm btn-primary" href="<?= $detailUrl ?>">View Details</a>
                                                        <button class="btn btn-sm btn-outline-secondary"
                                                            type="button"
                                                            data-bs-toggle="collapse"
                                                            data-bs-target="#<?= $collapseId ?>">
                                                            All fields
                                                        </button>
                                                    </td>
                                                </tr>
                                                <tr class="collapse" id="<?= $collapseId ?>">
                                                    <td colspan="5">
                                                        <!-- Render the FULL item from List API -->
                                                        <?= render_data($row, 'list[' . $i . ']') ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Raw JSON for List API -->
                                <details class="mt-3">
                                    <summary class="text-info">Raw JSON (List API)</summary>
                                    <pre class="mt-2 mb-0"><?= pretty_json($listResp) ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="h6">Fields found in List API</h3>
                            <?php if (empty($listKeys)): ?>
                                <div class="text-muted">No keys detected.</div>
                            <?php else: ?>
                                <div class="small text-muted mb-2">(Union of keys across all courses)</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($listKeys as $k): ?>
                                        <span class="badge badge-key"><?= e($k) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'detail' && !$error): ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-end justify-content-between mb-2">
                        <h2 class="h5 m-0">Course Details</h2>
                        <small class="text-muted">Source: Get Course Details API</small>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted">course_id:</div>
                        <div class="fs-6"><?= e($id) ?></div>
                    </div>

                    <!-- Full schema-agnostic render of the details payload -->
                    <div class="mb-3">
                        <?= render_data($detailsPayload, 'details') ?>
                    </div>

                    <!-- Raw JSON for Details API -->
                    <details class="mt-3">
                        <summary class="text-info">Raw JSON (Details API)</summary>
                        <pre class="mt-2 mb-0"><?= pretty_json($detailResp) ?></pre>
                    </details>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-4">
            <details>
                <summary class="text-info">Developer Notes</summary>
                <ul class="mt-2">
                    <li>Use the standard term: the <strong><code>echo</code> language construct</strong>, not “echo statement”.</li>
                    <li><strong>List API</strong> returns an array of course items under <code>data</code>. We compute the union of property names and show each item’s **full object** in an expander.</li>
                    <li><strong>Details API</strong> is rendered **schema-agnostically**, so you see every nested field (modules, IUs, outcomes, prerequisites, projects, fees, session plans, target audience, job roles, etc.).</li>
                    <li>Use the **Raw JSON** toggles to compare the literal payloads with the rendered view.</li>
                </ul>
            </details>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>