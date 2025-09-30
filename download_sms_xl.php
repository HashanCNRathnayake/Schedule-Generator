<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

date_default_timezone_set('Asia/Colombo'); // dates only; times are written as fractions (no TZ)
session_start();
require __DIR__ . '/db.php';

/* ===================== Inputs ===================== */
$rows    = $_POST['rows'] ?? ($_SESSION['generated'] ?? []);
$userId  = (int)($_SESSION['auth']['user_id'] ?? 0);

/* For template fallback */
$courseId     = $_POST['course_id']     ?? ($_SESSION['selected']['course_id']     ?? '');
$courseCode   = $_POST['course_code']   ?? ($_SESSION['selected']['course_code']   ?? '');
$moduleCode   = $_POST['module_code']   ?? ($_SESSION['selected']['module_code']   ?? '');
$learningMode = $_POST['learning_mode'] ?? ($_SESSION['selected']['learning_mode'] ?? '');

$startDate    = $_POST['start_date']    ?? ($_SESSION['meta']['start_date']   ?? ''); // only used if you later want
$customStart  = $_POST['custom_start']  ?? ($_SESSION['meta']['custom_start'] ?? '');
$customEnd    = $_POST['custom_end']    ?? ($_SESSION['meta']['custom_end']   ?? '');

$templateId   = (int)($_POST['template_id'] ?? ($_SESSION['selected']['template_id'] ?? 0));

/* = Optional: export by saved plan id (pull rows/meta/template_id from plan) = */
if (isset($_GET['id'])) {
    $exportId = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM session_plans WHERE id = ?");
    $stmt->bind_param('i', $exportId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rowDb = $res->fetch_assoc();
    $stmt->close();

    if ($rowDb) {
        $plan = json_decode($rowDb['plan_json'] ?? '[]', true) ?: [];
        $meta = $plan['meta'] ?? [];

        $rows         = $plan['rows'] ?? $rows;
        $courseId     = $rowDb['course_id']     ?: ($meta['course_id']     ?? $courseId);
        $courseCode   = $rowDb['course_code']   ?: ($meta['course_code']   ?? $courseCode);
        $moduleCode   = $rowDb['module_code']   ?: ($meta['module_code']   ?? $moduleCode);
        $learningMode = $rowDb['learning_mode'] ?: ($meta['learning_mode'] ?? $learningMode);
        $startDate    = $meta['start_date']     ?? $startDate;
        $customStart  = $meta['time']['custom_start'] ?? $customStart;
        $customEnd    = $meta['time']['custom_end']   ?? $customEnd;
        if (!$templateId) $templateId = (int)($meta['template_id'] ?? 0);
    } else {
        http_response_code(404);
        exit('Schedule not found.');
    }
}

/* ===================== Helpers ===================== */
function ts_from_any($s)
{
    $s = trim((string)$s);
    if ($s === '') return null;
    foreach (['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt && $dt->format($fmt) === $s) return $dt->getTimestamp();
    }
    $ts = strtotime($s);
    return $ts ?: null;
}

/** Use only custom_start/custom_end provided by the user */
function derive_start_end($customStart, $customEnd)
{
    $s = trim((string)$customStart);
    $e = trim((string)$customEnd);
    return [$s !== '' ? $s : null, $e !== '' ? $e : null];
}

/**
 * Convert "19:00", "7 PM", "07:30 pm", "7.30pm", "730pm" -> Excel time fraction (0..1)
 * Returns float|null
 */
function time_to_excel_fraction($t)
{
    $t = trim(strtolower((string)$t));
    if ($t === '') return null;

    // normalize
    $t = preg_replace('/\s+/', ' ', $t);
    $t = str_replace(['–', '—'], '-', $t);
    $t = str_replace('.', ':', $t);

    // handle "730pm" -> "7:30 pm"
    $compact = str_replace(' ', '', $t);
    if (preg_match('/^(\d{1,2})(\d{2})(am|pm)$/', $compact, $m)) {
        $t = $m[1] . ':' . $m[2] . ' ' . $m[3];
    }

    // match "h", "hh:mm", optional am/pm
    if (!preg_match('/^(\d{1,2})(?::(\d{1,2}))?\s*(am|pm)?$/i', $t, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = isset($m[2]) ? (int)$m[2] : 0;
    $ampm = isset($m[3]) ? strtolower($m[3]) : null;

    if ($ampm) {
        if ($h == 12) $h = 0;           // 12am = 00
        if ($ampm === 'pm') $h += 12;   // add 12 for pm
    }
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;

    $seconds = $h * 3600 + $min * 60;
    return $seconds / 86400; // Excel fraction of a day
}

/* ===== Ensure template id; fallback to latest matching keys if missing ===== */
if ($templateId <= 0 && $courseId && $courseCode && $moduleCode && $learningMode) {
    $stmt = $conn->prepare("
        SELECT id FROM session_templates
        WHERE course_id = ? AND course_code = ? AND module_code = ? AND learning_mode = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param('ssss', $courseId, $courseCode, $moduleCode, $learningMode);
    $stmt->execute();
    $stmt->bind_result($foundId);
    if ($stmt->fetch()) $templateId = (int)$foundId;
    $stmt->close();
}

/* ===== Build class_type map (session_no -> class_type) ===== */
$classMap = [];
if ($templateId > 0) {
    $stmt = $conn->prepare("
        SELECT CAST(session_no AS UNSIGNED) AS s_no, COALESCE(class_type,'') AS ct
        FROM session_template_rows
        WHERE template_id = ?
    ");
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $classMap[(int)$row['s_no']] = (string)$row['ct'];
    }
    $stmt->close();
}

/* ===================== Spreadsheet (NO title/meta) ===================== */
$ss = new Spreadsheet();
$ss->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Class Schedule');

$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
    ->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()
    ->setTop(0.39)->setRight(0.39)->setLeft(0.39)->setBottom(0.47);
$sheet->getPageSetup()->setHorizontalCentered(true);

$BLACK  = 'FF000000';
$HEAD   = 'FFF0F0F0';
$YELLOW = 'FFFFF7B2';
$borderThin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $BLACK]]]];

/* ---- Header row (A1..T1) ---- */
$headers = [
    'Class Type',
    'Date',
    'Day',
    'Start Time',
    'End Time',
    'Batch No',
    'Unit Code',
    'Unit Name',
    'Lecturer',
    'Mentor 1',
    'Mentor 2',
    'Mentor 3',
    'Assessor 1',
    'Assessor 2',
    'Internal Verifier',
    'Actual Makeup Date',
    'Actual Makeup Start Time',
    'Actual Makeup End Time',
    'Course Session ID',
    'Module Session ID'
];
$cols = range('A', 'T');
foreach ($cols as $i => $col) $sheet->setCellValue($col . '1', $headers[$i]);
$sheet->getStyle('A1:T1')->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $HEAD]],
] + $borderThin);

