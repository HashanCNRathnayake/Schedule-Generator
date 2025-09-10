<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

session_start();
require __DIR__ . '/db.php';


// ---- Pull the same inputs you already use for PDF ----
$rows    = $_POST['rows'] ?? ($_SESSION['generated'] ?? []);
$cohort  = trim($_POST['cohort_code'] ?? ($_SESSION['selected']['cohort_code'] ?? ''));
$userId  = (int)($_SESSION['user_id'] ?? 0);

$courseId     = $_POST['course_id']     ?? ($_SESSION['selected']['course_id']     ?? '');
$courseCode   = $_POST['course_code']   ?? ($_SESSION['selected']['course_code']   ?? '');
$moduleCode   = $_POST['module_code']   ?? ($_SESSION['selected']['module_code']   ?? '');
$learningMode = $_POST['learning_mode'] ?? ($_SESSION['selected']['learning_mode'] ?? '');
$courseTitle  = $_POST['course_title']  ?? ($_SESSION['selected']['course_title']  ?? '');

$startDate    = $_POST['start_date']    ?? ($_SESSION['meta']['start_date']   ?? '');
$days         = $_POST['days']          ?? ($_SESSION['meta']['days']         ?? []);
$countries    = $_POST['countries']     ?? ($_SESSION['meta']['countries']    ?? []);
$timeSlot     = $_POST['time_slot']     ?? ($_SESSION['meta']['time_slot']    ?? '');
$customStart  = $_POST['custom_start']  ?? ($_SESSION['meta']['custom_start'] ?? '');
$customEnd    = $_POST['custom_end']    ?? ($_SESSION['meta']['custom_end']   ?? '');


// ---- NEW: allow export by id directly from DB ----
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

        $rows        = $plan['rows'] ?? [];
        $userId      = (int)($rowDb['user_id'] ?? ($meta['user_id'] ?? 0));
        $cohort      = $rowDb['cohort_code']   ?: ($meta['cohort_code']   ?? '');
        $courseId    = $rowDb['course_id']     ?: ($meta['course_id']     ?? '');
        $courseCode  = $rowDb['course_code']   ?: ($meta['course_code']   ?? '');
        $moduleCode  = $rowDb['module_code']   ?: ($meta['module_code']   ?? '');
        $learningMode = $rowDb['learning_mode'] ?: ($meta['learning_mode'] ?? '');
        $courseTitle = $rowDb['course_title']  ?: ($meta['course_title']  ?? '');

        $startDate   = $meta['start_date']   ?? '';
        $days        = $meta['days']         ?? [];
        $countries   = $meta['countries']    ?? [];
        $timeSlot    = $meta['time']['slot'] ?? '';
        $customStart = $meta['time']['custom_start'] ?? '';
        $customEnd   = $meta['time']['custom_end']   ?? '';
    } else {
        http_response_code(404);
        exit('Schedule not found.');
    }
}


// Helper: robust dd/mm/yyyy text -> timestamp (or null)
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

$ss = new Spreadsheet();
$ss->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Class Schedule');

// Page setup (portrait + fit to 1 page wide)
$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
    ->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()
    ->setTop(0.39)->setRight(0.39)->setLeft(0.39)->setBottom(0.47); // ~10mm
$sheet->getPageSetup()->setHorizontalCentered(true);

// Colors
$PURPLE = 'FF941D63';     // title + meta label
$GREY   = 'FFDDDDDD';     // meta value bg
$HEAD   = 'FFF0F0F0';     // table head
$BLACK  = 'FF000000';
$WHITE  = 'FFFFFFFF';
$YELLOW = 'FFFFF7B2';

// ---- Title bar (A1:H1) ----
$sheet->mergeCells('A1:H1')->setCellValue('A1', 'CLASS SCHEDULE');
$sheet->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => $WHITE]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $PURPLE]],
]);

// ---- Meta block ----
$metaStart = 3;
$labels = [
    'Module Name:' => $moduleCode,
    'Course Name:' => $courseTitle,
    'Cohort Code:' => $cohort,
    'Mode :'       => $learningMode,
    'SOC :'        => (function ($startDate) {
        $ts = ts_from_any($startDate);
        return $ts ? date('d-M-y', $ts) : $startDate;
    })($startDate),
];

