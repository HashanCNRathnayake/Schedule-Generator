<?php
// admin/lib/schedule_lib.php
require_once __DIR__ . '/holiday_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function isPost($k)
{
    return isset($_POST[$k]);
}
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ===== CSV helpers ===== */
// function csv_to_rows($tmpPath): array
// {
//     $rows = [];
//     if (($fh = fopen($tmpPath, 'r')) === false) return $rows;

//     $headerFound = false;
//     while (($cols = fgetcsv($fh)) !== false) {
//         $cols = array_map('trim', $cols);

//         if (!$headerFound) {
//             if (isset($cols[0], $cols[1], $cols[2], $cols[3])) {
//                 $c0 = strtolower($cols[0]);
//                 $c1 = strtolower($cols[1]);
//                 $c2 = strtolower($cols[2]);
//                 $c3 = strtolower($cols[3]);
//                 if (
//                     str_contains($c0, 'session') &&
//                     str_contains($c1, 'type') &&
//                     str_contains($c2, 'details') &&
//                     (str_contains($c3, 'duration') || str_contains($c3, 'hrs'))
//                 ) {
//                     $headerFound = true;
//                 }
//             }
//             continue;
//         }

//         $c0 = $cols[0] ?? '';
//         $c1 = $cols[1] ?? '';
//         $c2 = $cols[2] ?? '';
//         $c3 = $cols[3] ?? '';
//         if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '') continue;
//         $rows[] = [$c0, $c1, $c2, $c3];
//     }
//     fclose($fh);
//     return $rows;
// }

function csv_to_rows($tmpPath): array
{
    $rows = [];
    if (($fh = fopen($tmpPath, 'r')) === false) return $rows;

    $headerFound = false;
    $classIdx = -1; // will hold the index of "Class Type" once we see the header

    while (($cols = fgetcsv($fh, 0, ',', '"', "\\")) !== false) {
        $cols = array_map('trim', $cols);

        // Find the header row and map the "Class Type" column by name
        if (!$headerFound) {
            if (isset($cols[0], $cols[1], $cols[2], $cols[3])) {
                $c0 = strtolower($cols[0]);
                $c1 = strtolower($cols[1]);
                $c2 = strtolower($cols[2]);
                $c3 = strtolower($cols[3]);

                if (
                    str_contains($c0, 'session') &&
                    str_contains($c1, 'type') &&
                    str_contains($c2, 'details') &&
                    (str_contains($c3, 'duration') || str_contains($c3, 'hrs'))
                ) {
                    // locate "Class Type" header anywhere in this row (e.g., column N)
                    foreach ($cols as $i => $h) {
                        $h = strtolower($h);
                        if (str_contains($h, 'class') && str_contains($h, 'type')) {
                            $classIdx = $i;
                            break;
                        }
                    }
                    $headerFound = true;
                    continue; // skip the header row itself
                }
            }
            // still above the table → ignore this row
            continue;
        }

        // After header: read row data
        $c0 = $cols[0] ?? '';
        $c1 = $cols[1] ?? '';
        // Clean details *robustly* and never return empty due to bullets
        $rawDetails = $cols[2] ?? '';
        $cleaned    = clean_session_details($rawDetails);
        // If cleaning somehow empties the string while the raw had content, keep a minimal fallback
        if ($cleaned === '' && $rawDetails !== '') {
            $cleaned = trim(preg_replace('/\?+/', ' ', fix_encoding($rawDetails)));
        }
        $c2 = $cleaned;
        $c3 = $cols[3] ?? '';
        $c4 = ($classIdx >= 0 && isset($cols[$classIdx])) ? $cols[$classIdx] : ''; // Class Type (may be empty if not present)

        // skip completely empty lines
        if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '' && $c4 === '') continue;

        // Return five values (adds Class Type as the 5th)
        $rows[] = [$c0, $c1, $c2, $c3, $c4];
    }

    fclose($fh);
    return $rows;
}


