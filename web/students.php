<?php
/**
 * 学生管理页面
 */

// 获取当前动作
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// 注释：学生模板下载已移至独立API端点 /api/download_student_template.php

// 处理学生信息下载 - 必须在任何输出之前
if ($action === 'download_student_info') {
    // 禁用错误显示，避免任何输出污染文件
    ini_set('display_errors', 0);
    error_reporting(0);
    
    // 清理任何已有的输出缓冲
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // 开启输出缓冲，确保在设置头信息前没有输出
    ob_start();
    
    // 首先加载环境配置并定义常量
    require_once __DIR__ . '/config/env.php';
    
    // 定义必要常量（在加载其他文件之前）
    if (!defined('BASE_URL')) define('BASE_URL', ENV_BASE_URL);
    if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__);
    if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', ROOT_PATH . '/uploads');
    if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', ENV_SESSION_TIMEOUT);
    
    // 现在可以安全加载其他文件
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/utils/auth.php';
    require_once __DIR__ . '/models/import_export.php';
    
    // 检查登录状态
    startSecureSession();
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    
    try {
        // 获取筛选条件
        $filters = [];
        if (isset($_GET['building']) && !empty($_GET['building'])) {
            $filters['building'] = (int)$_GET['building'];
        }
        if (isset($_GET['building_area']) && !empty($_GET['building_area'])) {
            $filters['building_area'] = $_GET['building_area'];
        }
        if (isset($_GET['class_name']) && !empty($_GET['class_name'])) {
            $filters['class_name'] = $_GET['class_name'];
        }
        if (isset($_GET['counselor']) && !empty($_GET['counselor'])) {
            $filters['counselor'] = $_GET['counselor'];
        }
        
        $importExport = new ImportExport();
        $templatePath = $importExport->createStudentInfoTemplate($filters);
        
        if ($templatePath && file_exists($templatePath) && filesize($templatePath) > 0) {
            // 生成文件名
            $filterDesc = [];
            if (isset($filters['building'])) $filterDesc[] = $filters['building'] . '号楼';
            if (isset($filters['building_area'])) $filterDesc[] = $filters['building_area'] . '区';
            if (isset($filters['class_name'])) $filterDesc[] = $filters['class_name'];
            if (isset($filters['counselor'])) $filterDesc[] = $filters['counselor'];
            
            $filterString = !empty($filterDesc) ? implode('_', $filterDesc) : '全部';
            $filename = 'student_info_' . $filterString . '_' . date('YmdHis') . '.csv';
            
            // 清理输出缓冲区，确保没有任何输出
            ob_end_clean();
            
            // 设置CSV下载头
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Content-Length: ' . filesize($templatePath));
            
            // 直接输出文件
            readfile($templatePath);
            exit;
        } else {
            error_log("学生信息文件创建失败: " . $templatePath);
            ob_end_clean();
            header('Location: ' . BASE_URL . '/students.php?error=download_failed');
            exit;
        }
    } catch (Exception $e) {
        error_log("学生信息下载错误: " . $e->getMessage());
        ob_end_clean();
        header('Location: ' . BASE_URL . '/students.php?error=download_failed');
        exit;
    }
}

// 正常页面加载 - 加载配置和必要文件
require_once 'config/config.php';
$pageTitle = '学生管理 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/student.php';
require_once 'models/check_record.php';
require_once 'models/import_export.php';
require_once 'utils/CSVEncodingHandler.php';

// 获取筛选条件和分页参数
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$filters = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $filters['search'] = sanitizeInput($_GET['search']);
}

if (isset($_GET['building']) && !empty($_GET['building'])) {
    $filters['building'] = (int)$_GET['building'];
}

if (isset($_GET['building_area']) && !empty($_GET['building_area'])) {
    $filters['building_area'] = $_GET['building_area'];
}

if (isset($_GET['building_floor']) && !empty($_GET['building_floor'])) {
    $filters['building_floor'] = (int)$_GET['building_floor'];
}

if (isset($_GET['class_name']) && !empty($_GET['class_name'])) {
    $filters['class_name'] = $_GET['class_name'];
}

if (isset($_GET['counselor']) && !empty($_GET['counselor'])) {
    $filters['counselor'] = $_GET['counselor'];
}

// 初始化消息
$message = '';
$messageType = '';

// 创建学生模型实例
$student = new Student();
$checkRecord = new CheckRecord();

// 处理学生状态更新 - 在输出任何内容前处理
if ($action === 'update_status' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/students.php');
    }
    
    if (isset($_POST['student_id']) && isset($_POST['status'])) {
        $studentId = sanitizeInput($_POST['student_id']);
        $status = sanitizeInput($_POST['status']);
        
        if ($checkRecord->updateStudentStatus($studentId, $status, $_SESSION['user_id'])) {
            // 强制清理缓存
            if (function_exists('clearAllRelatedCache')) {
                clearAllRelatedCache();
            } else if (function_exists('clearCache')) {
                clearCache(CACHE_KEY_ALL_STUDENTS);
                clearCache(CACHE_KEY_ALL_STATUS_DATE);
            }
            $_SESSION['message'] = '学生状态更新成功';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = '学生状态更新失败';
            $_SESSION['message_type'] = 'danger';
        }
    }
    
    // 重定向回学生列表
    redirect(BASE_URL . '/students.php');
}

// 处理学生删除 - 在输出任何内容前处理
if ($action === 'delete' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/students.php');
    }
    
    if (isset($_POST['student_id'])) {
        $studentId = sanitizeInput($_POST['student_id']);
        
        if ($student->deleteStudent($studentId)) {
            $_SESSION['message'] = '学生删除成功';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = '学生删除失败';
            $_SESSION['message_type'] = 'danger';
        }
    }
    
    // 重定向回学生列表
    redirect(BASE_URL . '/students.php');
}

// 处理批量删除学生 - 在输出任何内容前处理
if ($action === 'batch_delete' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/students.php');
    }
    
    if (isset($_POST['student_ids']) && !empty($_POST['student_ids'])) {
        $studentIds = explode(',', $_POST['student_ids']);
        $studentIds = array_map('sanitizeInput', $studentIds);
        
        $successCount = 0;
        $failCount = 0;
        $deletedStudents = [];
        
        foreach ($studentIds as $studentId) {
            if (!empty($studentId)) {
                // 获取学生信息用于记录
                $studentInfo = $student->getStudentById($studentId);
                
                if ($student->deleteStudent($studentId)) {
                    $successCount++;
                    if ($studentInfo) {
                        $deletedStudents[] = $studentInfo['name'] . '(' . $studentId . ')';
                    }
                } else {
                    $failCount++;
                }
            }
        }
        
        // 设置提示消息
        if ($successCount > 0 && $failCount == 0) {
            $_SESSION['message'] = "成功删除 {$successCount} 名学生";
            $_SESSION['message_type'] = 'success';
        } elseif ($successCount > 0 && $failCount > 0) {
            $_SESSION['message'] = "成功删除 {$successCount} 名学生，{$failCount} 名学生删除失败";
            $_SESSION['message_type'] = 'warning';
        } else {
            $_SESSION['message'] = "批量删除失败，没有学生被删除";
            $_SESSION['message_type'] = 'danger';
        }
        
        // 记录操作日志
        if (!empty($deletedStudents)) {
            logOperation($_SESSION['user_id'], '批量删除学生', '删除学生: ' . implode(', ', $deletedStudents));
        }
    } else {
        $_SESSION['message'] = '请选择要删除的学生';
        $_SESSION['message_type'] = 'warning';
    }
    
    redirect(BASE_URL . '/students.php');
}