// Style presets
$borderThin = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $BLACK]]]
];
$metaLabelStyle = [
    'font' => ['bold' => true, 'color' => ['argb' => $WHITE]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $PURPLE]],
] + $borderThin;
$metaValueStyle = [
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $GREY]],
] + $borderThin;

$row = $metaStart;
foreach ($labels as $k => $v) {
    // A label, B..H value (merge)
    $sheet->setCellValue("A{$row}", $k);
    $sheet->mergeCells("B{$row}:H{$row}")->setCellValue("B{$row}", $v);
    $sheet->getStyle("A{$row}")->applyFromArray($metaLabelStyle);
    $sheet->getStyle("B{$row}:H{$row}")->applyFromArray($metaValueStyle);
    $sheet->getRowDimension($row)->setRowHeight(18);
    $row++;
}

// ---- Table header (after a blank line) ----
$headerRow = $row + 1;
$headers = ['Session No', 'Session Type-Mode', 'Session Details', 'Duration Hr', 'Faculty Name', 'Date', 'Day', 'Time'];
$cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
foreach ($cols as $i => $col) $sheet->setCellValue($col . $headerRow, $headers[$i]);

$sheet->getStyle("A{$headerRow}:H{$headerRow}")->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $HEAD]],
] + $borderThin);

// Column widths (approx to match your PDF)
$sheet->getColumnDimension('A')->setWidth(7);   // No
$sheet->getColumnDimension('B')->setWidth(16);  // Type
$sheet->getColumnDimension('C')->setWidth(55);  // Details
$sheet->getColumnDimension('D')->setWidth(10);  // Duration
$sheet->getColumnDimension('E')->setWidth(24);  // Faculty
$sheet->getColumnDimension('F')->setWidth(13);  // Date
$sheet->getColumnDimension('G')->setWidth(8);   // Day
$sheet->getColumnDimension('H')->setWidth(16);  // Time

// Freeze panes (keep header visible)
$sheet->freezePane("A" . ($headerRow + 1));

// ---- Data rows ----
$dataStart = $headerRow + 1;
$rIdx = $dataStart;

foreach ($rows as $r) {
    $no       = $r['no']       ?? '';
    $type     = $r['type']     ?? '';
    $details  = $r['details']  ?? '';
    $duration = $r['duration'] ?? '';
    $faculty  = $r['faculty']  ?? '';
    $dateStr  = $r['date']     ?? '';
    $day      = $r['day']      ?? '';
    $time     = $r['time']     ?? '';

    $sheet->setCellValue("A{$rIdx}", $no);
    $sheet->setCellValue("B{$rIdx}", $type);
    $sheet->setCellValue("C{$rIdx}", $details);
    $sheet->setCellValue("D{$rIdx}", $duration);
    $sheet->setCellValue("E{$rIdx}", $faculty);

    // Date as real Excel date with dd/mm/yyyy format
    $ts = ts_from_any($dateStr);
    if ($ts) {
        $sheet->setCellValue("F{$rIdx}", ExcelDate::PHPToExcel($ts));
        $sheet->getStyle("F{$rIdx}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    } else {
        $sheet->setCellValue("F{$rIdx}", $dateStr);
    }

    $sheet->setCellValue("G{$rIdx}", $day);
    $sheet->setCellValue("H{$rIdx}", $time);

    // Wrap long text in details & center others
    $sheet->getStyle("C{$rIdx}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle("A{$rIdx}:B{$rIdx}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("D{$rIdx}:H{$rIdx}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Borders
    $sheet->getStyle("A{$rIdx}:H{$rIdx}")->applyFromArray($borderThin);

    // Slightly taller rows for readability
    $sheet->getRowDimension($rIdx)->setRowHeight(18);

    $rIdx++;
}

// Highlight LAST data row yellow
if (count($rows) > 0) {
    $last = $rIdx - 1;
    $sheet->getStyle("A{$last}:H{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($YELLOW);
}

// Output as download
$filename = 'class-schedule.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
