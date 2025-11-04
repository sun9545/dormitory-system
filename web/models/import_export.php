<?php
/**
 * 导入导出数据模型
 */

// 引入Excel处理库
require_once ROOT_PATH . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

// 引入Student模型
require_once __DIR__ . '/student.php';

class ImportExport {
    private $db;
    
    public function __construct() {
        $this->db = getDBConnection();
    }
    
    /**
     * 导入学生数据
     * 
     * @param string $filePath Excel文件路径
     * @return array 导入结果
     */
    public function importStudentsFromExcel($filePath) {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        try {
            // 读取Excel文件
            $reader = new XlsxReader();
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            
            // 获取表头
            $headers = [];
            for ($col = 1; $col <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn); $col++) {
                $headers[$col] = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
            }
            
            // 查找必要列的索引
            $studentIdCol = array_search('学号', $headers) ?: array_search('学号(必填)', $headers);
            $nameCol = array_search('姓名', $headers) ?: array_search('姓名(必填)', $headers);
            $genderCol = array_search('性别', $headers) ?: array_search('性别(男/女)', $headers);
            $classCol = array_search('班级', $headers);
            $buildingCol = array_search('楼栋', $headers) ?: array_search('楼栋(数字)', $headers);
            $areaCol = array_search('区域', $headers) ?: array_search('区域(A/B)', $headers);
            $floorCol = array_search('楼层', $headers) ?: array_search('楼层(数字)', $headers);
            $roomCol = array_search('宿舍号', $headers);
            $bedCol = array_search('床号', $headers) ?: array_search('床号(数字)', $headers);
            $counselorCol = array_search('辅导员', $headers);
            $counselorPhoneCol = array_search('辅导员电话', $headers);
            
            // 检查必要列是否存在
            if (!$studentIdCol || !$nameCol) {
                $result['failed']++;
                $result['errors'][] = "导入失败: Excel文件必须包含'学号'和'姓名'列";
                return $result;
            }
            
            // 读取数据
            $students = [];
            for ($row = 2; $row <= $highestRow; $row++) {
                $studentId = $studentIdCol ? $worksheet->getCellByColumnAndRow($studentIdCol, $row)->getValue() : '';
                $name = $nameCol ? $worksheet->getCellByColumnAndRow($nameCol, $row)->getValue() : '';
                $gender = $genderCol ? $worksheet->getCellByColumnAndRow($genderCol, $row)->getValue() : '男';
                $className = $classCol ? $worksheet->getCellByColumnAndRow($classCol, $row)->getValue() : '';
                $building = $buildingCol ? $worksheet->getCellByColumnAndRow($buildingCol, $row)->getValue() : 1;
                $buildingArea = $areaCol ? $worksheet->getCellByColumnAndRow($areaCol, $row)->getValue() : 'A';
                $buildingFloor = $floorCol ? $worksheet->getCellByColumnAndRow($floorCol, $row)->getValue() : 1;
                $roomNumber = $roomCol ? $worksheet->getCellByColumnAndRow($roomCol, $row)->getValue() : '';
                $bedNumber = $bedCol ? $worksheet->getCellByColumnAndRow($bedCol, $row)->getValue() : 1;
                $counselor = $counselorCol ? $worksheet->getCellByColumnAndRow($counselorCol, $row)->getValue() : '';
                $counselorPhone = $counselorPhoneCol ? $worksheet->getCellByColumnAndRow($counselorPhoneCol, $row)->getValue() : '';
                
                // 验证必填字段
                if (empty($studentId) || empty($name)) {
                    $result['failed']++;
                    $result['errors'][] = "第 {$row} 行：学号和姓名不能为空";
                    continue;
                }
                
                // 添加到导入列表
                $students[] = [
                    'student_id' => $studentId,
                    'name' => $name,
                    'gender' => !empty($gender) ? $gender : '男',
                    'class_name' => !empty($className) ? $className : '',
                    'building' => !empty($building) ? $building : 1,
                    'building_area' => !empty($buildingArea) ? $buildingArea : 'A',
                    'building_floor' => !empty($buildingFloor) ? $buildingFloor : 1,
                    'room_number' => !empty($roomNumber) ? $roomNumber : '',
                    'bed_number' => !empty($bedNumber) ? $bedNumber : 1,
                    'counselor' => !empty($counselor) ? $counselor : '',
                    'counselor_phone' => !empty($counselorPhone) ? $counselorPhone : ''
                ];
            }
            
            // 导入数据
            if (!empty($students)) {
                $student = new Student();
                $importResult = $student->importStudents($students);
                
                $result['success'] = $importResult['success'];
                $result['failed'] += $importResult['failed'];
                $result['errors'] = array_merge($result['errors'], $importResult['errors']);
            } else {
                $result['errors'][] = "没有找到有效的学生数据";
            }
        } catch (Exception $e) {
            $result['failed']++;
            $result['errors'][] = "导入失败: " . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * 导出学生数据到Excel
     * 
     * @param array $filters 过滤条件
     * @return string 导出的文件路径
     */
    public function exportStudentsToExcel($filters = []) {
        try {
            // 获取学生数据
            $student = new Student();
            $studentsData = $student->getAllStudents(1, 100000, $filters)['students'];
            
            // 创建电子表格
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // 设置表头
            $sheet->setCellValue('A1', '学号');
            $sheet->setCellValue('B1', '姓名');
            $sheet->setCellValue('C1', '性别');
            $sheet->setCellValue('D1', '班级');
            $sheet->setCellValue('E1', '楼栋');
            $sheet->setCellValue('F1', '区域');
            $sheet->setCellValue('G1', '楼层');
            $sheet->setCellValue('H1', '宿舍号');
            $sheet->setCellValue('I1', '床号');
            $sheet->setCellValue('J1', '辅导员');
            $sheet->setCellValue('K1', '辅导员电话');
            
            // 填充数据
            $row = 2;
            foreach ($studentsData as $student) {
                $sheet->setCellValue('A' . $row, $student['student_id']);
                $sheet->setCellValue('B' . $row, $student['name']);
                $sheet->setCellValue('C' . $row, $student['gender']);
                $sheet->setCellValue('D' . $row, $student['class_name']);
                $sheet->setCellValue('E' . $row, $student['building']);
                $sheet->setCellValue('F' . $row, $student['building_area']);
                $sheet->setCellValue('G' . $row, $student['building_floor']);
                $sheet->setCellValue('H' . $row, $student['room_number']);
                $sheet->setCellValue('I' . $row, $student['bed_number']);
                $sheet->setCellValue('J' . $row, $student['counselor']);
                $sheet->setCellValue('K' . $row, $student['counselor_phone']);
                $row++;
            }
            
            // 生成文件名
            $filename = 'students_export_' . date('YmdHis') . '.xlsx';
            $filepath = UPLOAD_PATH . '/' . $filename;
            
            // 确保目录存在
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }
            
            // 保存文件
            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);
            
            return $filepath;
        } catch (Exception $e) {
            error_log("导出学生数据失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建学生导入CSV模板
     * 
     * @return string 模板文件路径
     */
    public function createStudentTemplate() {
        try {
            // 确保目录存在
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0777, true);
            }
            
            // 生成文件名和路径
            $filename = 'student_template.csv';
            $filepath = UPLOAD_PATH . '/' . $filename;
            
            // 创建CSV文件
            $file = fopen($filepath, 'w');
            if ($file === false) {
                throw new Exception("无法创建CSV文件: " . $filepath);
            }
            
            // 添加BOM以正确显示中文
            fwrite($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // 写入表头
            fputcsv($file, ['学号', '姓名', '性别', '班级', '楼栋', '区域', '楼层', '宿舍号', '床号', '辅导员', '辅导员电话']);
            
            // 写入示例数据
            fputcsv($file, ['202001001', '张三', '男', '计算机科学与技术1班', '1', 'A', '1', '101', '1', '李老师', '13800138000']);
            
            // 关闭文件
            fclose($file);
            
            return $filepath;
        } catch (Exception $e) {
            error_log("创建学生模板失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建学生请假CSV模板
     * 
     * @return string 模板文件路径
     */
    public function createLeaveTemplate() {
        try {
            // 确保目录存在
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0777, true);
            }
            
            // 生成文件名和路径
            $filename = 'leave_template.csv';
            $filepath = UPLOAD_PATH . '/' . $filename;
            
            // 创建CSV文件
            $file = fopen($filepath, 'w');
            if ($file === false) {
                throw new Exception("无法创建CSV文件: " . $filepath);
            }
            
            // 添加BOM以正确显示中文
            fwrite($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // 写入表头
            fputcsv($file, ['班级', '姓名', '学号']);
            
            // 写入示例数据
            fputcsv($file, ['计算机科学与技术1班', '张三', '202001001']);
            
            // 关闭文件
            fclose($file);
            
            return $filepath;
        } catch (Exception $e) {
            error_log("创建请假模板失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建学生请假Excel模板
     * 
     * @return string 模板文件路径
     */
    public function createLeaveExcelTemplate() {
        try {
            // 创建电子表格
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // 设置表头
            $sheet->setCellValue('A1', '班级');
            $sheet->setCellValue('B1', '姓名');
            $sheet->setCellValue('C1', '学号');
            
            // 添加示例数据
            $sheet->setCellValue('A2', '计算机科学与技术1班');
            $sheet->setCellValue('B2', '张三');
            $sheet->setCellValue('C2', '202001001');
            
            $sheet->setCellValue('A3', '电气自动化技术2024-1');
            $sheet->setCellValue('B3', '李四');
            $sheet->setCellValue('C3', '2431110002');
            
            // 设置列宽
            foreach(range('A', 'C') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            
            // 生成文件名
            $filename = 'leave_template.xlsx';
            $filepath = UPLOAD_PATH . '/' . $filename;
            
            // 确保目录存在
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }
            
            // 保存文件
            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);
            
            return $filepath;
        } catch (Exception $e) {
            error_log("创建Excel请假模板失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从Excel或CSV导入请假信息
     * 
     * @param string $filePath 文件路径
     * @param int $userId 用户ID
     * @return array 导入结果
     */
    public function importLeaveFromExcel($filePath, $userId) {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        try {
            // 确保日志目录存在
            $logDir = 'logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            
            // 创建日志文件
            $logFile = $logDir . '/leave_import.log';
            $log = @fopen($logFile, 'a');
            
            if ($log) {
                fwrite($log, "开始导入请假信息: " . date('Y-m-d H:i:s') . "\n");
                fwrite($log, "文件路径: " . $filePath . "\n");
            }
            
            // 检查文件扩展名
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            
            $studentIds = [];
            
            if ($extension === 'csv') {
                // 处理CSV文件
                if ($log) fwrite($log, "处理CSV文件\n");
                
                // 使用统一编码处理
                require_once __DIR__ . '/../utils/CSVEncodingHandler.php';
                
                $content = file_get_contents($filePath);
                
                $logCallback = function($message) use ($log) {
                    if ($log) fwrite($log, $message . "\n");
                };
                
                $encodingResult = CSVEncodingHandler::detectAndConvertEncoding($content, $logCallback);
                
                if ($encodingResult['converted']) {
                    // 如果进行了编码转换，创建临时UTF-8文件
                    file_put_contents($filePath . '.utf8', $encodingResult['content']);
                    if ($log) fwrite($log, "已将文件转换为UTF-8编码并保存临时文件\n");
                    $filePath = $filePath . '.utf8';
                } else {
                    // 即使没有转换，也要应用内容清理
                    file_put_contents($filePath . '.utf8', $encodingResult['content']);
                    $filePath = $filePath . '.utf8';
                }
                
                // 打开CSV文件
                $handle = fopen($filePath, 'r');
                if ($handle === false) {
                    if ($log) fwrite($log, "错误: 无法打开CSV文件\n");
                    $result['errors'][] = "无法打开CSV文件";
                    return $result;
                }
                
                // 读取表头
                $headers = fgetcsv($handle);
                if ($headers === false) {
                    if ($log) fwrite($log, "错误: 无法读取CSV表头\n");
                    fclose($handle);
                    $result['errors'][] = "无法读取CSV表头";
                    return $result;
                }
                
                // 使用统一数据清理函数清理表头
                foreach ($headers as $key => $header) {
                    $headers[$key] = CSVEncodingHandler::cleanFieldData($header);
                }
                
                if ($log) fwrite($log, "表头: " . implode(', ', $headers) . "\n");
                
                // 查找必要列的索引
                $studentIdCol = array_search('学号', $headers);
                $nameCol = array_search('姓名', $headers);
                $classCol = array_search('班级', $headers);
                
                if ($log) fwrite($log, "列索引: 学号=" . ($studentIdCol !== false ? $studentIdCol : '未找到') . "\n");
                
                // 检查必要列是否存在
                if ($studentIdCol === false) {
                    if ($log) fwrite($log, "错误: 缺少学号列\n");
                    fclose($handle);
                    $result['errors'][] = "导入失败: CSV文件必须包含'学号'列";
                    return $result;
                }
                
                // 读取数据行
                $row = 2; // 从第2行开始（表头是第1行）
                while (($data = fgetcsv($handle)) !== false) {
                    // 如果数据行为空，跳过
                    if (empty($data) || count($data) <= 1) {
                        if ($log) fwrite($log, "跳过空行: 第{$row}行\n");
                        $row++;
                        continue;
                    }
                    
                    // 确保索引存在
                    $studentId = isset($data[$studentIdCol]) ? trim($data[$studentIdCol]) : '';
                    
                    // 验证必填字段
                    if (empty($studentId)) {
                        if ($log) fwrite($log, "错误: 第{$row}行学号为空\n");
                        $row++;
                        continue;
                    }
                    
                    $studentIds[] = $studentId;
                    if ($log) fwrite($log, "添加学生: " . $studentId . "\n");
                    $row++;
                }
                
                fclose($handle);
            } else {
                // 处理Excel文件
                if ($log) fwrite($log, "处理Excel文件\n");
                
            // 读取Excel文件
            $reader = new XlsxReader();
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                
                // 获取表头
                $headers = [];
                for ($col = 1; $col <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn); $col++) {
                    $headers[$col] = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
                }
                
                // 查找必要列的索引
                $classCol = array_search('班级', $headers);
                $nameCol = array_search('姓名', $headers);
                $studentIdCol = array_search('学号', $headers);
                
                // 检查必要列是否存在
                if (!$studentIdCol) {
                    if ($log) fwrite($log, "错误: 缺少学号列\n");
                    $result['errors'][] = "导入失败: Excel文件必须包含'学号'列";
                    return $result;
                }
            
            // 读取数据
            for ($row = 2; $row <= $highestRow; $row++) {
                    $studentId = $studentIdCol ? $worksheet->getCellByColumnAndRow($studentIdCol, $row)->getValue() : '';
                
                if (empty($studentId)) {
                    continue;
                }
                
                $studentIds[] = $studentId;
                    if ($log) fwrite($log, "添加学生: " . $studentId . "\n");
                }
            }
            
            // 批量设置请假状态
            if (!empty($studentIds)) {
                if ($log) fwrite($log, "找到 " . count($studentIds) . " 名学生\n");
                
                $checkRecord = new CheckRecord();
                $batchResult = $checkRecord->batchSetLeaveStatus($studentIds, $userId);
                
                $result['success'] = $batchResult['success'];
                $result['failed'] = $batchResult['failed'];
                $result['errors'] = $batchResult['errors'];
                
                if ($log) {
                    fwrite($log, "导入结果: 成功=" . $result['success'] . ", 失败=" . $result['failed'] . "\n");
                    if (!empty($result['errors'])) {
                        fwrite($log, "错误信息: " . implode(', ', $result['errors']) . "\n");
                    }
                }
                
                // 记录批量导入日志
                $batchName = '请假批次' . date('YmdHis');
                $stmt = $this->db->prepare("INSERT INTO leave_batch (batch_name, upload_time, uploaded_by, effective_date) 
                                           VALUES (:batch_name, NOW(), :uploaded_by, CURDATE())");
                $stmt->execute([
                    'batch_name' => $batchName,
                    'uploaded_by' => $userId
                ]);
            } else {
                if ($log) fwrite($log, "未找到有效的学生数据\n");
                $result['errors'][] = "未找到有效的学生数据";
            }
            
            if ($log) {
                fwrite($log, "导入结束: " . date('Y-m-d H:i:s') . "\n\n");
                fclose($log);
            }
        } catch (Exception $e) {
            $result['failed']++;
            $result['errors'][] = "导入失败: " . $e->getMessage();
            if (isset($log) && $log) {
                fwrite($log, "异常: " . $e->getMessage() . "\n");
                fwrite($log, "导入结束: " . date('Y-m-d H:i:s') . "\n\n");
                fclose($log);
            }
        }
        
        return $result;
    }
    
    /**
     * 获取请假批次上传记录
     * 
     * @param int $limit 记录数量限制
     * @return array 上传记录列表
     */
    public function getLeaveUploadRecords($limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    lb.batch_name,
                    lb.upload_time,
                    lb.effective_date,
                    u.username as uploaded_by_name,
                    COUNT(cr.record_id) as student_count
                FROM leave_batch lb
                LEFT JOIN users u ON lb.uploaded_by = u.id
                LEFT JOIN check_records cr ON DATE(cr.check_time) = lb.effective_date 
                    AND cr.status = '请假'
                    AND cr.check_time >= lb.upload_time
                GROUP BY lb.id
                ORDER BY lb.upload_time DESC
                LIMIT :limit
            ");
            
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取请假上传记录失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 创建学生信息CSV模板（用于指纹映射）
     * 
     * @param array $filters 筛选条件
     * @return string 模板文件路径
     */
    public function createStudentInfoTemplate($filters = []) {
        try {
            // 确保目录存在
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0777, true);
            }
            
            // 生成文件名和路径
            $filename = 'student_info_template_' . time() . '.csv';
            $filepath = UPLOAD_PATH . '/' . $filename;
            
            // 创建CSV文件
            $file = fopen($filepath, 'w');
            if ($file === false) {
                throw new Exception("无法创建CSV文件: " . $filepath);
            }
            
            // 添加BOM以正确显示中文
            fwrite($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // 写入表头
            fputcsv($file, ['宿舍楼号', '人/区号', '学号', '姓名', '班级', '辅导员']);
            
            // 获取学生数据
            $student = new Student();
            $studentsData = $student->getAllStudents(1, 100000, $filters)['students'];
            
            // 写入学生数据
            foreach ($studentsData as $studentInfo) {
                fputcsv($file, [
                    $studentInfo['building'],                    // 宿舍楼号
                    $studentInfo['building_area'],               // 人/区号 (A区/B区)
                    $studentInfo['student_id'],                  // 学号
                    $studentInfo['name'],                        // 姓名
                    $studentInfo['class_name'],                  // 班级
                    $studentInfo['counselor']                    // 辅导员
                ]);
            }
            
            // 关闭文件
            fclose($file);
            
            return $filepath;
        } catch (Exception $e) {
            error_log("创建学生信息模板失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 生成请假学生数据CSV文件
     * 
     * @param string $date 查询日期
     * @return string|false CSV文件路径或失败时返回false
     */
    public function generateLeaveDataCSV($date) {
        try {
            // 确保目录存在
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0777, true);
            }
            
            // 生成文件名和路径
            $filename = 'leave_data_' . $date . '_' . date('YmdHis') . '.csv';
            $filepath = UPLOAD_PATH . '/' . $filename;
            
            // 创建CSV文件
            $file = fopen($filepath, 'w');
            if ($file === false) {
                throw new Exception("无法创建CSV文件: " . $filepath);
            }
            
            // 添加BOM以正确显示中文
            fwrite($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // 写入表头
            fputcsv($file, ['序号', '房间号', '床号', '姓名', '辅导员', '辅导员联系方式']);
            
            // 获取指定日期的请假学生数据
            require_once __DIR__ . '/check_record.php';
            $checkRecord = new CheckRecord();
            $filters = ['status' => '请假'];
            $leaveStudents = $checkRecord->getAllStudentsStatusByDate($date, $filters);
            
            // 写入请假学生数据
            $sequence = 1; // 简单递增序号
            foreach ($leaveStudents as $student) {
                // 格式化房间号为：楼号#区域房间号
                $roomNumber = $student['building'] . '#' . $student['building_area'] . $student['room_number'];
                
                fputcsv($file, [
                    $sequence,                                   // 序号 (递增)
                    $roomNumber,                                 // 房间号 (如：10#A104)
                    $student['bed_number'],                      // 床号
                    $student['name'],                            // 姓名
                    $student['counselor'],                       // 辅导员
                    $student['counselor_phone'] ?? ''            // 辅导员联系方式
                ]);
                
                $sequence++; // 序号递增
            }
            
            // 关闭文件
            fclose($file);
            
            return $filepath;
        } catch (Exception $e) {
            error_log("生成请假数据CSV失败: " . $e->getMessage());
            return false;
        }
    }
    
} 