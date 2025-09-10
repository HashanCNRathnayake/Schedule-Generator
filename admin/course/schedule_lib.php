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
function csv_to_rows($tmpPath): array
{
    $rows = [];
    if (($fh = fopen($tmpPath, 'r')) === false) return $rows;

    $headerFound = false;
    while (($cols = fgetcsv($fh)) !== false) {
        $cols = array_map('trim', $cols);

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
                    $headerFound = true;
                }
            }
            continue;
        }

        $c0 = $cols[0] ?? '';
        $c1 = $cols[1] ?? '';
        $c2 = $cols[2] ?? '';
        $c3 = $cols[3] ?? '';
        if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '') continue;
        $rows[] = [$c0, $c1, $c2, $c3];
    }
    fclose($fh);
    return $rows;
}

/* ===== Normalization helpers ===== */
function normalize_session_type($s)
{
    $x = strtolower(trim($s));
    if (in_array($x, ['ms-sync', 'ms sync', 'mssync'])) return 'MS-Sync';
    if (in_array($x, ['ms-async', 'ms async', 'msasync', 'ms-asyn', 'ms-asyn c'])) return 'MS-ASync';
    return $s;
}
function faculty_from_type($type)
{
    $t = normalize_session_type($type);
    return ($t === 'MS-Sync') ? 'Mentor' : (($t === 'MS-ASync') ? 'NA' : '');
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

/* ===== Holidays + schedule (MULTI-COUNTRY) ===== */
function fetch_public_holidays_one($countryCode, $year): array
{
    $url = "https://date.nager.at/api/v3/PublicHolidays/$year/$countryCode";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $http < 200 || $http >= 300) return [];
    $data = json_decode($res, true);
    if (!is_array($data)) return [];
    $set = [];
    foreach ($data as $h) if (!empty($h['date'])) $set[$h['date']] = true;
    return $set;
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