// 处理全部删除
if ($action === 'delete_all' && getRequestMethod() === 'POST') {
    header('Content-Type: application/json');
    
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请重试']);
        exit;
    }
    
    try {
        $deletedCount = $student->deleteAllStudents();
        
        // 记录操作日志
        logOperation($_SESSION['user_id'], '全部删除学生', "删除了所有学生记录，共 {$deletedCount} 条");
        
        echo json_encode([
            'success' => true, 
            'message' => "成功删除全部 {$deletedCount} 条学生记录",
            'deleted_count' => $deletedCount
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
    }
    exit;
}

// 处理批量上传（AJAX方式）
// 处理CSV验证
if ($action === 'validate_csv' && getRequestMethod() === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // 检查文件上传
        if (!isset($_FILES['validate_file']) || $_FILES['validate_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => '文件上传失败']);
            exit;
        }
        
        $file = $_FILES['validate_file'];
        
        // 使用统一的编码处理读取CSV文件
        $content = file_get_contents($file['tmp_name']);
        $encodingResult = CSVEncodingHandler::detectAndConvertEncoding($content);
        $content = $encodingResult['content'];
        
        // 解析CSV内容
        $lines = array_filter(array_map('trim', explode("\n", $content)));
        
        if (count($lines) < 2) {
            echo json_encode(['success' => false, 'message' => 'CSV文件格式错误：至少需要标题行和一行数据']);
            exit;
        }
        
        // 智能检测分隔符
        $delimiter = ',';
        if (count($lines) > 0) {
            $headerLine = $lines[0];
            $commaCount = substr_count($headerLine, ',');
            $semicolonCount = substr_count($headerLine, ';');
            $tabCount = substr_count($headerLine, "\t");
            
            if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
                $delimiter = ';';
            } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
                $delimiter = "\t";
            }
        }
        
        // 解析表头
        $headers = str_getcsv($lines[0], $delimiter);
        $headers = array_map('trim', $headers);
        
        // 字段映射
        $fieldMapping = [
            '学号' => 'student_id',
            '姓名' => 'name', 
            '性别' => 'gender',
            '班级' => 'class_name',
            '楼栋' => 'building',
            '区域' => 'building_area',
            '楼层' => 'building_floor',
            '宿舍号' => 'room_number',
            '床号' => 'bed_number',
            '辅导员' => 'counselor',
            '辅导员电话' => 'counselor_phone'
        ];
        
        $csvData = [];
        
        // 处理数据行
        for ($i = 1; $i < count($lines); $i++) {
            $data = str_getcsv($lines[$i], $delimiter);
            
            // 初始化行数据
            $rowData = [
                'student_id' => '',
                'name' => '',
                'gender' => '',
                'class_name' => '',
                'building' => '',
                'building_area' => '',
                'building_floor' => '',
                'room_number' => '',
                'bed_number' => '',
                'counselor' => '',
                'counselor_phone' => ''
            ];
            
            // 智能字段映射
            for ($j = 0; $j < count($headers) && $j < count($data); $j++) {
                $headerName = trim($headers[$j]);
                if (isset($fieldMapping[$headerName])) {
                    $fieldName = $fieldMapping[$headerName];
                    $rowData[$fieldName] = CSVEncodingHandler::cleanFieldData($data[$j]);
                }
            }
            
            $csvData[] = $rowData;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $csvData
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '处理失败: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'batch_upload' && getRequestMethod() === 'POST') {
    header('Content-Type: application/json');
    
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请重试']);
        exit;
    }
    
    try {
        if (!isset($_FILES['student_batch_file']) || $_FILES['student_batch_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => '请选择要上传的文件']);
            exit;
        }
        
        $file = $_FILES['student_batch_file'];
        $fileInfo = pathinfo($file['name']);
        
        // 验证文件类型
        if (strtolower($fileInfo['extension']) !== 'csv') {
            echo json_encode(['success' => false, 'message' => '请上传CSV格式的文件']);
            exit;
        }
        
        // 添加调试日志
        error_log("CSV文件解析开始，文件名: " . $file['name']);
        
        // 使用统一的编码处理读取CSV文件
        $content = file_get_contents($file['tmp_name']);
        $encodingResult = CSVEncodingHandler::detectAndConvertEncoding($content);
        $content = $encodingResult['content'];
        
        // 解析CSV内容
        $lines = array_filter(array_map('trim', explode("\n", $content)));
        
        if (count($lines) < 2) {
            echo json_encode(['success' => false, 'message' => 'CSV文件格式错误：至少需要标题行和一行数据']);
                    exit;
                }
                
        // 智能检测分隔符
        $delimiter = ',';
        if (count($lines) > 0) {
            $headerLine = $lines[0];
            $commaCount = substr_count($headerLine, ',');
            $semicolonCount = substr_count($headerLine, ';');
            $tabCount = substr_count($headerLine, "\t");
            
            if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
                $delimiter = ';';
            } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
                $delimiter = "\t";
            }
        }
        
        // 解析表头
        $headers = str_getcsv($lines[0], $delimiter);
        $headers = array_map('trim', $headers);
        
        // 字段映射
        $fieldMapping = [
            '学号' => 'student_id',
            '姓名' => 'name', 
            '性别' => 'gender',
            '班级' => 'class_name',
            '楼栋' => 'building',
            '区域' => 'building_area',
            '楼层' => 'building_floor',
            '宿舍号' => 'room_number',
            '床号' => 'bed_number',
            '辅导员' => 'counselor',
            '辅导员电话' => 'counselor_phone'
        ];
        
        $csvData = [];
        
        // 处理数据行
        for ($i = 1; $i < count($lines); $i++) {
            $data = str_getcsv($lines[$i], $delimiter);
            
            // 初始化行数据
            $rowData = [
                'student_id' => '',
                'name' => '',
                'gender' => '',
                'class_name' => '',
                'building' => '',
                'building_area' => '',
                'building_floor' => '',
                'room_number' => '',
                'bed_number' => '',
                'counselor' => '',
                'counselor_phone' => '',
                'row_number' => $i + 1
            ];
            
            // 智能字段映射
            for ($j = 0; $j < count($headers) && $j < count($data); $j++) {
                $headerName = trim($headers[$j]);
                if (isset($fieldMapping[$headerName])) {
                    $fieldName = $fieldMapping[$headerName];
                    $rowData[$fieldName] = CSVEncodingHandler::cleanFieldData($data[$j]);
                }
            }
            
            $csvData[] = $rowData;
        }
        
        if (empty($csvData)) {
            echo json_encode(['success' => false, 'message' => '文件中没有有效数据']);
            exit;
        }
        
        // 批量导入学生
        $result = $student->batchImportStudents($csvData);
        
        echo json_encode([
            'success' => true,
            'message' => "批量上传完成",
            'total' => $result['total'],
            'success' => $result['success'],
            'fail' => $result['fail'],
            'errors' => $result['errors']
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '上传失败: ' . $e->getMessage()]);
    }
    exit;
}

