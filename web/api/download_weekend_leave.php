<?php
/**
 * 周末离校请假单下载API
 * 完全按照用户模板格式生成
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Drawing;

// 检查登录
startSecureSession();
if (!isLoggedIn()) {
    die('未登录');
}

// 获取数据库连接
$db = getDBConnection();

// 获取参数
$startDate = $_GET['start'] ?? '';
$endDate = $_GET['end'] ?? '';

if (empty($startDate) || empty($endDate)) {
    die('缺少日期参数');
}

// 验证日期格式
if (!validateDate($startDate) || !validateDate($endDate)) {
    die('日期格式错误');
}

try {
    // 生成日期数组
    $dates = generateDateRange($startDate, $endDate);
    
    if (count($dates) > 7) {
        die('日期范围不能超过7天');
    }
    
    // 计算周次
    $weekNumber = calculateWeekNumber($startDate);
    
    // 查询请假数据（按楼栋分组）
    $leaveData = getLeaveDataByBuilding($startDate, $endDate, $dates);
    
    if (empty($leaveData)) {
        die('该时间段内没有请假数据');
    }
    
    // 生成Excel
    $spreadsheet = generateWeekendLeaveExcel($leaveData, $dates, $weekNumber, $startDate);
    
    // 输出文件
    $filename = "周末离校请假单_第{$weekNumber}周_" . date('Ymd', strtotime($startDate)) . ".xlsx";
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . urlencode($filename) . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
    
} catch (Exception $e) {
    error_log("周末请假单生成失败: " . $e->getMessage());
    die('生成失败: ' . $e->getMessage());
}

/**
 * 验证日期格式
 */
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * 生成日期范围数组
 */
function generateDateRange($start, $end) {
    $dates = [];
    $current = new DateTime($start);
    $end = new DateTime($end);
    
    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }
    
    return $dates;
}

/**
 * 计算周次
 */
function calculateWeekNumber($date) {
    $targetDate = new DateTime($date);
    $month = (int)$targetDate->format('m');
    $year = (int)$targetDate->format('Y');
    
    if ($month >= 9) {
        $semesterStart = new DateTime("$year-09-01");
    } else {
        $semesterStart = new DateTime("$year-03-01");
    }
    
    while ($semesterStart->format('N') != 1) {
        $semesterStart->modify('+1 day');
    }
    
    $diff = $targetDate->diff($semesterStart);
    $days = $diff->days;
    
    if ($targetDate < $semesterStart) {
        return 1;
    }
    
    $weekNumber = floor($days / 7) + 1;
    return max(1, $weekNumber);
}

/**
 * 查询请假数据（按楼栋分组）
 */
function getLeaveDataByBuilding($startDate, $endDate, $dates) {
    global $db;
    
    $sql = "
        SELECT DISTINCT
            s.building,
            s.building_area,
            s.room_number,
            s.bed_number,
            s.student_id,
            s.name
        FROM students s
        WHERE EXISTS (
            SELECT 1 FROM check_records cr
            WHERE cr.student_id = s.student_id
              AND DATE(cr.check_time) BETWEEN :start_date AND :end_date
              AND cr.status = '请假'
        )
        ORDER BY s.building, s.building_area, s.room_number, s.bed_number
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $byBuilding = [];
    foreach ($students as $student) {
        $building = $student['building'];
        
        if (!isset($byBuilding[$building])) {
            $byBuilding[$building] = [];
        }
        
        $student['leave_status'] = [];
        foreach ($dates as $date) {
            $student['leave_status'][$date] = checkLeaveOnDate($student['student_id'], $date);
        }
        
        $byBuilding[$building][] = $student;
    }
    
    uksort($byBuilding, function($a, $b) {
        return (int)$a - (int)$b;
    });
    
    return $byBuilding;
}

/**
 * 检查某学生在某天是否请假
 */
function checkLeaveOnDate($studentId, $date) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM check_records
        WHERE student_id = :student_id 
          AND DATE(check_time) = :date 
          AND status = '请假'
    ");
    
    $stmt->execute([
        ':student_id' => $studentId,
        ':date' => $date
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['cnt'] > 0;
}

/**
 * 生成周末离校请假单Excel（按模板格式）
 */
function generateWeekendLeaveExcel($leaveData, $dates, $weekNumber, $startDate) {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);
    
    $sheetIndex = 0;
    
    foreach ($leaveData as $building => $students) {
        $sheet = $spreadsheet->createSheet($sheetIndex++);
        $sheet->setTitle($building . '号公寓');
        
        generateSheetContent($sheet, $building, $students, $dates, $weekNumber, $startDate);
    }
    
    $spreadsheet->setActiveSheetIndex(0);
    return $spreadsheet;
}