/* ===== Normalization helpers ===== */
function normalize_session_type($s)
{
    $x = trim(mb_strtolower((string)$s));
    // normalize weird dashes/spaces
    $x = str_replace(['–', '—', '  '], ['-', '-', ' '], $x);
    $x = preg_replace('/\s+/', '', $x); // e.g., "MS Sync" -> "mssync"

    // Async first (anything ending with -async)
    if (str_contains($x, 'async')) {
        if (str_starts_with($x, 'el')) return 'EL-Async';
        if (str_starts_with($x, 'ms')) return 'MS-Async';
        return 'Async';
    }

    if (str_contains($x, 'aync')) {
        if (str_starts_with($x, 'el')) return 'EL-Async';
        if (str_starts_with($x, 'ms')) return 'MS-Async';
        return 'Async';
    }

    // Sync cases
    if (str_contains($x, 'sync')) {
        if (str_starts_with($x, 'fc')) return 'FC-Sync';
        if (str_starts_with($x, 'ms')) return 'MS-Sync';
        if (str_starts_with($x, 'sa')) return 'SA-Sync';
        return 'Sync';
    }

    // fallback to original
    return $s;
}
function faculty_from_type($type)
{
    $t = normalize_session_type($type);

    // Your mapping:
    // - any *Async*  => NA
    // - FC-Sync      => Instructor
    // - MS-Sync      => Mentor
    // if (str_ends_with($t, 'Async') || stripos($t, 'Async') !== false) {
    //     return 'NA';
    // }
    if (
        str_ends_with($t, 'Async') || stripos($t, 'Async') !== false
        || str_ends_with($t, 'Aync') || stripos($t, 'Aync') !== false
    ) {
        return 'NA';
    }

    if ($t === 'FC-Sync') return 'Instructor';
    if ($t === 'MS-Sync') return 'Mentor';
    if ($t === 'SA-Sync') return 'Assessor';
    return ''; // unknown/other
}

function fix_encoding(string $s): string
{
    // Try to detect and convert to UTF-8 (common CSVs are cp1252/ISO-8859-1)
    $enc = mb_detect_encoding($s, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252'], true);
    if ($enc && $enc !== 'UTF-8') {
        $s = mb_convert_encoding($s, 'UTF-8', $enc);
    }
    // Normalize NBSP to regular space
    $s = str_replace("\xC2\xA0", ' ', $s);
    return $s;
}

function clean_session_details(string $s): string
{
    // 0) Normalize encoding first
    $orig = $s = fix_encoding($s);

    // 1) Convert *actual* bullet characters to a neutral separator
    //    (middle dot, bullet, dot operator, katakana middle dot, etc.)
    $s = str_replace(
        ["\xC2\xB7", '·', '•', '∙', '‧', '・', '●'],
        ' - ',
        $s
    );

    // 2) Many exports turned bullets into a literal " ? " token.
    //    Replace only a *standalone* question mark between spaces with a separator.
    //    Examples matched: " ? ", " ?  ", "  ? ", leading "? " and " ?," but NOT "Why?"
    $s = preg_replace('/(?<=\s)\?(?=\s|,|;|:)/u', ' - ', $s);  // in between words
    $s = preg_replace('/^(?:\?+\s+)/u', ' - ', $s);            // at start: "? Text" -> " - Text"

    // 3) Collapse doubles
    $s = preg_replace('/\s{2,}/u', ' ', $s);
    $s = preg_replace('/(\s-\s){2,}/u', ' - ', $s);

    // --- turn " - " separators into multiline bullets ---
    // e.g. "- A - B - C"  ->  "- A\n- B\n- C"
    $parts = array_filter(array_map('trim', preg_split('/\s-\s/u', $s)));
    if (count($parts) > 1) {
        $s = '- ' . implode("\n- ", $parts);
    }

    // 4) Trim spaces (but do not over-trim dashes away if that would empty the string)
    $s = trim($s);

    // 5) Safety net: if we somehow removed everything, fall back to a simple
    //    replacement of "?" with a space on the original
    if ($s === '') {
        $s = trim(preg_replace('/\?+/', ' ', $orig));
    }

    return $s;
}

function weekday_name(DateTime $d)
{
    return $d->format('D');
}
function ymd(DateTime $d)
{
    return $d->format('Y-m-d');
}

/* ===== Courses API sync into `courses` ===== */
function upsert_courses_from_api(mysqli $conn): array
{
    $url = "https://ce.educlaas.com/product-app/views/courses/api/claas-ai-app/admin/get-course-information";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $http < 200 || $http >= 300) {
        return ['ok' => false, 'msg' => "API error ($http): " . ($err ?: 'unexpected response')];
    }
    $json = json_decode($res, true);
    if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
        return ['ok' => false, 'msg' => 'API payload missing or malformed'];
    }

    $stmt = $conn->prepare("
        INSERT INTO courses (course_id, course_code, course_title_external)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE
          course_code = VALUES(course_code),
          course_title_external = VALUES(course_title_external)
    ");
    if (!$stmt) return ['ok' => false, 'msg' => 'DB prepare failed: ' . $conn->error];

    $count = 0;
    foreach ($json['data'] as $row) {
        $course_id  = (string)($row['course_id'] ?? '');
        $course_code = (string)($row['course_code'] ?? '');
        $title_ext   = (string)($row['course_title_external'] ?? '');
        if ($course_id === '' || $course_code === '' || $title_ext === '') continue;
        $stmt->bind_param("sss", $course_id, $course_code, $title_ext);
        if ($stmt->execute()) $count++;
    }
    $stmt->close();
    return ['ok' => true, 'msg' => "Courses saved/updated: $count", 'count' => $count];
}