// 处理学生数据在线验证
if ($action === 'validate_student_data' && getRequestMethod() === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // 验证CSRF令牌
    if (!isset($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => '安全验证失败']);
        exit;
    }
    
    if (!isset($input['data']) || !is_array($input['data'])) {
        echo json_encode(['success' => false, 'message' => '无效的数据格式']);
        exit;
    }
    
    $validationResults = [];
    $allStudentIds = array_column($input['data'], 'student_id');
    $studentIdCounts = array_count_values($allStudentIds);
    
    foreach ($input['data'] as $index => $studentData) {
        $errors = [];
        $warnings = [];
        
        // 验证必填字段
        if (empty($studentData['student_id'])) {
            $errors[] = '学号不能为空';
        }
        if (empty($studentData['name'])) {
            $errors[] = '姓名不能为空';
        }
        if (empty($studentData['class_name'])) {
            $errors[] = '班级不能为空';
        }
        
        // 验证性别 - 使用统一的字段清理函数
        $gender = CSVEncodingHandler::cleanFieldData($studentData['gender']);
        
        if (!in_array($gender, ['男', '女'])) {
            $errors[] = "性别必须是\"男\"或\"女\"，当前值：\"$gender\"（长度：" . mb_strlen($gender) . "）";
        }
        
        // 验证床号
        if (!empty($studentData['bed_number'])) {
            if (!is_numeric($studentData['bed_number']) || $studentData['bed_number'] < 1 || $studentData['bed_number'] > 8) {
                $errors[] = '床号应为1-8之间的数字';
            }
        }
        
        // 检查文件内学号重复
        if (!empty($studentData['student_id']) && $studentIdCounts[$studentData['student_id']] > 1) {
            $errors[] = '学号在文件中重复出现';
        }
        
        // 检查数据库中是否存在
        if (!empty($studentData['student_id'])) {
            $existingStudent = $student->getStudentById($studentData['student_id']);
            if ($existingStudent) {
                $warnings[] = '学号已存在，将更新现有记录';
            }
        }
        
        $validationResults[] = [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    echo json_encode([
        'success' => true,
        'validation_results' => $validationResults
    ]);
    exit;
}

// 处理学生添加 - 在输出任何内容前处理
if ($action === 'add' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/students.php');
    }
    
    $requiredFields = ['student_id', 'name', 'gender', 'class_name', 'building', 'building_area', 'building_floor', 'room_number', 'bed_number'];
    
    // 检查必填字段
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (empty($missingFields)) {
        $newStudent = [
            'student_id' => sanitizeInput($_POST['student_id']),
            'name' => sanitizeInput($_POST['name']),
            'gender' => sanitizeInput($_POST['gender']),
            'class_name' => sanitizeInput($_POST['class_name']),
            'building' => (int)$_POST['building'],
            'building_area' => sanitizeInput($_POST['building_area']),
            'building_floor' => (int)$_POST['building_floor'],
            'room_number' => sanitizeInput($_POST['room_number']),
            'bed_number' => (int)$_POST['bed_number'],
            'counselor' => sanitizeInput($_POST['counselor'] ?? ''),
            'counselor_phone' => sanitizeInput($_POST['counselor_phone'] ?? '')
        ];
        
        // 记录添加学生请求
        error_log("尝试添加学生: " . json_encode($newStudent));
        
        // 检查学生ID是否已存在
        $existingStudent = $student->getStudentById($newStudent['student_id']);
        if ($existingStudent) {
            $message = "学号 {$newStudent['student_id']} 已存在，无法添加";
            $messageType = 'danger';
            error_log("添加学生失败: " . $message);
        } else {
        if ($student->addStudent($newStudent)) {
            $_SESSION['message'] = '学生添加成功';
            $_SESSION['message_type'] = 'success';
            
            // 重定向回学生列表
            redirect(BASE_URL . '/students.php');
        } else {
                $message = '学生添加失败，请检查输入数据是否符合要求';
            $messageType = 'danger';
                error_log("添加学生失败: 数据验证未通过或数据库错误");
            }
        }
    } else {
        $message = '请填写所有必填字段: ' . implode(', ', $missingFields);
        $messageType = 'danger';
        error_log("添加学生失败: 缺少必填字段 - " . implode(', ', $missingFields));
    }
}

// 处理学生编辑 - 在输出任何内容前处理
if ($action === 'edit' && getRequestMethod() === 'POST') {
    $requiredFields = ['student_id', 'name', 'gender', 'class_name', 'building', 'building_area', 'building_floor', 'room_number', 'bed_number'];
    
    if (validateRequired($requiredFields, $_POST)) {
        $updatedStudent = [
            'student_id' => sanitizeInput($_POST['student_id']),
            'name' => sanitizeInput($_POST['name']),
            'gender' => sanitizeInput($_POST['gender']),
            'class_name' => sanitizeInput($_POST['class_name']),
            'building' => (int)$_POST['building'],
            'building_area' => sanitizeInput($_POST['building_area']),
            'building_floor' => (int)$_POST['building_floor'],
            'room_number' => sanitizeInput($_POST['room_number']),
            'bed_number' => (int)$_POST['bed_number'],
            'counselor' => sanitizeInput($_POST['counselor'] ?? ''),
            'counselor_phone' => sanitizeInput($_POST['counselor_phone'] ?? '')
        ];
        
        if ($student->updateStudent($updatedStudent)) {
            $_SESSION['message'] = '学生信息保存成功';
            $_SESSION['message_type'] = 'success';
            
            // 重定向回学生列表
            redirect(BASE_URL . '/students.php');
        } else {
            $message = '学生信息保存失败，请重试';
            $messageType = 'danger';
        }
    } else {
        $message = '请填写所有必填字段';
        $messageType = 'danger';
    }
}

// 处理导出学生模板 - 在输出任何内容前处理

// 处理导入学生数据 - 在输出任何内容前处理
if ($action === 'import' && getRequestMethod() === 'POST') {
    // 确保日志目录存在
    $logDir = 'logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $logFile = $logDir . '/import.log';
    
    // 传统表单处理代码已删除，现在统一使用AJAX方式
    $_SESSION['message'] = "请使用AJAX上传方式";
    $_SESSION['message_type'] = 'info';
    redirect(BASE_URL . '/students.php');
}

// 处理查看页面的重定向 - 在输出任何内容前处理
if ($action === 'view') {
    $studentId = isset($_GET['student_id']) ? sanitizeInput($_GET['student_id']) : '';
    $studentData = $student->getStudentById($studentId);
    
    if (!$studentData) {
        $_SESSION['message'] = '未找到学生信息';
        $_SESSION['message_type'] = 'danger';
        
        // 重定向回学生列表
        redirect(BASE_URL . '/students.php');
    }
}

// 处理编辑页面的重定向 - 在输出任何内容前处理
if ($action === 'edit' && getRequestMethod() === 'GET') {
    $studentId = isset($_GET['student_id']) ? sanitizeInput($_GET['student_id']) : '';
    $studentData = $student->getStudentById($studentId);
    
    if (!$studentData) {
        $_SESSION['message'] = '未找到学生信息';
        $_SESSION['message_type'] = 'danger';
        
        // 重定向回学生列表
        redirect(BASE_URL . '/students.php');
    }
}

// 加载页头
include 'templates/header.php';