/* Column widths */
$widths = [20, 13, 10, 12, 12, 12, 14, 28, 16, 14, 14, 14, 16, 16, 18, 16, 18, 18, 18, 18];
foreach ($cols as $i => $col) $sheet->getColumnDimension($col)->setWidth($widths[$i] ?? 14);

$sheet->freezePane('A2');

/* ---- Data rows (start at row 2) ---- */
list($globalStartTxt, $globalEndTxt) = derive_start_end($customStart, $customEnd);
$globalStartFrac = time_to_excel_fraction($globalStartTxt);
$globalEndFrac   = time_to_excel_fraction($globalEndTxt);

$rIdx = 2;
foreach ($rows as $r) {
    $no      = isset($r['no']) ? (int)trim((string)$r['no']) : 0;
    $dateStr = $r['date'] ?? '';
    $day     = $r['day']  ?? '';

    // A: Class Type (from DB map)
    $sheet->setCellValue("A{$rIdx}", $classMap[$no] ?? '');

    // B: Date (Excel date if parseable)
    $dts = ts_from_any($dateStr);
    if ($dts) {
        $sheet->setCellValue("B{$rIdx}", ExcelDate::PHPToExcel($dts));
        $sheet->getStyle("B{$rIdx}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    } else {
        $sheet->setCellValue("B{$rIdx}", $dateStr);
    }

    // C: Day
    $sheet->setCellValue("C{$rIdx}", $day);

    // D/E: Start/End time (use ONLY custom_start/custom_end; write as Excel fractions)
    if ($globalStartFrac !== null) {
        $sheet->setCellValue("D{$rIdx}", $globalStartFrac);
        $sheet->getStyle("D{$rIdx}")->getNumberFormat()->setFormatCode('hh:mm');
    } else {
        $sheet->setCellValue("D{$rIdx}", $globalStartTxt ?: '');
    }

    if ($globalEndFrac !== null) {
        $sheet->setCellValue("E{$rIdx}", $globalEndFrac);
        $sheet->getStyle("E{$rIdx}")->getNumberFormat()->setFormatCode('hh:mm');
    } else {
        $sheet->setCellValue("E{$rIdx}", $globalEndTxt ?: '');
    }

    // F..T left blank intentionally
    $sheet->getStyle("A{$rIdx}:T{$rIdx}")->applyFromArray($borderThin);
    $sheet->getStyle("A{$rIdx}:E{$rIdx}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("B{$rIdx}:E{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getRowDimension($rIdx)->setRowHeight(18);

    $rIdx++;
}

/* Optional: highlight last row */
if ($rIdx > 2) {
    $last = $rIdx - 1;
    $sheet->getStyle("A{$last}:T{$last}")
        ->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB($YELLOW);
}

/* ---- Output ---- */
$filename = 'class-schedule.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
