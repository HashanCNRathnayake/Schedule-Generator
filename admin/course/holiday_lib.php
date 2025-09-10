<?php
// admin/course/holiday_lib.php

// ---- ICS Calendar IDs for your countries (free, no key) ----
// Source list of Google "Regional holidays" calendar IDs is public. :contentReference[oaicite:1]{index=1}
const HOL_CAL_IDS = [
    'LK' => 'en.lk.official#holiday@group.v.calendar.google.com',          // Sri Lanka
    'IN' => 'en.indian.official#holiday@group.v.calendar.google.com',      // India
    'SG' => 'en.singapore.official#holiday@group.v.calendar.google.com',   // Singapore
    'BD' => 'en.bd.official#holiday@group.v.calendar.google.com',          // Bangladesh
    'MM' => 'en.mm.official#holiday@group.v.calendar.google.com',          // Myanmar (Burma)
    'PH' => 'en.philippines.official#holiday@group.v.calendar.google.com', // Philippines
    'MY' => 'en.malaysia.official#holiday@group.v.calendar.google.com',    // Malaysia
];

// ---- PUBLIC: get a set like ['2025-09-10' => true, ...] unioned across countries ----
function holiday_set_between(mysqli $conn, array $countries, string $fromYmd, string $toYmd): array
{
    $year = (int)date('Y'); // "current year only" as requested

    // Ensure cache for current year only (if table contains another year or empty -> purge & refill)
    ensure_current_year_cache($conn, $countries, $year);

    // Pull union of holiday dates (still only current year in the table)
    $in = implode(',', array_fill(0, count($countries), '?'));
    $types = str_repeat('s', count($countries)) . 'ss';
    $sql = "SELECT DISTINCT hdate FROM public_holidays
          WHERE country_code IN ($in)
            AND hdate BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $params = array_merge($countries, [$fromYmd, $toYmd]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $set = [];
    while ($row = $res->fetch_assoc()) {
        $set[$row['hdate']] = true;
    }
    $stmt->close();
    return $set;
}

// ---- Cache management (current year only) ----
function ensure_current_year_cache(mysqli $conn, array $countries, int $year): void
{
    $curYearHasRows = year_exists($conn, $year);

    if (!$curYearHasRows) {
        // New year (or empty table): wipe everything and refill for the requested countries
        $conn->query("TRUNCATE TABLE public_holidays");
        foreach ($countries as $cc) {
            $rows = fetch_holidays_ics($cc, $year);
            if ($rows) upsert_holidays($conn, $rows);
        }
        return;
    }

    // If any rows from other years exist, remove them
    $stmt = $conn->prepare("DELETE FROM public_holidays WHERE YEAR(hdate) <> ?");
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $stmt->close();

    // Ensure every requested country has current-year rows; if missing, fetch & insert
    foreach ($countries as $cc) {
        if (!country_year_exists($conn, $cc, $year)) {
            $rows = fetch_holidays_ics($cc, $year);
            if ($rows) upsert_holidays($conn, $rows);
        }
    }
}

function year_exists(mysqli $conn, int $year): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM public_holidays WHERE YEAR(hdate)=? LIMIT 1");
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function country_year_exists(mysqli $conn, string $cc, int $year): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM public_holidays WHERE country_code=? AND YEAR(hdate)=? LIMIT 1");
    $stmt->bind_param('si', $cc, $year);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

// ---- Fetch from Google ICS (no API key) ----
// Full ICS URL pattern (need url-encoding for '#' and '@'):
// https://calendar.google.com/calendar/ical/{urlencode(CAL_ID)}/public/basic.ics
// Example encoding: '#' -> %23, '@' -> %40. :contentReference[oaicite:2]{index=2}
function fetch_holidays_ics(string $cc, int $year): array
{
    $calId = HOL_CAL_IDS[$cc] ?? null;
    if (!$calId) return [];
    $icsUrl = "https://calendar.google.com/calendar/ical/" . rawurlencode($calId) . "/public/basic.ics";
    $ics = @file_get_contents($icsUrl);
    if ($ics === false) return [];

    $events = parse_ics_allday_events($ics);
    $out = [];
    foreach ($events as $e) {
        $d = $e['date'];                 // YYYY-MM-DD
        if ((int)substr($d, 0, 4) !== $year) continue; // keep only current year
        $out[] = [
            'country_code' => $cc,
            'hdate'        => $d,
            'name'         => $e['summary'] ?? 'Holiday',
            'source'       => 'GOOGLE_ICS',
        ];
    }
    return $out;
}

// Parse all-day ICS entries into [['date'=>'YYYY-MM-DD', 'summary'=>'...'], ...]
function parse_ics_allday_events(string $ics): array
{
    // Unfold folded lines
    $ics = preg_replace("/\r\n[ \t]/", '', $ics);
    $lines = preg_split("/\r\n|\n|\r/", $ics);

    $ev = null;
    $out = [];
    foreach ($lines as $line) {
        if (strncmp($line, 'BEGIN:VEVENT', 12) === 0) {
            $ev = [];
        } elseif (strncmp($line, 'END:VEVENT', 10) === 0) {
            if (!empty($ev['date'])) $out[] = $ev;
            $ev = null;
        } elseif ($ev !== null) {
            if (strpos($line, 'DTSTART') === 0) {
                if (preg_match('/DTSTART(?:;[^:]+)?:([0-9]{8})/', $line, $m)) {
                    $yyyymmdd = $m[1];
                    $ev['date'] = substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
                }
            } elseif (strpos($line, 'SUMMARY:') === 0) {
                $ev['summary'] = substr($line, 8);
            }
        }
    }
    return $out;
}

function upsert_holidays(mysqli $conn, array $rows): void
{
    if (!$rows) return;
    $sql = "INSERT INTO public_holidays (country_code, hdate, name, source)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE name=VALUES(name), source=VALUES(source)";
    $stmt = $conn->prepare($sql);
    foreach ($rows as $r) {
        $stmt->bind_param('ssss', $r['country_code'], $r['hdate'], $r['name'], $r['source']);
        $stmt->execute();
    }
    $stmt->close();
}
