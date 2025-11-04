<?php
/**
 * 独立的请假数据下载脚本 - 按楼号分sheet的Excel版本
 * 使用PhpSpreadsheet生成多sheet的Excel文件
 */

// 设置安全头
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// 禁用错误显示
ini_set('display_errors', 0);
error_reporting(0);

// 清理所有输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

// 引入PhpSpreadsheet
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// 获取日期参数
$date = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 验证日期格式
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit('日期格式无效');
}

// 生成Excel文件
try {
    // 连接数据库
    $host = 'localhost';
    $dbname = 'student_dorm_sql';
    $username = 'Student_Dorm_Sql';
    $password = 'YOUR_DATABASE_PASSWORD';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // 查询请假学生数据 - 按楼号分组
    $sql = "SELECT DISTINCT s.student_id, s.name, s.building, s.building_area, s.room_number, s.bed_number, 
                   s.counselor, s.counselor_phone
            FROM check_records cr 
            JOIN students s ON cr.student_id = s.student_id 
            WHERE DATE(cr.check_time) = ? AND cr.status = '请假'
            ORDER BY s.building, s.building_area, s.room_number, s.bed_number";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date]);
    $allStudents = $stmt->fetchAll();
    
    // 如果没有请假学生，返回空文件提示
    if (empty($allStudents)) {
        // 创建一个简单的提示Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('提示');
        $sheet->setCellValue('A1', '提示');
        $sheet->setCellValue('A2', '该日期没有请假学生记录');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        // 输出文件
        $filename = 'leave_data_' . $date . '_' . date('YmdHis') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
    
    // 按楼号分组
    $studentsByBuilding = [];
    foreach ($allStudents as $student) {
        $building = $student['building'];
        if (!isset($studentsByBuilding[$building])) {
            $studentsByBuilding[$building] = [];
        }
        $studentsByBuilding[$building][] = $student;
    }
    
    // 对楼号排序
    ksort($studentsByBuilding);
    
    // 创建Excel文件
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0); // 移除默认的sheet
    
    $sheetIndex = 0;
    foreach ($studentsByBuilding as $building => $students) {
        // 创建新的sheet
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $building . '号楼');
        $spreadsheet->addSheet($sheet, $sheetIndex);
        
        // 设置表头
        $headers = ['序号', '房间号', '床号', '姓名', '辅导员', '辅导员联系方式'];
        $sheet->fromArray($headers, null, 'A1');
        
        // 设置表头样式
        $headerStyle = [
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];
        $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);
        
        // 设置列宽
        $sheet->getColumnDimension('A')->setWidth(8);   // 序号
        $sheet->getColumnDimension('B')->setWidth(15);  // 房间号
        $sheet->getColumnDimension('C')->setWidth(8);   // 床号
        $sheet->getColumnDimension('D')->setWidth(12);  // 姓名
        $sheet->getColumnDimension('E')->setWidth(12);  // 辅导员
        $sheet->getColumnDimension('F')->setWidth(18);  // 联系方式
        
        // 设置表头行高
        $sheet->getRowDimension(1)->setRowHeight(25);
        
        // 填充数据
        $row = 2;
        $sequence = 1;
        foreach ($students as $student) {
            $roomNumber = $student['building'] . '#' . $student['building_area'] . $student['room_number'];
            $counselorPhone = $student['counselor_phone'] ?? '';
            
            $sheet->setCellValue('A' . $row, $sequence);
            $sheet->setCellValue('B' . $row, $roomNumber);
            $sheet->setCellValue('C' . $row, $student['bed_number']);
            $sheet->setCellValue('D' . $row, $student['name']);
            $sheet->setCellValue('E' . $row, $student['counselor']);
            $sheet->setCellValue('F' . $row, $counselorPhone);
            
            // 设置数据行样式
            $dataStyle = [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC']
                    ]
                ]
            ];
            $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($dataStyle);
            
            // 设置行高
            $sheet->getRowDimension($row)->setRowHeight(20);
            
            // 交替行背景色
            if ($sequence % 2 == 0) {
                $sheet->getStyle('A' . $row . ':F' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F2F2');
            }
            
            $row++;
            $sequence++;
        }
        
        // 添加统计行
        $sheet->setCellValue('A' . $row, '合计');
        $sheet->setCellValue('D' . $row, count($students) . ' 人');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        
        // 统计行样式
        $summaryStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FF0000']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000']
                ]
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFF00']
            ]
        ];
        $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($summaryStyle);
        $sheet->getRowDimension($row)->setRowHeight(25);
        
        $sheetIndex++;
    }
    
    // 激活第一个sheet
    $spreadsheet->setActiveSheetIndex(0);
    
    // 输出Excel文件
    $filename = 'leave_data_' . $date . '_' . date('YmdHis') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
    // 清理内存
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    
    exit;
    
} catch (Exception $e) {
    error_log("请假数据下载错误: " . $e->getMessage());
    error_log("错误堆栈: " . $e->getTraceAsString());
    http_response_code(500);
    exit('下载失败: ' . $e->getMessage());
}
?>
