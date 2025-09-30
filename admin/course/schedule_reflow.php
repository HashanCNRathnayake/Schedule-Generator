<?php
// schedule_reflow.php
declare(strict_types=1);
session_start();

// Never echo PHP warnings/notices to the client — they break JSON
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    // --- Includes (paths are correct from /admin/course/) ---
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../../db.php';
    require_once __DIR__ . '/../../auth/guard.php';
    require_once __DIR__ . '/schedule_lib.php'; // must define generate_schedule(), weekday_name(), ymd(), etc.

    // --- Read POST ---
    $moduleCode = trim($_POST['module_code'] ?? '');
    $rowIndex   = isset($_POST['row_index']) ? (int)$_POST['row_index'] : -1;
    $newDateIso = trim($_POST['new_date_iso'] ?? '');

    if ($moduleCode === '' || $rowIndex < 0 || $newDateIso === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing module_code / row_index / new_date_iso']);
        exit;
    }

    // --- Pull session state ---
    $meta       = $_SESSION['meta']        ?? null;
    $grid       = $_SESSION['grid_rows']   ?? null;   // templates: [mod => [[no,type,details,dur],...]]
    $generated  = $_SESSION['generated']   ?? null;   // generated rows for display
    if (!$meta || !$grid || !$generated || empty($grid[$moduleCode]) || empty($generated[$moduleCode])) {
        echo json_encode(['ok' => false, 'error' => 'No session data available to reflow. Re-generate first.']);
        exit;
    }

    // --- Meta & inputs ---
    $days        = (array)($meta['days'] ?? []);
    $countries   = (array)($meta['countries'] ?? []);
    $breakDays   = (int)  ($meta['break_days'] ?? 0);
    $customStart = trim($meta['custom_start'] ?? '');
    $customEnd   = trim($meta['custom_end']   ?? '');
    $multiAllowed = !empty($meta['multi_allowed']);
    $socIso      = trim($meta['soc'] ?? $meta['start_date'] ?? '');
    $eocIso      = trim($meta['eoc'] ?? $meta['end_date']   ?? '');

    if ($socIso === '' || $eocIso === '' || empty($days) || empty($countries)) {
        echo json_encode(['ok' => false, 'error' => 'Meta missing (SOC/EOC/days/countries). Re-generate first.']);
        exit;
    }

    $soc = new DateTime($socIso);
    $eoc = new DateTime($eocIso);

    $templates   = $grid[$moduleCode];       // [[no,type,details,dur], ...]
    $currentRows = $generated[$moduleCode];  // [{no,type,details,...,date,day,time}, ...]

    if (!isset($templates[$rowIndex])) {
        echo json_encode(['ok' => false, 'error' => 'row_index out of range for this module']);
        exit;
    }

    // --- Helpers ---
    $isAsync = function (string $type): bool {
        return (bool)preg_match('/async/i', $type);
    };
    $isSync = function (string $type) use ($isAsync): bool {
        if ($isAsync($type)) return false;
        return (bool)preg_match('/\bsync\b/i', $type) || (bool)preg_match('/-sync/i', $type);
    };
    $ddmmyyyy = function (DateTime $d): string {
        return $d->format('d/m/Y');
    };
    // Next valid ON or AFTER anchor
    $nextValidOnOrAfter = function (DateTime $anchor) use ($days, $countries) {
        $probe = [[1, '', '', '']];
        $got = generate_schedule($probe, $anchor->format('Y-m-d'), $days, $countries);
        return $got[0] ?? null; // DateTime|null
    };
    // Next valid STRICTLY AFTER anchor
    $nextValidAfter = function (DateTime $anchor) use ($days, $countries) {
        $probe = [[1, '', '', '']];
        $got = generate_schedule($probe, $anchor->modify('+1 day')->format('Y-m-d'), $days, $countries);
        return $got[0] ?? null;
    };

    // --- Pin the edited row date (move it to the next valid day on/after user input) ---
    $requested = new DateTime($newDateIso);
    if ($requested < $soc) {
        $requested = clone $soc;
    }

    $pin = $nextValidOnOrAfter(clone $requested);
    if (!$pin || $pin > $eoc) {
        // Nothing can be scheduled at or after this date
        // Blank everything from rowIndex onward
        for ($i = $rowIndex; $i < count($currentRows); $i++) {
            $currentRows[$i]['date'] = '';
            $currentRows[$i]['day']  = '';
            $currentRows[$i]['time'] = '';
        }
        // Persist & return this module only
        $_SESSION['generated'][$moduleCode] = $currentRows;

        $respRows = [];
        foreach ($currentRows as $r) {
            $iso = trim((string)($r['date'] ?? ''));
            $respRows[] = [
                'date'     => $iso ? (new DateTime($iso))->format('d/m/Y') : '',
                'date_iso' => $iso,
                'day'      => $r['day'] ?? '',
                'time'     => $r['time'] ?? '',
            ];
        }
        echo json_encode(['ok' => true, 'rows' => $respRows]);
        exit;
    }

    // --- Recompute ONLY this module from the edited row downward ---
    // Keep rows before rowIndex as-is.
    $lastAssigned = null;
    // Determine anchor for the edited row:
    if ($rowIndex > 0) {
        // Use the last actual date before the edited row if present
        for ($j = $rowIndex - 1; $j >= 0; $j--) {
            $iso = trim((string)($currentRows[$j]['date'] ?? ''));
            if ($iso !== '') {
                $lastAssigned = new DateTime($iso);
                break;
            }
        }
    }

    // Now assign from rowIndex..end
    $recalc = $currentRows; // copy
    $i = $rowIndex;

    while ($i < count($recalc)) {
        $typeThis = (string)($recalc[$i]['type'] ?? '');
        $canPair  = false;

        if ($multiAllowed && $isAsync($typeThis) && ($i + 1) < count($recalc)) {
            $typeNext = (string)($recalc[$i + 1]['type'] ?? '');
            if ($isSync($typeNext)) $canPair = true;
        }

        if ($i === $rowIndex) {
            // Edited row is forced to $pin; if pair, assign next row to same date
            $use = clone $pin;
            if ($use > $eoc) {
                $use = clone $eoc;
            }

            // Fill row i
            $recalc[$i]['date'] = ymd($use);
            $recalc[$i]['day']  = weekday_name($use);
            $recalc[$i]['time'] = $isAsync($typeThis)
                ? 'Self-Paced Before Sync Session'
                : (($customStart && $customEnd) ? ($customStart . ' - ' . $customEnd) : '19:00 - 22:00');

            $lastAssigned = clone $use;

            if ($canPair) {
                // row i+1 same date as pin
                $typeNext = (string)($recalc[$i + 1]['type'] ?? '');
                $recalc[$i + 1]['date'] = ymd($use);
                $recalc[$i + 1]['day']  = weekday_name($use);
                $recalc[$i + 1]['time'] = ($customStart && $customEnd) ? ($customStart . ' - ' . $customEnd) : '19:00 - 22:00';
                $lastAssigned = clone $use;
                $i += 2;
                continue;
            }

            $i += 1;
            continue;
        }

        // After the edited row: strictly-after lastAssigned
        if (!$lastAssigned) {
            // If nothing is assigned yet (shouldn’t happen after edited row), start at SOC
            $anchor = clone $soc;
            $next = $nextValidOnOrAfter($anchor);
        } else {
            $anchor = clone $lastAssigned;
            $next = $nextValidAfter($anchor);
        }

        if (!$next || $next > $eoc) {
            // No more slots → blanks from here onward
            for (; $i < count($recalc); $i++) {
                $recalc[$i]['date'] = '';
                $recalc[$i]['day']  = '';
                $recalc[$i]['time'] = '';
            }
            break;
        }

        // If this is an async and next is sync and multiAllowed → put both on $next
        $typeThis = (string)($recalc[$i]['type'] ?? '');
        $canPairNow = $multiAllowed && $isAsync($typeThis) && ($i + 1) < count($recalc) && $isSync((string)$recalc[$i + 1]['type']);

        if ($canPairNow) {
            $recalc[$i]['date'] = ymd($next);
            $recalc[$i]['day']  = weekday_name($next);
            $recalc[$i]['time'] = 'Self-Paced Before Sync Session';

            $recalc[$i + 1]['date'] = ymd($next);
            $recalc[$i + 1]['day']  = weekday_name($next);
            $recalc[$i + 1]['time'] = ($customStart && $customEnd) ? ($customStart . ' - ' . $customEnd) : '19:00 - 22:00';

            $lastAssigned = clone $next;
            $i += 2;
            continue;
        }

        // single assignment
        $recalc[$i]['date'] = ymd($next);
        $recalc[$i]['day']  = weekday_name($next);
        $recalc[$i]['time'] = $isAsync($typeThis)
            ? 'Self-Paced Before Sync Session'
            : (($customStart && $customEnd) ? ($customStart . ' - ' . $customEnd) : '19:00 - 22:00');

        $lastAssigned = clone $next;
        $i += 1;
    }

    // Save back to session for this module (other modules kept as-is here)
    $_SESSION['generated'][$moduleCode] = $recalc;

    // Build response rows for this module (dd/mm/yyyy + iso)
    $respRows = [];
    foreach ($recalc as $r) {
        $iso = trim((string)($r['date'] ?? ''));
        $respRows[] = [
            'date'     => $iso ? (new DateTime($iso))->format('d/m/Y') : '',
            'date_iso' => $iso,
            'day'      => $r['day']  ?? '',
            'time'     => $r['time'] ?? '',
        ];
    }

    echo json_encode(['ok' => true, 'rows' => $respRows]);
    exit;
} catch (Throwable $e) {
    // Final safety net: always return JSON
    echo json_encode([
        'ok' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
}