/**
 * 生成单个Sheet的内容（完全按照模板格式）
 */
function generateSheetContent($sheet, $building, $students, $dates, $weekNumber, $startDate) {
    $dayCount = count($dates);
    $dateStartCol = 4; // D列开始是日期
    $dateEndCol = 3 + $dayCount; // 最后一个日期列
    $noteCol = $dateEndCol + 1; // 备注列
    $lastCol = getColumnLetter($noteCol);
    
    $currentRow = 1;
    
    // ===== 第1-2行：大标题 =====
    $dateObj = new DateTime($startDate);
    $dateStr = $dateObj->format('Y年m月d日');
    $titleText = "周末离校请假单     \n （第 {$weekNumber} 周          {$dateStr}）";
    
    $sheet->setCellValue('A1', $titleText);
    $sheet->mergeCells("A1:{$lastCol}2");
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 18, 'name' => '宋体'],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER, 
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ]
    ]);
    $sheet->getRowDimension(2)->setRowHeight(42);
    
    // ===== 第3-4行：学院和公寓信息 =====
    $currentRow = 3;
    $midCol = getColumnLetter((int)(($noteCol + 3) / 2)); // 中间列
    $midCol2 = getColumnLetter((int)(($noteCol + 3) / 2) + 1);
    
    $sheet->setCellValue('A3', '学院名称');
    $sheet->mergeCells('A3:A4');
    
    $sheet->setCellValue('B3', '机电工程学院');
    $sheet->mergeCells("B3:{$midCol}4");
    
    $nextCol = getColumnLetter((int)(($noteCol + 3) / 2) + 1);
    $sheet->setCellValue("{$nextCol}3", '公寓号');
    $sheet->mergeCells("{$nextCol}3:{$nextCol}4");
    
    $nextCol2 = getColumnLetter((int)(($noteCol + 3) / 2) + 2);
    $sheet->setCellValue("{$nextCol2}3", $building);
    $sheet->mergeCells("{$nextCol2}3:{$lastCol}4");
    
    // 样式
    $sheet->getStyle("A3:{$lastCol}4")->applyFromArray([
        'font' => ['size' => 11, 'name' => '宋体'],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    
    // ===== 第5-6行：表头 =====
    $currentRow = 5;
    
    // 固定列表头
    $sheet->setCellValue('A5', '寝室号');
    $sheet->mergeCells('A5:A6');
    $sheet->setCellValue('B5', '学生姓名');
    $sheet->mergeCells('B5:B6');
    $sheet->setCellValue('C5', '床位');
    $sheet->mergeCells('C5:C6');
    
    // 请假时间合并列
    $dateStartColLetter = getColumnLetter($dateStartCol);
    $dateEndColLetter = getColumnLetter($dateEndCol);
    $sheet->setCellValue("{$dateStartColLetter}5", '请假时间');
    $sheet->mergeCells("{$dateStartColLetter}5:{$dateEndColLetter}5");
    
    // 备注列
    $sheet->setCellValue("{$lastCol}5", '备注');
    $sheet->mergeCells("{$lastCol}5:{$lastCol}6");
    
    // 第6行：日期子表头
    foreach ($dates as $index => $date) {
        $col = getColumnLetter($dateStartCol + $index);
        $dateObj = new DateTime($date);
        $weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
        $weekday = $weekdays[$dateObj->format('w')];
        $sheet->setCellValue("{$col}6", $weekday);
    }
    
    // 表头样式
    $sheet->getStyle("A5:{$lastCol}6")->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'name' => '宋体'],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE7E6E6']]
    ]);
    $sheet->getRowDimension(5)->setRowHeight(30);
    $sheet->getRowDimension(6)->setRowHeight(24);
    
    // ===== 数据行 =====
    $currentRow = 7;
    foreach ($students as $student) {
        $roomNo = $student['building_area'] . $student['room_number'];
        $sheet->setCellValue("A{$currentRow}", $roomNo);
        $sheet->setCellValue("B{$currentRow}", $student['name']);
        $sheet->setCellValue("C{$currentRow}", $student['bed_number']);
        
        // 请假状态
        foreach ($dates as $index => $date) {
            $col = getColumnLetter($dateStartCol + $index);
            $hasLeave = $student['leave_status'][$date];
            $sheet->setCellValue("{$col}{$currentRow}", $hasLeave ? '✓' : '');
        }
        
        // 备注列留空
        $sheet->setCellValue("{$lastCol}{$currentRow}", '');
        
        $currentRow++;
    }
    
    // 数据区样式
    $dataEndRow = $currentRow - 1;
    if ($dataEndRow >= 7) {
        $sheet->getStyle("A7:{$lastCol}{$dataEndRow}")->applyFromArray([
            'font' => ['size' => 11, 'name' => '宋体'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        for ($i = 7; $i <= $dataEndRow; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(13.95);
        }
    }
    
    // ===== 签字栏（左右分布）=====
    $currentRow = $dataEndRow + 1;
    $signRow = $currentRow;
    
    // 左边：辅导员签字（A到中间列）
    $midCol = getColumnLetter((int)(($noteCol + 1) / 2));
    $sheet->setCellValue("A{$signRow}", '辅导员签字：');
    $sheet->mergeCells("A{$signRow}:{$midCol}{$signRow}");
    
    // 右边：分院领导签字
    $nextCol = getColumnLetter((int)(($noteCol + 1) / 2) + 1);
    $sheet->setCellValue("{$nextCol}{$signRow}", "分院领导签字：\n\n\n\n    （分院盖章）");
    $sheet->mergeCells("{$nextCol}{$signRow}:{$lastCol}{$signRow}");
    
    // 签字栏样式
    $sheet->getStyle("A{$signRow}:{$lastCol}{$signRow}")->applyFromArray([
        'font' => ['size' => 11, 'name' => '宋体'],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT, 
            'vertical' => Alignment::VERTICAL_TOP,
            'wrapText' => true
        ],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $sheet->getRowDimension($signRow)->setRowHeight(136.95);
    
    // 如果有辅导员手签图片
    $signatureFile = ROOT_PATH . '/uploads/counselor_signature.png';
    if (file_exists($signatureFile)) {
        $drawing = new Drawing();
        $drawing->setName('辅导员签字');
        $drawing->setPath($signatureFile);
        $drawing->setCoordinates("B{$signRow}");
        $drawing->setHeight(60);
        $drawing->setOffsetX(10);
        $drawing->setOffsetY(30);
        $drawing->setWorksheet($sheet);
    }
    
    // ===== 备注行 =====
    $currentRow++;
    $noteRow = $currentRow;
    $sheet->setCellValue("A{$noteRow}", '备注：');
    $sheet->setCellValue("B{$noteRow}", '此表按公寓号填写，一个公寓一张表，填写完成后辅导员及管理副书记签字盖分院公章，交给查寝老师。');
    $sheet->mergeCells("B{$noteRow}:{$lastCol}{$noteRow}");
    
    $sheet->getStyle("A{$noteRow}:{$lastCol}{$noteRow}")->applyFromArray([
        'font' => ['size' => 10, 'name' => '宋体'],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $sheet->getRowDimension($noteRow)->setRowHeight(28.95);
    
    // ===== 设置列宽（按模板）=====
    $sheet->getColumnDimension('A')->setWidth(14.2);
    $sheet->getColumnDimension('B')->setWidth(13.9);
    $sheet->getColumnDimension('C')->setWidth(6.8);
    
    for ($i = 0; $i < $dayCount; $i++) {
        $col = getColumnLetter($dateStartCol + $i);
        $sheet->getColumnDimension($col)->setWidth(12.6);
    }
    
    $sheet->getColumnDimension($lastCol)->setWidth(19.4);
    
    // 打印设置
    $sheet->getPageSetup()->setPrintArea("A1:{$lastCol}{$noteRow}");
    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
}

/**
 * 获取Excel列字母
 */
function getColumnLetter($num) {
    $letter = '';
    while ($num > 0) {
        $num--;
        $letter = chr(65 + ($num % 26)) . $letter;
        $num = intdiv($num, 26);
    }
    return $letter;
}