// 从会话中获取消息
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// 根据不同的动作显示不同的视图
switch ($action) {
    case 'add':
        // 显示添加学生表单
        include 'templates/student_form.php';
        break;
        
    case 'edit':
        // 获取学生信息并显示编辑表单
        $studentId = isset($_GET['student_id']) ? sanitizeInput($_GET['student_id']) : '';
        $studentData = $student->getStudentById($studentId);
            include 'templates/student_form.php';
        break;
        
    case 'view':
        // 获取学生信息
        $studentId = isset($_GET['student_id']) ? sanitizeInput($_GET['student_id']) : '';
        $studentData = $student->getStudentById($studentId);
        
            // 获取学生当天状态
            $latestStatus = $checkRecord->getStudentTodayStatus($studentId);
            
            // 获取历史记录
            $history = $checkRecord->getStudentHistory($studentId);
            
            // 显示学生详情页
            include 'templates/student_detail.php';
        break;
        
    case 'import_form':
        // 显示导入学生表单
        include 'templates/student_import_form.php';
        break;
        
    default:
        // 默认显示学生列表
        $studentsData = $student->getAllStudents($page, PAGINATION_LIMIT, $filters);
        $students = $studentsData['students'];
        $pagination = $studentsData['pagination'];
        
        // 获取班级列表(用于筛选)
        $classes = $student->getAllClasses();
        
        // 获取辅导员列表(用于筛选)
        $counselors = $student->getAllCounselors();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">学生管理</h1>
    <div>
        <a href="<?php echo BASE_URL; ?>/students.php?action=add" class="btn btn-success me-2">
            <i class="bi bi-plus-circle"></i> 添加学生
        </a>
        <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#batchUploadModal">
            <i class="fas fa-upload me-1"></i>批量上传
        </button>
        <button type="button" class="btn btn-info me-2" data-bs-toggle="modal" data-bs-target="#downloadStudentInfoModal">
            <i class="fas fa-download me-1"></i>下载学生信息
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-info-circle me-2"></i> <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 筛选表单 -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">筛选条件</h6>
    </div>
    <div class="card-body">
        <form method="get" action="<?php echo BASE_URL; ?>/students.php" class="d-flex align-items-end gap-3">
            <div class="flex-fill">
                <label for="search" class="form-label mb-1">搜索</label>
                <input type="text" class="form-control" id="search" name="search" placeholder="学号/姓名/班级" value="<?php echo isset($filters['search']) ? $filters['search'] : ''; ?>">
            </div>
            <div class="flex-fill">
                <label for="building" class="form-label mb-1">楼栋</label>
                <select class="form-select" id="building" name="building">
                    <option value="">全部</option>
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo isset($filters['building']) && $filters['building'] == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>号楼
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex-fill">
                <label for="building_area" class="form-label mb-1">区域</label>
                <select class="form-select" id="building_area" name="building_area">
                    <option value="">全部</option>
                    <option value="A" <?php echo isset($filters['building_area']) && $filters['building_area'] === 'A' ? 'selected' : ''; ?>>A区</option>
                    <option value="B" <?php echo isset($filters['building_area']) && $filters['building_area'] === 'B' ? 'selected' : ''; ?>>B区</option>
                </select>
            </div>
            <div class="flex-fill">
                <label for="building_floor" class="form-label mb-1">楼层</label>
                <select class="form-select" id="building_floor" name="building_floor">
                    <option value="">全部</option>
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo isset($filters['building_floor']) && $filters['building_floor'] == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>层
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex-fill">
                <label for="class_name" class="form-label mb-1">班级</label>
                <select class="form-select" id="class_name" name="class_name">
                    <option value="">全部班级</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class; ?>" <?php echo isset($filters['class_name']) && $filters['class_name'] === $class ? 'selected' : ''; ?>>
                            <?php echo $class; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-fill">
                <label for="counselor" class="form-label mb-1">辅导员</label>
                <select class="form-select" id="counselor" name="counselor">
                    <option value="">全部辅导员</option>
                    <?php foreach ($counselors as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['counselor']); ?>" 
                                <?php echo isset($filters['counselor']) && $filters['counselor'] === $c['counselor'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['counselor']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-shrink-0">
                <button type="submit" class="btn btn-primary">筛选</button>
                <a href="<?php echo BASE_URL; ?>/students.php" class="btn btn-secondary ms-2">重置</a>
            </div>
        </form>
    </div>
</div>

<!-- 学生列表 -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="m-0 font-weight-bold text-primary">学生列表</h6>
            <div>
                <span class="badge bg-primary rounded-pill">共 <?php echo $pagination['total']; ?> 条记录</span>
    </div>
        </div>
        <div class="d-flex align-items-center">
            <button type="button" id="batchDeleteBtn" class="btn btn-danger btn-sm me-2" disabled>
                <i class="fas fa-trash me-1"></i>批量删除
            </button>
            <button type="button" id="deleteAllBtn" class="btn btn-outline-danger btn-sm me-2">
                <i class="fas fa-trash-alt me-1"></i>全部删除
            </button>
            <span id="selectedCount" class="text-muted small">已选择 <span class="fw-bold">0</span> 名学生</span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                <thead class="table-light">
                    <tr>
                        <th class="text-center">
                            <input type="checkbox" id="selectAll" class="form-check-input">
                        </th>
                        <th class="text-center">学号</th>
                        <th class="text-center">姓名</th>
                        <th class="text-center">性别</th>
                        <th class="text-center">班级</th>
                        <th class="text-center">宿舍</th>
                        <th class="text-center">辅导员</th>
                        <th class="text-center">在寝状态</th>
                        <th class="text-center">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($students)): ?>
                        <?php foreach ($students as $student): ?>
                            <?php 
                            // 获取学生当天的状态，而不是历史最新状态
                            $today = date('Y-m-d');
                            $todayStatus = $checkRecord->getAllStudentsStatusByDate($today, ['student_id' => $student['student_id']]);
                            $status = !empty($todayStatus) ? $todayStatus[0]['status'] : '未签到';
                            $statusClass = getStatusClass($status);
                            ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input student-checkbox" 
                                           value="<?php echo $student['student_id']; ?>" 
                                           data-student-name="<?php echo $student['name']; ?>">
                                </td>
                                <td class="text-center"><?php echo $student['student_id']; ?></td>
                                <td class="text-center"><?php echo $student['name']; ?></td>
                                <td class="text-center"><?php echo $student['gender']; ?></td>
                                <td class="text-center"><?php echo $student['class_name']; ?></td>
                                <td class="text-center"><?php echo $student['building']; ?>号楼<?php echo $student['building_area']; ?>区<?php echo $student['building_floor']; ?>层<?php echo $student['room_number']; ?>-<?php echo $student['bed_number']; ?>床</td>
                                <td class="text-center"><?php echo $student['counselor']; ?></td>
                                <td class="text-center">
                                    <?php
                                    $statusColors = [
                                        '在寝' => 'success',
                                        '离寝' => 'danger', 
                                        '请假' => 'warning',
                                        '未签到' => 'secondary'
                                    ];
                                    $statusColor = $statusColors[$status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $statusColor; ?>"><?php echo $status; ?></span>
                                </td>
                                <td class="text-center">
                                        <a href="<?php echo BASE_URL; ?>/students.php?action=view&student_id=<?php echo $student['student_id']; ?>" 
                                       class="btn btn-sm btn-info me-1">查看</a>
                                        <a href="<?php echo BASE_URL; ?>/students.php?action=edit&student_id=<?php echo $student['student_id']; ?>" 
                                       class="btn btn-sm btn-primary me-1">编辑</a>
                                        <button type="button" class="btn btn-sm btn-danger delete-student-btn" 
                                            data-student-id="<?php echo $student['student_id']; ?>" 
                                            data-student-name="<?php echo $student['name']; ?>">删除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 mb-2 d-block"></i>
                                暂无学生数据
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
        </div>
        
        <!-- 分页 -->
        <?php if ($pagination['totalPages'] > 1): ?>
    <div class="d-flex justify-content-center">
        <?php 
        // 构建包含筛选参数的URL
        $paginationUrl = BASE_URL . '/students.php';
        $queryParams = [];
        if (!empty($filters['search'])) $queryParams['search'] = $filters['search'];
        if (!empty($filters['building'])) $queryParams['building'] = $filters['building'];
        if (!empty($filters['building_area'])) $queryParams['building_area'] = $filters['building_area'];
        if (!empty($filters['building_floor'])) $queryParams['building_floor'] = $filters['building_floor'];
        if (!empty($filters['class_name'])) $queryParams['class_name'] = $filters['class_name'];
        if (!empty($filters['counselor'])) $queryParams['counselor'] = $filters['counselor'];
        
        if (!empty($queryParams)) {
            $paginationUrl .= '?' . http_build_query($queryParams) . '&';
        } else {
            $paginationUrl .= '?';
        }
        
        echo generatePagination($pagination['currentPage'], $pagination['totalPages'], $paginationUrl); 
        ?>
        </div>
        <?php endif; ?>

<!-- 全局删除确认对话框 -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-labelledby="deleteStudentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteStudentModalLabel">确认删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <p>确定要删除学生 <span id="studentNameToDelete"></span> (<span id="studentIdToDelete"></span>) 吗？此操作不可恢复。</p>
            </div>
            <div class="modal-footer">
                <form id="deleteStudentForm" method="post" action="<?php echo BASE_URL; ?>/students.php?action=delete" class="delete-student-form">
                    <input type="hidden" name="student_id" id="deleteStudentId">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="button" class="btn btn-secondary" id="cancelDeleteBtn">取消</button>
                    <button type="submit" class="btn btn-danger">确认删除</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 批量删除确认对话框 -->
<div class="modal fade" id="batchDeleteModal" tabindex="-1" aria-labelledby="batchDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchDeleteModalLabel">确认批量删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>危险操作！</strong>
                </div>
                <p>确定要删除选中的 <strong id="batchDeleteCount">0</strong> 条学生记录吗？</p>
                <p class="text-danger">此操作无法撤销！</p>
            </div>
            <div class="modal-footer">
                <form id="batchDeleteForm" method="post" action="<?php echo BASE_URL; ?>/students.php?action=batch_delete">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="student_ids" id="batchDeleteIds">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger">确认删除</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 全部删除确认对话框 -->
<div class="modal fade" id="deleteAllModal" tabindex="-1" aria-labelledby="deleteAllModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteAllModalLabel">全部删除确认</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>极度危险操作！</strong>
                </div>
                <p>确定要删除<strong>所有</strong>学生记录吗？</p>
                <p>这将删除当前系统中的全部 <strong><?php echo $pagination['total']; ?></strong> 条学生记录。</p>
                <p class="text-danger">此操作无法撤销！</p>
                <hr>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirmDeleteAllStudents">
                    <label class="form-check-label text-danger" for="confirmDeleteAllStudents">
                        我确认要删除所有学生记录
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteAllStudentsBtn" disabled>
                    <i class="fas fa-trash-alt me-1"></i>确认删除全部
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 批量上传模态框 -->
<div class="modal fade" id="batchUploadModal" tabindex="-1" aria-labelledby="batchUploadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchUploadModalLabel">批量上传学生信息</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" tabindex="-1"></button>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/students.php?action=batch_upload" enctype="multipart/form-data" id="studentBatchUploadForm" data-custom-validation="true">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- 使用说明 -->
                    <div class="alert alert-info mb-4">
                        <h6><i class="fas fa-info-circle me-2"></i>使用说明</h6>
                        <ul class="mb-2">
                            <li>请先下载CSV模板文件，按照模板格式填写数据</li>
                            <li>CSV文件必须包含：学号、姓名、性别、班级、宿舍信息等</li>
                            <li>系统自动检测并转换文件编码，无需手动处理UTF-8</li>
                            <li>如果学号已存在，系统将更新对应的学生信息</li>
                        </ul>
                        <div class="text-center">
                            <a href="<?php echo BASE_URL; ?>/api/download_student_template.php" class="btn btn-sm btn-outline-primary" target="_blank" tabindex="-1">
                                <i class="fas fa-download me-1"></i>下载CSV模板
                            </a>
                        </div>
                    </div>
                    
                    <!-- 验证要求 -->
                    <div class="alert alert-warning mb-4">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>必须满足的条件</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-danger">必填字段：</h6>
                                <ul class="mb-2">
                                    <li><strong>学号</strong> - 不能为空，必须唯一</li>
                                    <li><strong>姓名</strong> - 不能为空</li>
                                    <li><strong>性别</strong> - 男/女</li>
                                    <li><strong>班级</strong> - 不能为空</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-info">其他要求：</h6>
                                <ul class="mb-2">
                                    <li>宿舍信息必须完整</li>
                                    <li>床号范围1-8</li>
                                    <li>CSV文件大小不超过10MB</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 文件上传 -->
                    <div class="mb-4">
                        <label for="student_batch_file" class="form-label">选择CSV文件 <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="student_batch_file" name="student_batch_file" 
                               accept=".csv,text/csv" required tabindex="-1">
                        <div class="form-text">支持的文件格式：CSV (.csv)</div>
                    </div>
                    
                    <!-- 预览行数选择器 -->
                    <div class="mb-4">
                        <label for="student_preview_limit" class="form-label">预览行数</label>
                        <select class="form-select" id="student_preview_limit" name="student_preview_limit">
                            <option value="10">10行</option>
                            <option value="50" selected>50行（推荐）</option>
                            <option value="100">100行</option>
                            <option value="200">200行</option>
                            <option value="0">全部数据</option>
                        </select>
                        <div class="form-text">选择上传前要预览的数据行数，全部数据适用于小文件</div>
                    </div>
                    
                    <!-- 文件预览区域 -->
                    <div id="student_file_preview" class="mb-3" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">文件预览</h6>
                            <button type="button" class="btn btn-sm btn-outline-info" id="student_validate_data_btn" tabindex="-1">
                                <i class="fas fa-check-circle me-1"></i>在线验证
                            </button>
                        </div>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>学号</th>
                                        <th>姓名</th>
                                        <th>性别</th>
                                        <th>班级</th>
                                        <th>宿舍楼</th>
                                        <th>房间号</th>
                                        <th>床号</th>
                                        <th id="student_validation_header" style="display: none;">验证状态</th>
                                    </tr>
                                </thead>
                                <tbody id="student_preview_tbody">
                                </tbody>
                            </table>
                        </div>
                        <div id="student_preview_summary" class="text-muted small"></div>
                        <div id="student_validation_result" class="mt-3" style="display: none;"></div>
                    </div>
                    
                    <!-- 上传选项 -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="student_update_existing" name="student_update_existing" checked>
                                <label class="form-check-label" for="student_update_existing">
                                    更新已存在的学生信息
                                </label>
                                <small class="form-text text-muted d-block">允许更新数据库中已存在的学生记录</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" tabindex="-1">取消</button>
                    <button type="button" class="btn btn-primary" id="studentUploadBtn" tabindex="-1">
                        <i class="fas fa-upload me-1"></i>开始上传
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 上传结果模态框 -->
<div class="modal fade" id="studentUploadResultModal" tabindex="-1" aria-labelledby="studentUploadResultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="studentUploadResultModalLabel">上传结果</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="studentUploadResultContent">
                <!-- 结果内容将通过JavaScript动态填充 -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">确定</button>
            </div>
        </div>
    </div>
</div>

<?php
        break;
}
?>

<style>
/* 防止模态框闪屏的CSS */
.modal.fade .modal-dialog {
    transition: transform 0.3s ease-out;
}

.modal.show .modal-dialog {
    transform: none;
}

/* 确保平滑的显示和隐藏 */
.modal-backdrop.fade {
        opacity: 0;
}

.modal-backdrop.show {
    opacity: 0.5;
}
</style>

<script>
// 全局变量和函数
let deleteModal;

// 关闭删除模态框的函数
function closeDeleteModal() {
    if (deleteModal) {
        deleteModal.hide();
        
        // 强制清理模态框状态
        setTimeout(() => {
            const modalElement = document.getElementById('deleteStudentModal');
            if (modalElement) {
                modalElement.style.display = 'none';
                modalElement.classList.remove('show');
                modalElement.setAttribute('aria-hidden', 'true');
                modalElement.removeAttribute('aria-modal');
                modalElement.removeAttribute('role');
            }
            
            // 清理body状态
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // 移除backdrop
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
        }, 150);
    }
    return false;
}

// 删除学生功能
document.addEventListener('DOMContentLoaded', function() {
    // 检查当前页面是否需要删除功能（编辑页面不需要）
    const isEditPage = window.location.href.includes('action=edit');
    if (isEditPage) {
        console.log('编辑页面，跳过删除功能初始化');
        return;
    }
    
    // 获取所有删除按钮
    const deleteButtons = document.querySelectorAll('.delete-student-btn');
    const deleteModalElement = document.getElementById('deleteStudentModal');
    
    // 检查模态框元素是否存在
    if (!deleteModalElement) {
        console.log('删除模态框元素未找到，跳过删除功能初始化');
        return;
    }
    
    // 确保Bootstrap已加载
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap未加载，无法初始化删除模态框');
        return;
    }
    
    try {
        // 配置模态框选项
        deleteModal = new bootstrap.Modal(deleteModalElement, {
            backdrop: true,     // 允许点击背景关闭
            keyboard: true,     // 允许ESC关闭
            focus: true         // 获取焦点
        });
    } catch (error) {
        console.error('删除模态框初始化失败:', error);
        return;
    }
    
    // 删除按钮点击事件
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault(); // 阻止默认行为
            e.stopPropagation(); // 阻止事件冒泡
            
            const studentId = this.getAttribute('data-student-id');
            const studentName = this.getAttribute('data-student-name');
            
            // 设置模态框内容
            document.getElementById('studentIdToDelete').textContent = studentId;
            document.getElementById('studentNameToDelete').textContent = studentName;
            document.getElementById('deleteStudentId').value = studentId;
            
            // 显示模态框
            deleteModal.show();
            
            return false;
        });
    });
    
    // 取消按钮事件处理
    const cancelButton = document.getElementById('cancelDeleteBtn');
    if (cancelButton) {
        cancelButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeDeleteModal();
            return false;
        });
    }

    // 头部关闭按钮事件处理
    const headerCloseButton = deleteModalElement.querySelector('.btn-close');
    if (headerCloseButton) {
        headerCloseButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeDeleteModal();
            return false;
        });
    }
    
    // 模态框隐藏后的清理工作
    deleteModalElement.addEventListener('hidden.bs.modal', function() {
        // 清理表单数据
        document.getElementById('deleteStudentId').value = '';
        document.getElementById('studentIdToDelete').textContent = '';
        document.getElementById('studentNameToDelete').textContent = '';
    });
    
    // 批量删除功能 - 仅在列表页面初始化
    const selectAllCheckbox = document.getElementById('selectAll');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox');
    const batchDeleteBtn = document.getElementById('batchDeleteBtn');
    const selectedCountElement = document.getElementById('selectedCount');
    
    // 检查批量删除模态框是否存在
    const batchDeleteModalElement = document.getElementById('batchDeleteModal');
    if (!batchDeleteModalElement) {
        console.log('批量删除模态框未找到，跳过批量删除功能');
        return;
    }
    
    const batchDeleteModal = new bootstrap.Modal(batchDeleteModalElement);
    
    // 全选/取消全选
    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        studentCheckboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        updateBatchDeleteUI();
    });
    
    // 单个复选框变化
    studentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectAllState();
            updateBatchDeleteUI();
        });
    });
    
    // 更新全选状态
    function updateSelectAllState() {
        const checkedCount = document.querySelectorAll('.student-checkbox:checked').length;
        const totalCount = studentCheckboxes.length;
        
        if (checkedCount === 0) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = false;
        } else if (checkedCount === totalCount) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = true;
                    } else {
            selectAllCheckbox.indeterminate = true;
            selectAllCheckbox.checked = false;
        }
    }
    
    // 更新批量删除UI
    function updateBatchDeleteUI() {
        const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
        const count = checkedBoxes.length;
        
        // 更新按钮状态
        batchDeleteBtn.disabled = count === 0;
        
        // 更新选择计数
        selectedCountElement.querySelector('.fw-bold').textContent = count;
    }
    
    // 批量删除按钮点击
    batchDeleteBtn.addEventListener('click', function() {
        const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
        const studentIds = [];
        const studentList = [];
        
        checkedBoxes.forEach(checkbox => {
            const studentId = checkbox.value;
            const studentName = checkbox.getAttribute('data-student-name');
            studentIds.push(studentId);
            studentList.push(`${studentName} (${studentId})`);
        });
        
        if (studentIds.length === 0) {
            alert('请先选择要删除的学生');
            return;
        }
        
        // 更新模态框内容
        document.getElementById('batchDeleteCount').textContent = studentIds.length;
        document.getElementById('batchDeleteIds').value = studentIds.join(',');
        
        batchDeleteModal.show();
    });
    
    // 全部删除功能
    const deleteAllBtn = document.getElementById('deleteAllBtn');
    const deleteAllModalElement = document.getElementById('deleteAllModal');
    
    if (!deleteAllModalElement) {
        console.log('全部删除模态框未找到，跳过全部删除功能');
        return;
    }
    
    const deleteAllModal = new bootstrap.Modal(deleteAllModalElement);
    const confirmDeleteAllCheckbox = document.getElementById('confirmDeleteAllStudents');
    const confirmDeleteAllBtn = document.getElementById('confirmDeleteAllStudentsBtn');
    
    // 全部删除按钮点击
    if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', function() {
            deleteAllModal.show();
        });
    }
    
    // 确认复选框变化
    if (confirmDeleteAllCheckbox && confirmDeleteAllBtn) {
        confirmDeleteAllCheckbox.addEventListener('change', function() {
            confirmDeleteAllBtn.disabled = !this.checked;
        });
        
        // 确认全部删除
        confirmDeleteAllBtn.addEventListener('click', function() {
            if (!confirmDeleteAllCheckbox.checked) {
                alert('请先确认删除操作');
                return;
            }
            
            // 禁用按钮并显示加载状态
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>删除中...';
            
            // 发送删除请求
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');
            
            fetch('<?php echo BASE_URL; ?>/students.php?action=delete_all', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('删除失败: ' + data.message);
                    // 恢复按钮状态
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-trash-alt me-1"></i>确认删除全部';
                }
            })
            .catch(error => {
                console.error('删除错误:', error);
                alert('删除过程中发生错误');
                // 恢复按钮状态
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-trash-alt me-1"></i>确认删除全部';
            });
        });
    }
    
    // 学生批量上传功能
    const studentFileInput = document.getElementById('student_batch_file');
    const studentPreviewDiv = document.getElementById('student_file_preview');
    const studentPreviewTbody = document.getElementById('student_preview_tbody');
    const studentPreviewSummary = document.getElementById('student_preview_summary');
    const studentValidateBtn = document.getElementById('student_validate_data_btn');
    const studentValidationHeader = document.getElementById('student_validation_header');
    const studentValidationResult = document.getElementById('student_validation_result');
    const studentUploadForm = document.getElementById('studentBatchUploadForm');
    const studentUploadBtn = document.getElementById('studentUploadBtn');
    
    if (studentFileInput) {
        studentFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) {
                studentPreviewDiv.style.display = 'none';
                return;
            }
            
            if (!file.name.toLowerCase().endsWith('.csv')) {
                alert('请选择CSV格式的文件');
                return;
            }
            
            // 显示加载状态
            studentPreviewDiv.style.display = 'block';
            studentPreviewTbody.innerHTML = '<tr><td colspan="7" class="text-center"><i class="fas fa-spinner fa-spin"></i> 正在处理文件编码...</td></tr>';
            studentPreviewSummary.textContent = '正在检测文件编码...';
            
            // 使用Ajax发送到后端处理编码
            const formData = new FormData();
            formData.append('preview_file', file);
            formData.append('preview_type', 'student');
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            // 获取预览行数限制
            const previewLimit = document.getElementById('student_preview_limit').value;
            formData.append('preview_limit', previewLimit);
            
            fetch('<?php echo BASE_URL; ?>/api/preview_csv_unified.php?t=' + Date.now(), {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('学生管理预览响应:', data);
                
                // 清空加载状态
                studentPreviewTbody.innerHTML = '';
                studentValidationHeader.style.display = 'none';
                studentValidationResult.style.display = 'none';
                
                if (data.success) {
                    // 显示预览数据
                    data.data.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.setAttribute('data-row-data', JSON.stringify({
                            student_id: row.student_id || '',
                            name: row.name || '',
                            gender: row.gender || '',
                            class_name: row.class_name || '',
                            building_number: row.building || '',
                            room_number: row.room_number || '',
                            bed_number: row.bed_number || '',
                            counselor_name: row.counselor || '',
                            counselor_phone: row.counselor_phone || ''
                        }));
                        
                        tr.innerHTML = `
                            <td>${row.student_id || ''}</td>
                            <td>${row.name || ''}</td>
                            <td>${row.gender || ''}</td>
                            <td>${row.class_name || ''}</td>
                            <td>${row.building || ''}</td>
                            <td>${row.room_number || ''}</td>
                            <td>${row.bed_number || ''}</td>
                            <td class="validation-status" style="display: none;"></td>
                        `;
                        studentPreviewTbody.appendChild(tr);
                    });
                    
                    // 更新统计信息
                    let summaryText = `共 ${data.total} 行数据`;
                    if (data.is_full_preview) {
                        summaryText += `，已显示全部`;
                    } else if (data.total > data.preview_count) {
                        summaryText += `，已预览 ${data.preview_count} 行`;
                    }
                    if (data.encoding_detected && data.encoding_detected !== 'UTF-8') {
                        summaryText += ` (已从${data.encoding_detected}转换为UTF-8)`;
                    }
                    studentPreviewSummary.textContent = summaryText;
                } else {
                    // 显示错误信息
                    studentPreviewTbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> ${data.message}</td></tr>`;
                    studentPreviewSummary.textContent = '预览失败';
                }
            })
            .catch(error => {
                console.error('学生管理预览请求失败:', error);
                studentPreviewTbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> 网络请求失败</td></tr>';
                studentPreviewSummary.textContent = '预览失败';
            });
        });
    }
    
    // 预览行数选择器变化时自动重新预览
    const studentPreviewLimitSelect = document.getElementById('student_preview_limit');
    if (studentPreviewLimitSelect && studentFileInput) {
        studentPreviewLimitSelect.addEventListener('change', function() {
            // 如果已经选择了文件，自动重新预览
            if (studentFileInput.files[0]) {
                // 触发文件输入的change事件来重新预览
                const event = new Event('change');
                studentFileInput.dispatchEvent(event);
            }
        });
    }
    
    // 在线验证按钮
    if (studentValidateBtn) {
        studentValidateBtn.addEventListener('click', function() {
            const file = studentFileInput.files[0];
            if (!file) {
                alert('请先选择文件');
                return;
            }
            
            // 显示加载状态
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>验证中...';
            
            // 使用统一的AJAX验证处理
            const formData = new FormData();
            formData.append('validate_file', file);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch('<?php echo BASE_URL; ?>/students.php?action=validate_csv', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const dataToValidate = data.data;
                
                // 发送验证请求
                fetch('<?php echo BASE_URL; ?>/students.php?action=validate_student_data', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        data: dataToValidate,
                        csrf_token: '<?php echo generateCSRFToken(); ?>'
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        updateStudentValidationDisplay(result.validation_results);
                        showStudentValidationSummary(result.validation_results);
                    } else {
                        alert('验证失败：' + result.message);
                    }
                })
                .catch(error => {
                    console.error('验证错误:', error);
                    alert('验证过程中发生错误');
                })
                .finally(() => {
                    // 恢复按钮状态
                    studentValidateBtn.disabled = false;
                    studentValidateBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>在线验证';
                });
                } else {
                    alert('文件处理失败：' + data.message);
                    // 恢复按钮状态
                    studentValidateBtn.disabled = false;
                    studentValidateBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>在线验证';
                }
            })
            .catch(error => {
                console.error('文件处理错误:', error);
                alert('文件处理过程中发生错误');
                // 恢复按钮状态
                studentValidateBtn.disabled = false;
                studentValidateBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>在线验证';
            });
        });
    }
    
    // 上传按钮点击处理
    if (studentUploadBtn) {
        studentUploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const file = studentFileInput.files[0];
            if (!file) {
                alert('请先选择文件');
                return;
            }
            
            // 显示上传状态
            studentUploadBtn.disabled = true;
            studentUploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>上传中...';
            
            const formData = new FormData(studentUploadForm);
            
            fetch(studentUploadForm.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                console.log('上传返回结果:', result);
                
                // 检查是否有数据被处理（不管成功失败）
                if (result.total && result.total > 0) {
                    console.log('准备显示结果弹窗');
                    
                    // 转换数据格式以匹配弹窗函数
                    const resultData = {
                        total: result.total || 0,
                        success: result.success || 0,  // result.success 是成功数量
                        fail: result.fail || 0,
                        errors: result.errors || []
                    };
                    
                    console.log('转换后的数据:', resultData);
                    
                    try {
                        console.log('准备调用 showStudentUploadResult');
                        showStudentUploadResult(resultData);
                        console.log('showStudentUploadResult 调用完成');
                    } catch (error) {
                        console.error('showStudentUploadResult 调用出错:', error);
                        alert('显示结果时出错: ' + error.message);
                    }
                    
                    // 关闭上传模态框
                    const uploadModal = bootstrap.Modal.getInstance(document.getElementById('batchUploadModal'));
                    if (uploadModal) uploadModal.hide();
                    
                } else {
                    alert('上传失败：' + (result.message || '未知错误'));
                }
            })
            .catch(error => {
                console.error('上传错误:', error);
                alert('上传过程中发生错误');
            })
            .finally(() => {
                // 恢复按钮状态
                studentUploadBtn.disabled = false;
                studentUploadBtn.innerHTML = '<i class="fas fa-upload me-1"></i>开始上传';
            });
        });
    }
    
    // 删除重复的上传按钮处理逻辑
    
    // 辅助函数
    function parseCSVLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"') {
                inQuotes = !inQuotes;
            } else if (char === ',' && !inQuotes) {
                result.push(current.trim());
                current = '';
            } else {
                current += char;
            }
        }
        result.push(current.trim());
        
        return result;
    }
    
    function updateStudentValidationDisplay(validationResults) {
        studentValidationHeader.style.display = 'table-cell';
        const statusCells = document.querySelectorAll('.validation-status');
        
        statusCells.forEach((cell, index) => {
            cell.style.display = 'table-cell';
            if (index < validationResults.length) {
                const result = validationResults[index];
                if (result.valid) {
                    if (result.warnings && result.warnings.length > 0) {
                        cell.innerHTML = '<span class="badge bg-warning">⚠️ 警告</span>';
                        cell.title = result.warnings.join('; ');
                    } else {
                        cell.innerHTML = '<span class="badge bg-success">✅ 通过</span>';
                    }
                } else {
                    cell.innerHTML = '<span class="badge bg-danger">❌ 失败</span>';
                    cell.title = result.errors.join('; ');
                }
            }
        });
    }
    
    function showStudentValidationSummary(validationResults) {
        let perfectCount = 0;
        let warningCount = 0;
        let errorCount = 0;
        
        validationResults.forEach(result => {
            if (result.valid) {
                if (result.warnings && result.warnings.length > 0) {
                    warningCount++;
                } else {
                    perfectCount++;
                }
            } else {
                errorCount++;
            }
        });
        
        let summaryHtml = `
            <div class="alert alert-info">
                <h6><i class="fas fa-chart-bar me-2"></i>验证结果统计</h6>
                <div class="row">
                    <div class="col-md-4">
                        <span class="badge bg-success me-2">${perfectCount}</span>完全通过
                    </div>
                    <div class="col-md-4">
                        <span class="badge bg-warning me-2">${warningCount}</span>有警告
                    </div>
                    <div class="col-md-4">
                        <span class="badge bg-danger me-2">${errorCount}</span>验证失败
                    </div>
                </div>
        `;
        
        if (errorCount > 0) {
            summaryHtml += '<div class="mt-2"><strong class="text-danger">注意：存在验证失败的记录，建议修正后再上传</strong></div>';
        }
        
        summaryHtml += '</div>';
        
        studentValidationResult.innerHTML = summaryHtml;
        studentValidationResult.style.display = 'block';
    }
    
    // 删除重复的旧版本showStudentUploadResult函数
    
    // 模态框重置功能
    const batchUploadModal = document.getElementById('batchUploadModal');
    if (batchUploadModal) {
        // 立即设置初始状态 - 确保页面加载时模态框处于正确的可访问性状态
        const setModalAccessibility = function(isVisible) {
            const focusableElements = batchUploadModal.querySelectorAll('button, input, select, textarea, a[href]');
            if (isVisible) {
                // 模态框可见时：移除aria-hidden，允许内部元素获得焦点
                batchUploadModal.removeAttribute('aria-hidden');
                focusableElements.forEach(element => element.removeAttribute('tabindex'));
            } else {
                // 模态框隐藏时：设置aria-hidden，阻止内部元素获得焦点
                batchUploadModal.setAttribute('aria-hidden', 'true');
                focusableElements.forEach(element => element.setAttribute('tabindex', '-1'));
            }
        };
        
        // 初始化时设置为隐藏状态 - 使用延迟确保DOM完全加载
        setTimeout(function() {
            setModalAccessibility(false);
        }, 100);
        
        // 修复可访问性问题 - 模态框显示时设置正确的tabindex
        batchUploadModal.addEventListener('shown.bs.modal', function() {
            setModalAccessibility(true);
        });
        
        // 添加额外的事件监听器确保可访问性状态正确
        batchUploadModal.addEventListener('hide.bs.modal', function() {
            // 模态框开始隐藏时立即设置可访问性状态
            setModalAccessibility(false);
        });
        
        batchUploadModal.addEventListener('hidden.bs.modal', function() {
            // 确保模态框完全隐藏后状态正确
            setModalAccessibility(false);
            
            // 重置功能
            // 重置表单
            const form = document.getElementById('studentBatchUploadForm');
            if (form) form.reset();
            
            // 隐藏预览区域
            if (studentPreviewDiv) studentPreviewDiv.style.display = 'none';
            if (studentValidationResult) studentValidationResult.style.display = 'none';
            if (studentValidationHeader) studentValidationHeader.style.display = 'none';
            
            // 清空预览内容
            if (studentPreviewTbody) studentPreviewTbody.innerHTML = '';
            if (studentPreviewSummary) studentPreviewSummary.textContent = '';
            
            // 重置按钮状态
            if (studentUploadBtn) {
                studentUploadBtn.disabled = false;
                studentUploadBtn.innerHTML = '<i class="fas fa-upload me-1"></i>开始上传';
            }
            if (studentValidateBtn) {
                studentValidateBtn.disabled = false;
                studentValidateBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>在线验证';
            }
        });
    }
});

// HTML转义函数
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 显示学生上传结果（美观的模态框版本）
function showStudentUploadResult(data) {
    console.log('showStudentUploadResult 被调用，数据:', data);
    
    const successRate = data.total > 0 ? Math.round((data.success / data.total) * 100) : 0;
    const isSuccess = data.fail === 0;
    
    console.log('计算结果 - 成功率:', successRate, '是否成功:', isSuccess);
    
    // 生成统计卡片
    const statsHtml = `
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body py-3">
                        <div class="h2 text-primary mb-1">${data.total || 0}</div>
                        <div class="text-muted small">总记录数</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body py-3">
                        <div class="h2 text-success mb-1">${data.success || 0}</div>
                        <div class="text-muted small">成功导入</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body py-3">
                        <div class="h2 text-danger mb-1">${data.fail || 0}</div>
                        <div class="text-muted small">导入失败</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body py-3">
                        <div class="h2 text-info mb-1">${successRate}%</div>
                        <div class="text-muted small">成功率</div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 生成结果总结
    const summaryHtml = `
        <div class="alert alert-${isSuccess ? 'success' : 'warning'} mb-3">
            <div class="d-flex align-items-center">
                <i class="fas fa-${isSuccess ? 'check-circle' : 'exclamation-triangle'} fa-2x me-3"></i>
                <div>
                    <h6 class="mb-1">${isSuccess ? '🎉 批量上传成功完成!' : '⚠️ 批量上传部分完成'}</h6>
                    <p class="mb-0">
                        ${isSuccess 
                            ? `恭喜！所有 ${data.total} 条记录都已成功导入系统。`
                            : `共处理 ${data.total} 条记录，成功 ${data.success} 条，失败 ${data.fail} 条。`
                        }
                    </p>
                </div>
            </div>
        </div>
    `;
    
    // 生成错误详情（如果有）
    let errorsHtml = '';
    if (data.errors && data.errors.length > 0) {
        errorsHtml = `
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        错误详情 (${data.errors.length} 个问题)
                    </h6>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <ol class="mb-0">
                        ${data.errors.slice(0, 20).map(error => `
                            <li class="mb-2">
                                <span class="text-danger">${escapeHtml(error)}</span>
                            </li>
                        `).join('')}
                        ${data.errors.length > 20 ? `
                            <li class="text-muted">
                                <em>还有 ${data.errors.length - 20} 个错误未显示...</em>
                            </li>
                        ` : ''}
                    </ol>
                </div>
            </div>
            
            <div class="alert alert-info mt-3 mb-0">
                <h6><i class="fas fa-lightbulb me-2"></i>常见问题解决方案</h6>
                <ul class="mb-0">
                    <li><strong>学号重复：</strong>检查CSV文件中是否有重复的学号</li>
                    <li><strong>性别格式错误：</strong>性别字段只能填写"男"或"女"</li>
                    <li><strong>必填字段为空：</strong>学号、姓名、性别不能为空</li>
                    <li><strong>数据格式错误：</strong>请检查楼号、房间号、床位号是否为数字</li>
                </ul>
            </div>
        `;
    } else if (isSuccess) {
        errorsHtml = `
            <div class="alert alert-success mb-0">
                <div class="text-center">
                    <i class="fas fa-thumbs-up fa-3x text-success mb-3"></i>
                    <h5>完美！</h5>
                    <p class="mb-0">所有数据都已成功导入，可以在上方列表中查看。页面将自动刷新以显示最新数据。</p>
                </div>
            </div>
        `;
    }
    
    // 组合完整内容
    const modalContent = statsHtml + summaryHtml + errorsHtml;
    
    // 更新模态框标题
    const modalTitle = document.getElementById('studentUploadResultModalLabel');
    if (!modalTitle) {
        console.error('找不到模态框标题元素: studentUploadResultModalLabel');
        alert('模态框标题元素未找到');
        return;
    }
    
    modalTitle.innerHTML = `
        <i class="fas fa-${isSuccess ? 'check-circle text-success' : 'chart-line text-warning'} me-2"></i>
        学生批量上传${isSuccess ? '成功' : '结果'}
    `;
    
    // 插入内容到模态框
    const modalBody = document.getElementById('studentUploadResultContent');
    if (!modalBody) {
        console.error('找不到模态框内容元素: studentUploadResultContent');
        alert('模态框内容元素未找到');
        return;
    }
    
    modalBody.innerHTML = modalContent;
    
    // 显示模态框
    const modalElement = document.getElementById('studentUploadResultModal');
    if (!modalElement) {
        console.error('找不到模态框元素: studentUploadResultModal');
        alert('模态框元素未找到');
        return;
    }
    
    console.log('准备显示模态框');
    
    // 确保Bootstrap Modal已加载并且元素存在
    try {
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap未加载');
            alert('Bootstrap库未加载，无法显示模态框');
            return;
        }
        
        const resultModal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: false
        });
        resultModal.show();
        console.log('模态框显示命令已发出');
    } catch (error) {
        console.error('模态框创建失败:', error);
        alert('模态框创建失败: ' + error.message);
        return;
    }
    
    // 如果有成功的记录，在模态框关闭后刷新页面
    if (data.success > 0) {
        document.getElementById('studentUploadResultModal').addEventListener('hidden.bs.modal', function() {
            window.location.reload();
        }, { once: true });
    }
}
</script>

