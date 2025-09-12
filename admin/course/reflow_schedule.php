<?php
// admin/course/reflow_schedule.php
session_start();

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../admin/course/schedule_lib.php';

header('Content-Type: application/json');

try {
    $index     = isset($_POST['index']) ? (int)$_POST['index'] : null;
    $newDate   = trim($_POST['new_date'] ?? '');

    $validDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    $sessionDays  = $_SESSION['meta']['days'] ?? [];
    $postDays     = (array)($_POST['days'] ?? []);
    $days = $sessionDays ? array_values(array_intersect($sessionDays, $validDays))
        : array_values(array_intersect($postDays, $validDays));

    $sessionCountries = $_SESSION['meta']['countries'] ?? [];
    $postCountries    = (array)($_POST['countries'] ?? []);
    $countries        = $sessionCountries ?: $postCountries;

    if ($index === null || $newDate === '') {
        echo json_encode(['ok' => false, 'msg' => 'Missing index or new_date']);
        exit;
    }

    // We only need the length; prefer grid_rows (source rows). Fallback to generated.
    $grid = $_SESSION['grid_rows'] ?? null;
    if (!$grid) {
        $gen = $_SESSION['generated'] ?? [];
        $grid = array_fill(0, count($gen), []); // only need count
    }

    if (!$grid) {
        echo json_encode(['ok' => false, 'msg' => 'No rows available to reflow']);
        exit;
    }

    $updates = reflow_following($grid, $index, $newDate, $days, $countries);

    // Build payload with day abbreviations
    $out = [];
    foreach ($updates as $j => $ymd) {
        $d = new DateTime($ymd);
        $out[] = [
            'index' => $j,
            'date'  => $ymd,
            'day'   => $d->format('D')
        ];
    }

    echo json_encode(['ok' => true, 'updates' => $out]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