/**
 * @param array $rows          CSV first 4 columns (for count)
 * @param string $startDate    'Y-m-d'
 * @param array $allowedDays   e.g. ['Mon','Wed','Fri']
 * @param array $countries     e.g. ['LK','IN']  (multi-select supported)
 * @return DateTime[]          dates for each row (length ~ rows)
 */
function generate_schedule(array $grid, string $startDateYmd, array $days, array $countries): array
{
    // Map 3-letter day names to indices
    $dayIdx = array_flip(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']);
    $wantDays = array_values(array_filter(
        array_map(fn($d) => $dayIdx[$d] ?? -1, $days),
        fn($i) => $i >= 0
    ));

    // Horizon: enough to place all sessions
    $from = new DateTime($startDateYmd);
    $to   = (clone $from)->modify('+400 days');

    // Union of holiday dates (current year only, cached)
    global $conn; // uses your mysqli from db.php
    $holSet = holiday_set_between($conn, $countries, $from->format('Y-m-d'), $to->format('Y-m-d'));

    $dates = [];
    $iRow  = 0;
    $cursor = clone $from;

    while ($iRow < count($grid)) {
        $dow = (int)$cursor->format('w'); // 0=Sun..6=Sat
        if (!in_array($dow, $wantDays, true)) {
            $cursor->modify('+1 day');
            continue;
        }

        $ymd = $cursor->format('Y-m-d');
        if (isset($holSet[$ymd])) { // skip holiday (if in any selected country)
            $cursor->modify('+1 day');
            continue;
        }

        $dates[$iRow] = clone $cursor; // return a DateTime object to match ymd() & weekday_name()
        $iRow++;
        $cursor->modify('+1 day');
    }

    return $dates;
}

/**
 * Convert ['Mon','Wed','Fri'] to weekday indices [1,3,5]
 */
function want_day_indices(array $days): array
{
    $dayIdx = array_flip(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']); // Sun=0..Sat=6
    return array_values(array_filter(
        array_map(fn($d) => $dayIdx[$d] ?? -1, $days),
        fn($i) => $i >= 0
    ));
}

/**
 * Find the next valid date (weekday in $wantDays and not in $holSet).
 * If $inclusive is false, starts checking from (cursor + 1 day).
 */
function next_valid_date(DateTime $cursor, array $wantDays, array $holSet, bool $inclusive = false): DateTime
{
    $d = clone $cursor;
    if (!$inclusive) $d->modify('+1 day');

    while (true) {
        $dow = (int)$d->format('w'); // 0..6
        if (in_array($dow, $wantDays, true)) {
            $ymd = $d->format('Y-m-d');
            if (!isset($holSet[$ymd])) {
                return clone $d;
            }
        }
        $d->modify('+1 day');
    }
}

/**
 * Recompute dates for rows AFTER $startIndex, starting from $newDateYmd.
 * Returns ['index' => 'Y-m-d', ...] for indices ($startIndex+1 ... end)
 */
function reflow_following(array $grid, int $startIndex, string $newDateYmd, array $days, array $countries): array
{
    global $conn;

    $count = count($grid);
    if ($startIndex < -1) $startIndex = -1;
    if ($startIndex >= $count) return [];

    $wantDays = want_day_indices($days);
    $from     = new DateTime($newDateYmd);
    $to       = (clone $from)->modify('+400 days');
    $holSet   = holiday_set_between($conn, $countries, $from->format('Y-m-d'), $to->format('Y-m-d'));

    $updates = [];
    $cursor  = new DateTime($newDateYmd); // base = edited row's new date

    for ($j = $startIndex + 1; $j < $count; $j++) {
        $cursor = next_valid_date($cursor, $wantDays, $holSet, false); // strictly after previous
        $updates[$j] = $cursor->format('Y-m-d');
    }

    return $updates;
}