<!-- 下载学生信息模态框 -->
<div class="modal fade" id="downloadStudentInfoModal" tabindex="-1" aria-labelledby="downloadStudentInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="downloadStudentInfoModalLabel">
                    <i class="fas fa-download me-2"></i>下载学生信息
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <h6><i class="fas fa-info-circle me-2"></i>说明</h6>
                    <p class="mb-2">将下载包含以下字段的CSV文件：</p>
                    <ul class="mb-0">
                        <li>宿舍楼号</li>
                        <li>人/区号 (A区/B区)</li>
                        <li>学号</li>
                        <li>姓名</li>
                        <li>班级</li>
                        <li>辅导员</li>
                    </ul>
                </div>
                
                <form id="downloadStudentInfoForm">
                    <div class="row">
                        <div class="col-md-6">
                            <label for="download_building" class="form-label">楼栋</label>
                            <select class="form-select" id="download_building" name="building">
                                <option value="">全部楼栋</option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?>号楼</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="download_building_area" class="form-label">区域</label>
                            <select class="form-select" id="download_building_area" name="building_area">
                                <option value="">全部区域</option>
                                <option value="A">A区</option>
                                <option value="B">B区</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label for="download_class_name" class="form-label">班级</label>
                            <select class="form-select" id="download_class_name" name="class_name">
                                <option value="">全部班级</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="download_counselor" class="form-label">辅导员</label>
                            <select class="form-select" id="download_counselor" name="counselor">
                                <option value="">全部辅导员</option>
                                <?php foreach ($counselors as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['counselor']); ?>"><?php echo htmlspecialchars($c['counselor']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="confirmDownloadBtn">
                    <i class="fas fa-download me-1"></i>下载
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// 下载学生信息功能
document.getElementById('confirmDownloadBtn').addEventListener('click', function() {
    const form = document.getElementById('downloadStudentInfoForm');
    const formData = new FormData(form);
    
    // 构建下载URL
    const params = new URLSearchParams();
    params.append('action', 'download_student_info');
    
    // 添加筛选参数
    for (const [key, value] of formData.entries()) {
        if (value.trim() !== '') {
            params.append(key, value);
        }
    }
    
    // 触发下载
    const downloadUrl = '<?php echo BASE_URL; ?>/students.php?' + params.toString();
    window.open(downloadUrl, '_blank');
    
    // 关闭模态框
    const modal = bootstrap.Modal.getInstance(document.getElementById('downloadStudentInfoModal'));
    modal.hide();
});
</script>

<?php include 'templates/footer.php'; ?>
