<?php
/**
 * 指纹管理页面
 */
require_once 'config/config.php';
$pageTitle = '指纹管理 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/fingerprint.php';
require_once 'models/device.php';
require_once 'models/student.php';

// 获取当前动作
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// 处理模板下载
if ($action === 'download_template') {
    // 清理输出缓冲区，避免之前的任何输出污染CSV
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // 禁用错误显示，避免错误信息污染CSV文件
    ini_set('display_errors', 0);
    error_reporting(0);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fingerprint_template.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // 写入BOM以支持中文
    fwrite($output, "\xEF\xBB\xBF");
    
    // 写入表头
    fputcsv($output, ['学号', '设备ID', '指纹ID', '手指编号(可选)']);
    
    // 写入示例数据（使用实际存在的设备）
    try {
        $deviceModel = new Device();
        $devices = $deviceModel->getAllDevices();
        $activeDevices = array_filter($devices, function($device) {
            return $device['status'] === 'active';
        });
    } catch (Exception $e) {
        // 如果设备查询失败，设置为空数组，使用默认示例
        $activeDevices = [];
    }
    
    if (!empty($activeDevices)) {
        $deviceIds = array_column($activeDevices, 'device_id');
        fputcsv($output, ['2431110001', $deviceIds[0], '0', '1']);
        fputcsv($output, ['2431110002', $deviceIds[0], '1', '2']);
        if (count($deviceIds) > 1) {
            fputcsv($output, ['2431110003', $deviceIds[1], '0', '1']);
        } else {
            fputcsv($output, ['2431110003', $deviceIds[0], '2', '1']);
        }
    } else {
        // 如果没有激活设备，使用默认示例
        fputcsv($output, ['2431110001', 'FP001-10-1', '0', '1']);
        fputcsv($output, ['2431110002', 'FP001-10-1', '1', '2']);
        fputcsv($output, ['2431110003', 'FP001-10-2', '0', '1']);
    }
    
    fclose($output);
    exit;
}


// 获取筛选条件和分页参数
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$filters = [];

if (isset($_GET['device_id']) && !empty($_GET['device_id'])) {
    $filters['device_id'] = sanitizeInput($_GET['device_id']);
}

if (isset($_GET['student_id']) && !empty($_GET['student_id'])) {
    $filters['student_id'] = sanitizeInput($_GET['student_id']);
}

// 初始化消息
$message = '';
$messageType = '';

// 创建模型实例
$fingerprintModel = new Fingerprint();
$deviceModel = new Device();
$studentModel = new Student();

// 处理指纹映射添加
if ($action === 'add' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/fingerprint.php');
    }
    
    $deviceId = sanitizeInput($_POST['device_id']);
    $fingerprintId = (int)$_POST['fingerprint_id'];
    $studentId = sanitizeInput($_POST['student_id']);
    $fingerIndex = isset($_POST['finger_index']) ? (int)$_POST['finger_index'] : null;
    
    // 验证必填字段
    if (empty($deviceId) || $fingerprintId < 0 || $fingerprintId > 999 || empty($studentId)) {
        $_SESSION['message'] = '请填写所有必填字段，指纹ID范围为0-999';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/fingerprint.php?action=add');
    }
    
    // 验证设备是否存在
    $device = $deviceModel->getDeviceById($deviceId);
    if (!$device) {
        $_SESSION['message'] = '指定的设备不存在';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/fingerprint.php?action=add');
    }
    
    // 验证学生是否存在
    $student = $studentModel->getStudentById($studentId);
    if (!$student) {
        $_SESSION['message'] = '指定的学生不存在';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/fingerprint.php?action=add');
    }
    
    // 检查重复映射
    $duplicateCheck = $fingerprintModel->checkDuplicateMapping($deviceId, $fingerprintId, $studentId);
    if ($duplicateCheck['has_duplicate']) {
        $_SESSION['message'] = $duplicateCheck['message'];
        $_SESSION['message_type'] = 'warning';
        redirect(BASE_URL . '/fingerprint.php?action=add');
    }
    
    $result = $fingerprintModel->addFingerprintMapping($deviceId, $fingerprintId, $studentId, $fingerIndex);
    
    if ($result === true) {
        // 添加成功
        require_once 'utils/cache.php';
        clearAllRelatedCache();
        
        $_SESSION['message'] = '指纹映射添加成功';
        $_SESSION['message_type'] = 'success';
        redirect(BASE_URL . '/fingerprint.php');
    } elseif ($result === 'duplicate') {
        // 重复指纹ID
        $_SESSION['message'] = "指纹ID {$fingerprintId} 在设备 {$deviceId} 上已被使用，请选择其他指纹ID";
        $_SESSION['message_type'] = 'warning';
        redirect(BASE_URL . '/fingerprint.php?action=add');
    } else {
        // 其他错误
        $_SESSION['message'] = '指纹映射添加失败，请检查输入信息';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/fingerprint.php?action=add');
    }
}

// 处理批量上传 (AJAX方式)
if ($action === 'batch_upload' && getRequestMethod() === 'POST') {
    // 设置JSON响应头
    header('Content-Type: application/json');
    
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请重试']);
        exit;
    }
    
    // 使用统一的文件上传处理函数（安全验证）
    $uploadResult = handleFileUpload($_FILES['batch_file']);
    
    if (!$uploadResult['success']) {
        echo json_encode(['success' => false, 'message' => $uploadResult['message']]);
        exit;
    }
    
    // 获取上传后的安全文件路径
    $uploadedFilePath = $uploadResult['path'];
    
    // 使用统一的编码处理读取CSV文件
    require_once 'utils/CSVEncodingHandler.php';
    
    $content = file_get_contents($uploadedFilePath);
    if ($content === false) {
        // 读取失败，删除上传的文件
        if (file_exists($uploadedFilePath)) {
            unlink($uploadedFilePath);
        }
        echo json_encode(['success' => false, 'message' => '无法读取上传的文件']);
        exit;
    }
    
    // 统一编码检测和转换
    $encodingResult = CSVEncodingHandler::detectAndConvertEncoding($content);
    $content = $encodingResult['content'];
    
    // 按行分割内容
    $lines = array_filter(array_map('trim', explode("\n", $content)), function($line) {
        return !empty($line);
    });
    
    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'CSV文件为空或格式不正确']);
        exit;
    }
    
    // 智能检测分隔符
    $delimiter = ',';
    $headerLine = $lines[0];
    $commaCount = substr_count($headerLine, ',');
    $semicolonCount = substr_count($headerLine, ';');
    $tabCount = substr_count($headerLine, "\t");
    
    if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
        $delimiter = ';';
    } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
        $delimiter = "\t";
    }
    
    // 解析表头（跳过第一行）
    $headers = str_getcsv($lines[0], $delimiter);
    $csvData = [];
    
    // 处理数据行
    for ($i = 1; $i < count($lines); $i++) {
        $row = str_getcsv($lines[$i], $delimiter);
        
        // 跳过空行
        if (empty(array_filter($row, function($cell) { return !empty(trim($cell)); }))) {
            continue;
        }
        
        // 确保至少有学号
        if (count($row) < 1 || empty(CSVEncodingHandler::cleanFieldData($row[0]))) {
            continue;
        }
        
        $csvData[] = [
            'student_id' => CSVEncodingHandler::cleanFieldData($row[0]),
            'device_id' => isset($row[1]) ? CSVEncodingHandler::cleanFieldData($row[1]) : '',
            'fingerprint_id' => isset($row[2]) && !empty(trim($row[2])) ? (int)trim($row[2]) : null,
            'finger_index' => isset($row[3]) && !empty(trim($row[3])) ? (int)trim($row[3]) : null
        ];
    }
    
    if (empty($csvData)) {
        echo json_encode(['success' => false, 'message' => 'CSV文件中没有有效数据']);
        exit;
    }
    
    // 调试：记录CSV数据
    error_log("指纹上传 - CSV数据行数: " . count($csvData));
    error_log("指纹上传 - 第一行数据: " . json_encode($csvData[0] ?? []));
    
    // 执行批量导入
    $result = $fingerprintModel->batchImportMappings($csvData);
    
    // 调试：记录返回结果
    error_log("指纹上传 - 返回结果: " . json_encode($result));
    
    if ($result['success']) {
        // 清理相关缓存
        require_once 'utils/cache.php';
        clearAllRelatedCache();
    }
    
    // 清理上传的临时文件
    if (isset($uploadedFilePath) && file_exists($uploadedFilePath)) {
        unlink($uploadedFilePath);
    }
    
    // 直接返回JSON结果
    echo json_encode($result);
    exit;
}

// 处理在线验证
if ($action === 'validate_data' && getRequestMethod() === 'POST') {
    header('Content-Type: application/json');
    
    // 获取JSON数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['data'])) {
        echo json_encode(['success' => false, 'message' => '无效的请求数据']);
        exit;
    }
    
    
    // 验证CSRF令牌
    if (!isset($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => '安全验证失败']);
        exit;
    }
    
    $dataToValidate = $input['data'];
    $validationResults = [];
    
    
    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 首先收集所有数据用于重复检查
    $studentIds = [];
    $deviceFingerprintPairs = [];
    
    foreach ($dataToValidate as $idx => $mapping) {
        if (!empty($mapping['student_id'])) {
            $studentIds[] = ['index' => $idx, 'student_id' => $mapping['student_id']];
        }
        if (!empty($mapping['device_id']) && isset($mapping['fingerprint_id'])) {
            $deviceFingerprintPairs[] = [
                'index' => $idx, 
                'device_id' => $mapping['device_id'], 
                'fingerprint_id' => $mapping['fingerprint_id']
            ];
        }
    }
    
    
    foreach ($dataToValidate as $index => $mapping) {
        $errors = [];
        $warnings = [];
        $valid = true;
        
        
        // 验证学号
        if (empty($mapping['student_id'])) {
            $errors[] = '学号不能为空';
            $valid = false;
        } else {
            $stmt = $pdo->prepare("SELECT student_id FROM students WHERE student_id = ?");
            $stmt->execute([$mapping['student_id']]);
            if (!$stmt->fetch()) {
                $errors[] = "学号 {$mapping['student_id']} 不存在";
                $valid = false;
            } else {
                // 检查该学号在指纹数据库中是否已有记录
                $stmt = $pdo->prepare("SELECT device_id, fingerprint_id, finger_index, enrollment_status FROM fingerprint_mapping WHERE student_id = ?");
                $stmt->execute([$mapping['student_id']]);
                $existingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($existingRecords)) {
                    // 该学号已有指纹记录，检查冲突
                    $recordsInfo = [];
                    $hasExactMatch = false;
                    $hasSameDeviceConflict = false;
                    
                    foreach ($existingRecords as $record) {
                        $recordsInfo[] = "设备{$record['device_id']}指纹{$record['fingerprint_id']}({$record['enrollment_status']})";
                        
                        // 检查是否与当前要添加的记录完全相同
                        if ($record['device_id'] === $mapping['device_id'] && 
                            $record['fingerprint_id'] == $mapping['fingerprint_id']) {
                            $hasExactMatch = true;
                            if ($record['enrollment_status'] === 'enrolled') {
                                $errors[] = "冲突：学号 {$mapping['student_id']} 在设备 {$mapping['device_id']} 的指纹ID {$mapping['fingerprint_id']} 已录入";
                                $valid = false;
                            } elseif ($record['enrollment_status'] === 'pending') {
                                $errors[] = "冲突：学号 {$mapping['student_id']} 在设备 {$mapping['device_id']} 的指纹ID {$mapping['fingerprint_id']} 正在录入中";
                                $valid = false;
                            }
                        } elseif ($record['device_id'] === $mapping['device_id']) {
                            // 同设备不同指纹ID
                            $hasSameDeviceConflict = true;
                        }
                    }
                    
                    if ($hasSameDeviceConflict && !$hasExactMatch) {
                        $warnings[] = "学号 {$mapping['student_id']} 在设备 {$mapping['device_id']} 已有其他指纹，可能产生冲突";
                    }
                    
                    if (!$hasExactMatch && !$hasSameDeviceConflict) {
                        // 不同设备，仅提示信息
                        $warnings[] = "学号 {$mapping['student_id']} 已有指纹记录：" . implode('、', $recordsInfo);
                    }
                }
            }
            
            // 检查文件内学号重复
            $duplicateStudentRows = [];
            foreach ($studentIds as $item) {
                if ($item['student_id'] === $mapping['student_id'] && $item['index'] !== $index) {
                    $duplicateStudentRows[] = $item['index'] + 2; // +2 因为有表头且从0开始
                }
            }
            if (!empty($duplicateStudentRows)) {
                $errors[] = "学号 {$mapping['student_id']} 在文件中重复出现（第" . implode('、', $duplicateStudentRows) . "行）";
                $valid = false;
            }
        }
        
        // 验证设备ID
        if (empty($mapping['device_id'])) {
            $errors[] = '设备ID不能为空';
            $valid = false;
        } else {
            $stmt = $pdo->prepare("SELECT device_id, status FROM devices WHERE device_id = ?");
            $stmt->execute([$mapping['device_id']]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$device) {
                $errors[] = "设备ID {$mapping['device_id']} 不存在";
                $valid = false;
            } elseif ($device['status'] !== 'active') {
                $errors[] = "设备 {$mapping['device_id']} 状态为 {$device['status']}，需要激活状态";
                $valid = false;
            }
        }
        
        // 验证指纹ID
        $fingerprintId = (int)$mapping['fingerprint_id'];
        if ($fingerprintId < 0 || $fingerprintId > 999) {
            $errors[] = "指纹ID {$fingerprintId} 超出范围(0-999)";
            $valid = false;
        }
        
        // 检查指纹ID是否已被使用
        if ($valid && !empty($mapping['device_id']) && is_numeric($mapping['fingerprint_id'])) {
            $stmt = $pdo->prepare("SELECT student_id FROM fingerprint_mapping WHERE device_id = ? AND fingerprint_id = ?");
            $stmt->execute([$mapping['device_id'], $fingerprintId]);
            $existingStudent = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingStudent && $existingStudent['student_id'] !== $mapping['student_id']) {
                $errors[] = "指纹ID {$fingerprintId} 已被学生 {$existingStudent['student_id']} 使用";
                $valid = false;
            }
            
            // 检查文件内设备+指纹ID重复
            $duplicateFingerprintRows = [];
            foreach ($deviceFingerprintPairs as $item) {
                if ($item['device_id'] === $mapping['device_id'] && 
                    $item['fingerprint_id'] == $mapping['fingerprint_id'] && 
                    $item['index'] !== $index) {
                    $duplicateFingerprintRows[] = $item['index'] + 2; // +2 因为有表头且从0开始
                }
            }
            if (!empty($duplicateFingerprintRows)) {
                $errors[] = "设备 {$mapping['device_id']} 的指纹ID {$fingerprintId} 在文件中重复出现（第" . implode('、', $duplicateFingerprintRows) . "行）";
                $valid = false;
            }
        }
        
        $validationResults[] = [
            'valid' => $valid,
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

// 处理可用性检查
if ($action === 'check_availability' && getRequestMethod() === 'POST') {
    header('Content-Type: application/json');
    
    // 获取JSON数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => '无效的请求数据']);
        exit;
    }
    
    // 验证CSRF令牌
    if (!isset($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => '安全验证失败']);
        exit;
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    $results = [];
    
    // 检查学号可用性
    if (!empty($input['student_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT student_id, name, class_name FROM students WHERE student_id = ?");
            $stmt->execute([$input['student_id']]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                $results['student'] = [
                    'exists' => true,
                    'valid' => true,
                    'info' => $student,
                    'message' => "学生：{$student['name']} ({$student['class_name']})"
                ];
            } else {
                $results['student'] = [
                    'exists' => false,
                    'valid' => false,
                    'message' => '学号不存在，请先在学生管理中添加该学生'
                ];
            }
        } catch (Exception $e) {
            $results['student'] = [
                'exists' => false,
                'valid' => false,
                'message' => '查询学生信息时发生错误'
            ];
        }
    }
    
    // 检查指纹ID可用性
    if (!empty($input['device_id']) && isset($input['fingerprint_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT student_id, fingerprint_id FROM fingerprint_mapping WHERE device_id = ? AND fingerprint_id = ?");
            $stmt->execute([$input['device_id'], $input['fingerprint_id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // 指纹ID已被使用
                $stmt = $pdo->prepare("SELECT name FROM students WHERE student_id = ?");
                $stmt->execute([$existing['student_id']]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $results['fingerprint'] = [
                    'available' => false,
                    'valid' => false,
                    'message' => "指纹ID {$input['fingerprint_id']} 已被学生 {$existing['student_id']} ({$student['name']}) 使用"
                ];
            } else {
                // 指纹ID可用
                $results['fingerprint'] = [
                    'available' => true,
                    'valid' => true,
                    'message' => "指纹ID {$input['fingerprint_id']} 可用"
                ];
            }
        } catch (Exception $e) {
            $results['fingerprint'] = [
                'available' => false,
                'valid' => false,
                'message' => '查询指纹ID时发生错误'
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
    exit;
}

// 处理指纹映射删除
if ($action === 'delete' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/fingerprint.php');
    }
    
    $mappingId = (int)$_POST['mapping_id'];
    
    if ($fingerprintModel->deleteFingerprintMapping($mappingId)) {
        // 清理相关缓存
        require_once 'utils/cache.php';
        clearAllRelatedCache();
        
        $_SESSION['message'] = '指纹映射删除成功';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = '指纹映射删除失败';
        $_SESSION['message_type'] = 'danger';
    }
    
    redirect(BASE_URL . '/fingerprint.php');
}

// 处理批量删除
if ($action === 'batch_delete' && getRequestMethod() === 'POST') {
    header('Content-Type: application/json');
    
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请重试']);
        exit;
    }
    
    $ids = isset($_POST['ids']) ? $_POST['ids'] : [];
    
    if (empty($ids) || !is_array($ids)) {
        echo json_encode(['success' => false, 'message' => '请选择要删除的记录']);
        exit;
    }
    
    // 验证所有ID都是数字
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($id) { return $id > 0; });
    
    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => '无效的记录ID']);
        exit;
    }
    
    try {
        $deletedCount = $fingerprintModel->batchDeleteFingerprintMappings($ids);
        
        // 清理相关缓存
        require_once 'utils/cache.php';
        clearAllRelatedCache();
        
        echo json_encode([
            'success' => true, 
            'message' => "成功删除 {$deletedCount} 条指纹映射记录",
            'deleted_count' => $deletedCount
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
    }
    exit;
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
        $deletedCount = $fingerprintModel->deleteAllFingerprintMappings();
        
        // 清理相关缓存
        require_once 'utils/cache.php';
        clearAllRelatedCache();
        
        echo json_encode([
            'success' => true, 
            'message' => "成功删除全部 {$deletedCount} 条指纹映射记录",
            'deleted_count' => $deletedCount
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
    }
    exit;
}

// 获取指纹映射列表
$offset = ($page - 1) * $perPage;
$mappings = $fingerprintModel->getFingerprintMappingsWithDetails($filters, $perPage, $offset);
$totalMappings = $fingerprintModel->countFingerprintMappings($filters);
$totalPages = ceil($totalMappings / $perPage);

// 获取所有设备（用于下拉框）
$allDevices = $deviceModel->getAllDevices();

// 检查是否有会话消息
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// 检查是否有批量上传结果
$batchUploadResult = null;
if (isset($_SESSION['batch_upload_result'])) {
    $batchUploadResult = $_SESSION['batch_upload_result'];
    unset($_SESSION['batch_upload_result']);
}

require_once 'templates/header.php';
?>

<div class="container-fluid">
    <!-- 页面标题 -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">指纹管理</h1>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFingerprintModal">
                <i class="fas fa-plus fa-sm text-white-50"></i> 添加指纹映射
            </button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#batchUploadModal">
                <i class="fas fa-upload fa-sm text-white-50"></i> 批量上传
            </button>
            <a href="<?php echo BASE_URL; ?>/fingerprint.php?action=download_template" class="btn btn-info">
                <i class="fas fa-download fa-sm text-white-50"></i> 下载模板
            </a>
        </div>
    </div>

    <!-- 消息提示 -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert" style="border-left: 4px solid; font-size: 1.1em;">
            <?php 
            $icon = '';
            switch($messageType) {
                case 'success':
                    $icon = '<i class="fas fa-check-circle me-2"></i>';
                    break;
                case 'warning':
                    $icon = '<i class="fas fa-exclamation-triangle me-2"></i>';
                    break;
                case 'danger':
                    $icon = '<i class="fas fa-times-circle me-2"></i>';
                    break;
                default:
                    $icon = '<i class="fas fa-info-circle me-2"></i>';
            }
            echo $icon . htmlspecialchars($message); 
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <script>
        // 自动滚动到消息提示
        document.addEventListener('DOMContentLoaded', function() {
            const alertElement = document.querySelector('.alert');
            if (alertElement) {
                alertElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // 5秒后自动淡化（但不关闭）
                setTimeout(() => {
                    alertElement.style.opacity = '0.7';
                }, 5000);
            }
        });
        </script>
    <?php endif; ?>

    <!-- 批量上传结果详情 -->
    <?php if ($batchUploadResult): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-chart-bar me-2"></i>批量上传结果详情
                </h6>
                <button type="button" class="btn-close" onclick="this.closest('.card').style.display='none'" aria-label="关闭"></button>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="h4 font-weight-bold text-primary"><?php echo $batchUploadResult['total']; ?></div>
                            <div class="text-xs font-weight-bold text-uppercase text-muted">总记录数</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="h4 font-weight-bold text-success"><?php echo $batchUploadResult['success']; ?></div>
                            <div class="text-xs font-weight-bold text-uppercase text-muted">成功导入</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="h4 font-weight-bold text-danger"><?php echo $batchUploadResult['fail']; ?></div>
                            <div class="text-xs font-weight-bold text-uppercase text-muted">导入失败</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <?php 
                            $successRate = $batchUploadResult['total'] > 0 ? round(($batchUploadResult['success'] / $batchUploadResult['total']) * 100, 1) : 0;
                            ?>
                            <div class="h4 font-weight-bold text-info"><?php echo $successRate; ?>%</div>
                            <div class="text-xs font-weight-bold text-uppercase text-muted">成功率</div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($batchUploadResult['errors'])): ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>错误详情</h6>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th width="60">序号</th>
                                        <th>错误信息</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($batchUploadResult['errors'], 0, 20) as $index => $error): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($error); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($batchUploadResult['errors']) > 20): ?>
                            <div class="text-muted small mt-2">
                                <i class="fas fa-info-circle me-1"></i>
                                显示前20个错误，总计 <?php echo count($batchUploadResult['errors']); ?> 个错误
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- 筛选表单 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">筛选条件</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="<?php echo BASE_URL; ?>/fingerprint.php" class="row g-3">
                <div class="col-md-4">
                    <label for="device_id" class="form-label">设备</label>
                    <select class="form-select" id="device_id" name="device_id">
                        <option value="">全部设备</option>
                        <?php foreach ($allDevices as $device): ?>
                            <option value="<?php echo htmlspecialchars($device['device_id']); ?>"
                                    <?php echo (isset($filters['device_id']) && $filters['device_id'] === $device['device_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($device['device_name']); ?> (<?php echo htmlspecialchars($device['device_id']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="student_id" class="form-label">学号</label>
                    <input type="text" class="form-control" id="student_id" name="student_id" 
                           value="<?php echo isset($filters['student_id']) ? htmlspecialchars($filters['student_id']) : ''; ?>"
                           placeholder="输入学号">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">筛选</button>
                        <a href="<?php echo BASE_URL; ?>/fingerprint.php" class="btn btn-outline-secondary">重置</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 指纹映射列表 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">指纹映射列表</h6>
            <span class="badge bg-info">共 <?php echo $totalMappings; ?> 条记录</span>
        </div>
        <div class="card-body">
            <?php if (empty($mappings)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-fingerprint fa-3x text-gray-300 mb-3"></i>
                    <h5 class="text-gray-600">暂无指纹映射</h5>
                    <p class="text-gray-500">点击右上角的"添加指纹映射"按钮来添加第一个映射</p>
                </div>
            <?php else: ?>
                <!-- 批量操作按钮 -->
                <div class="mb-3">
                    <button type="button" class="btn btn-danger btn-sm" id="batchDeleteBtn" disabled>
                        <i class="fas fa-trash me-1"></i>批量删除
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm ms-2" id="deleteAllBtn">
                        <i class="fas fa-trash-alt me-1"></i>全部删除
                    </button>
                    <span id="selectedCount" class="ms-3 text-muted">已选择 0 项</span>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr class="text-center">
                                <th width="40">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="selectAll">
                                        <label class="form-check-label" for="selectAll"></label>
                                    </div>
                                </th>
                                <th>序号</th>
                                <th>设备名称</th>
                                <th>设备ID</th>
                                <th>指纹ID</th>
                                <th>学号</th>
                                <th>姓名</th>
                                <th>手指编号</th>
                                <th>录入状态</th>
                                <th>录入时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $mapping): ?>
                                <tr class="text-center">
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input row-checkbox" type="checkbox" value="<?php echo $mapping['id']; ?>">
                                        </div>
                                    </td>
                                    <td><?php echo $mapping['id']; ?></td>
                                    <td><?php echo htmlspecialchars($mapping['device_name']); ?></td>
                                    <td><?php echo htmlspecialchars($mapping['device_id']); ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $mapping['fingerprint_id']; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($mapping['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($mapping['student_name'] ?? '未知'); ?></td>
                                    <td>
                                        <?php if ($mapping['finger_index']): ?>
                                            第<?php echo $mapping['finger_index']; ?>指
                                        <?php else: ?>
                                            <span class="text-muted">未指定</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        $statusText = '';
                                        switch ($mapping['enrollment_status']) {
                                            case 'enrolled':
                                                $statusClass = 'success';
                                                $statusText = '已录入';
                                                break;
                                            case 'pending':
                                                $statusClass = 'warning';
                                                $statusText = '待录入';
                                                break;
                                            case 'failed':
                                                $statusClass = 'danger';
                                                $statusText = '录入失败';
                                                break;
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($mapping['enrolled_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                data-custom-delete="true"
                                                onclick="deleteFingerprintMapping(<?php echo $mapping['id']; ?>, '<?php echo htmlspecialchars($mapping['student_id']); ?>', <?php echo $mapping['fingerprint_id']; ?>)">
                                            删除
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="指纹映射分页" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <!-- 上一页 -->
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($page > 1) ? BASE_URL . '/fingerprint.php?page=' . ($page - 1) . (!empty($filters) ? '&' . http_build_query($filters) : '') : '#'; ?>" aria-label="上一页">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            
                            <?php
                            // 智能分页显示逻辑
                            $showPages = [];
                            $delta = 2; // 当前页前后显示的页数
                            
                            // 总是显示第一页
                            $showPages[] = 1;
                            
                            // 计算当前页周围的页码
                            for ($i = max(2, $page - $delta); $i <= min($totalPages - 1, $page + $delta); $i++) {
                                $showPages[] = $i;
                            }
                            
                            // 总是显示最后一页
                            if ($totalPages > 1) {
                                $showPages[] = $totalPages;
                            }
                            
                            // 去重并排序
                            $showPages = array_unique($showPages);
                            sort($showPages);
                            
                            // 渲染页码
                            $prevPage = 0;
                            foreach ($showPages as $pageNum):
                                // 如果页码不连续，显示省略号
                                if ($pageNum - $prevPage > 1):
                            ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php
                                endif;
                            ?>
                                <li class="page-item <?php echo ($pageNum === $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo BASE_URL; ?>/fingerprint.php?page=<?php echo $pageNum; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                                        <?php echo $pageNum; ?>
                                    </a>
                                </li>
                            <?php
                                $prevPage = $pageNum;
                            endforeach;
                            ?>
                            
                            <!-- 下一页 -->
                            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($page < $totalPages) ? BASE_URL . '/fingerprint.php?page=' . ($page + 1) . (!empty($filters) ? '&' . http_build_query($filters) : '') : '#'; ?>" aria-label="下一页">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- 添加指纹映射模态框 -->
<div class="modal fade" id="addFingerprintModal" tabindex="-1" aria-labelledby="addFingerprintModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addFingerprintModalLabel">添加指纹映射</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/fingerprint.php?action=add" data-custom-validation="true">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="add_device_id" class="form-label">设备 <span class="text-danger">*</span></label>
                        <select class="form-select" id="add_device_id" name="device_id" required>
                            <option value="">请选择设备</option>
                            <?php foreach ($allDevices as $device): ?>
                                <option value="<?php echo htmlspecialchars($device['device_id']); ?>">
                                    <?php echo htmlspecialchars($device['device_name']); ?> (<?php echo htmlspecialchars($device['device_id']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_fingerprint_id" class="form-label">指纹ID <span class="text-danger">*</span></label>
                        <div class="input-group">
                        <input type="number" class="form-control" id="add_fingerprint_id" name="fingerprint_id" 
                               min="0" max="999" step="1" required placeholder="输入指纹ID (0-999)">
                            <span class="input-group-text" id="fingerprint_id_status">
                                <i class="fas fa-question-circle text-muted"></i>
                            </span>
                        </div>
                        <div id="fingerprint_id_info" class="form-text">指纹在设备中的编号，范围0-999</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_student_id" class="form-label">学号 <span class="text-danger">*</span></label>
                        <div class="input-group">
                        <input type="text" class="form-control" id="add_student_id" name="student_id" required
                               placeholder="输入学生学号">
                            <span class="input-group-text" id="student_id_status">
                                <i class="fas fa-question-circle text-muted"></i>
                            </span>
                        </div>
                        <div id="student_info" class="form-text"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_finger_index" class="form-label">手指编号</label>
                        <select class="form-select" id="add_finger_index" name="finger_index">
                            <option value="">不指定</option>
                            <option value="1">右手拇指</option>
                            <option value="2">右手食指</option>
                            <option value="3">右手中指</option>
                            <option value="4">右手无名指</option>
                            <option value="5">右手小指</option>
                            <option value="6">左手拇指</option>
                            <option value="7">左手食指</option>
                            <option value="8">左手中指</option>
                            <option value="9">左手无名指</option>
                            <option value="10">左手小指</option>
                        </select>
                        <div class="form-text">可选，用于记录使用的是哪个手指</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">添加映射</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 删除确认模态框 -->
<div class="modal fade" id="deleteFingerprintModal" tabindex="-1" aria-labelledby="deleteFingerprintModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteFingerprintModalLabel">确认删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>确定要删除学生 <strong id="deleteFingerprintStudent"></strong> 的指纹映射（指纹ID: <strong id="deleteFingerprintId"></strong>）吗？</p>
                <p class="text-danger">此操作无法撤销！</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteFingerprintBtn">确认删除</button>
            </div>
        </div>
    </div>
</div>

<!-- 批量删除确认模态框 -->
<div class="modal fade" id="batchDeleteModal" tabindex="-1" aria-labelledby="batchDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchDeleteModalLabel">批量删除确认</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>危险操作！</strong>
                </div>
                <p>确定要删除选中的 <strong id="batchDeleteCount">0</strong> 条指纹映射记录吗？</p>
                <p class="text-danger">此操作无法撤销！</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="confirmBatchDeleteBtn">
                    <i class="fas fa-trash me-1"></i>确认删除
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 全部删除确认模态框 -->
<div class="modal fade" id="deleteAllModal" tabindex="-1" aria-labelledby="deleteAllModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteAllModalLabel">全部删除确认</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>极度危险操作！</strong>
                </div>
                <p>确定要删除<strong>所有</strong>指纹映射记录吗？</p>
                <p>这将删除当前系统中的全部 <strong><?php echo $totalMappings; ?></strong> 条指纹映射记录。</p>
                <p class="text-danger">此操作无法撤销！</p>
                <hr>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirmDeleteAll">
                    <label class="form-check-label text-danger" for="confirmDeleteAll">
                        我确认要删除所有指纹映射记录
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteAllBtn" disabled>
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
                <h5 class="modal-title" id="batchUploadModalLabel">批量上传指纹映射</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/fingerprint.php?action=batch_upload" enctype="multipart/form-data" id="batchUploadForm" data-custom-validation="true">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- 使用说明 -->
                    <div class="alert alert-info mb-4">
                        <h6><i class="fas fa-info-circle me-2"></i>使用说明</h6>
                        <ul class="mb-2">
                            <li>请先下载CSV模板文件，按照模板格式填写数据</li>
                            <li>CSV文件必须包含：学号、设备ID、指纹ID、手指编号(可选)</li>
                            <li>文件编码请使用UTF-8格式</li>
                            <li>如果指纹ID已存在，系统将更新对应的学生信息</li>
                        </ul>
                    </div>
                    
                    <!-- 验证要求 -->
                    <div class="alert alert-warning mb-4">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>必须满足的条件</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-danger">必填字段：</h6>
                                <ul class="mb-2">
                                    <li><strong>学号</strong> - 不能为空，必须在<a href="<?php echo BASE_URL; ?>/students.php" target="_blank" class="alert-link">学生管理</a>中存在</li>
                                    <li><strong>设备ID</strong> - 不能为空，必须在<a href="<?php echo BASE_URL; ?>/device.php" target="_blank" class="alert-link">设备管理</a>中存在</li>
                                    <li><strong>指纹ID</strong> - 必须在0-999范围内</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-info">其他要求：</h6>
                                <ul class="mb-2">
                                    <li>设备状态必须为"激活"</li>
                                    <li>手指编号为可选字段(1-10)</li>
                                    <li>CSV文件大小不超过10MB</li>
                                </ul>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <a href="<?php echo BASE_URL; ?>/device.php" target="_blank" class="btn btn-sm btn-outline-info">
                                <i class="fas fa-list me-1"></i>查看设备管理
                            </a>
                        </div>
                    </div>
                    
                    <!-- 文件上传 -->
                    <div class="mb-4">
                        <label for="batch_file" class="form-label">选择CSV文件 <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="batch_file" name="batch_file" 
                               accept=".csv,text/csv" required>
                        <div class="form-text">支持的文件格式：CSV (.csv)</div>
                    </div>
                    
                    <!-- 预览行数选择器 -->
                    <div class="mb-4">
                        <label for="fingerprint_preview_limit" class="form-label">预览行数</label>
                        <select class="form-select" id="fingerprint_preview_limit" name="fingerprint_preview_limit">
                            <option value="10">10行</option>
                            <option value="50" selected>50行（推荐）</option>
                            <option value="100">100行</option>
                            <option value="200">200行</option>
                            <option value="0">全部数据</option>
                        </select>
                        <div class="form-text">选择上传前要预览的数据行数，全部数据适用于小文件</div>
                    </div>
                    
                    <!-- 文件预览区域 -->
                    <div id="file_preview" class="mb-3" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">文件预览</h6>
                            <button type="button" class="btn btn-sm btn-outline-info" id="validate_data_btn">
                                <i class="fas fa-check-circle me-1"></i>在线验证
                            </button>
                        </div>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>学号</th>
                                        <th>设备ID</th>
                                        <th>指纹ID</th>
                                        <th>手指编号</th>
                                        <th id="validation_header" style="display: none;">验证状态</th>
                                    </tr>
                                </thead>
                                <tbody id="preview_tbody">
                                </tbody>
                            </table>
                        </div>
                        <div id="preview_summary" class="text-muted small"></div>
                        <div id="validation_result" class="mt-3" style="display: none;"></div>
                    </div>
                    
                    <!-- 上传选项 -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="update_existing" name="update_existing" checked>
                                <label class="form-check-label" for="update_existing">
                                    更新已存在的映射
                                </label>
                                <small class="form-text text-muted d-block">允许更新数据库中已存在的指纹映射记录</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <a href="<?php echo BASE_URL; ?>/fingerprint.php?action=download_template" 
                       class="btn btn-info me-auto" target="_blank">
                        <i class="fas fa-download me-1"></i>下载模板
                    </a>
                    <button type="submit" class="btn btn-success" id="upload_submit_btn">
                        <i class="fas fa-upload me-1"></i>开始上传
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 批量上传结果模态框 -->
<div class="modal fade" id="uploadResultModal" tabindex="-1" aria-labelledby="uploadResultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadResultModalLabel">
                    <i class="fas fa-chart-line me-2"></i>批量上传结果
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body" id="uploadResultModalBody">
                <!-- 结果内容将通过JavaScript动态插入 -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>确定
                </button>
                <button type="button" class="btn btn-info" onclick="location.reload()">
                    <i class="fas fa-sync me-1"></i>刷新页面
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// 统一的可用性检查函数
function checkAvailability() {
    const studentId = document.getElementById('add_student_id').value.trim();
    const deviceId = document.getElementById('add_device_id').value;
    const fingerprintId = document.getElementById('add_fingerprint_id').value;
    
    // 收集需要检查的数据
    const checkData = {};
    if (studentId) checkData.student_id = studentId;
    if (deviceId && fingerprintId !== '') checkData.device_id = deviceId;
    if (fingerprintId !== '') checkData.fingerprint_id = parseInt(fingerprintId);
    
    // 如果没有数据需要检查，清空状态
    if (Object.keys(checkData).length === 0) {
        updateStudentStatus('', '', 'neutral');
        updateFingerprintStatus('', '', 'neutral');
        return;
    }
    
    // 添加CSRF令牌
    checkData.csrf_token = document.querySelector('input[name="csrf_token"]').value;
    
    // 发送检查请求
    fetch('<?php echo BASE_URL; ?>/fingerprint.php?action=check_availability', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(checkData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.results) {
            // 更新学号状态
            if (data.results.student) {
                const student = data.results.student;
                updateStudentStatus(
                    student.message,
                    student.valid ? 'success' : 'danger',
                    student.valid ? 'valid' : 'invalid'
                );
            }
            
            // 更新指纹ID状态
            if (data.results.fingerprint) {
                const fingerprint = data.results.fingerprint;
                updateFingerprintStatus(
                    fingerprint.message,
                    fingerprint.valid ? 'success' : 'danger',
                    fingerprint.valid ? 'valid' : 'invalid'
                );
            }
        }
    })
    .catch(error => {
        console.error('可用性检查失败:', error);
        updateStudentStatus('网络错误，无法验证', 'warning', 'neutral');
        updateFingerprintStatus('网络错误，无法验证', 'warning', 'neutral');
    });
}

// 更新学号状态显示
function updateStudentStatus(message, type, status) {
    const statusIcon = document.getElementById('student_id_status');
    const infoDiv = document.getElementById('student_info');
    const inputField = document.getElementById('add_student_id');
    
    // 更新图标
    statusIcon.innerHTML = getStatusIcon(status);
    
    // 更新输入框样式
    inputField.className = 'form-control ' + getInputClass(status);
    
    // 更新信息文本
    if (message) {
        infoDiv.innerHTML = `<span class="text-${type}">${message}</span>`;
        infoDiv.className = `form-text text-${type}`;
    } else {
        infoDiv.innerHTML = '';
        infoDiv.className = 'form-text';
    }
}

// 更新指纹ID状态显示
function updateFingerprintStatus(message, type, status) {
    const statusIcon = document.getElementById('fingerprint_id_status');
    const infoDiv = document.getElementById('fingerprint_id_info');
    const inputField = document.getElementById('add_fingerprint_id');
    
    // 更新图标
    statusIcon.innerHTML = getStatusIcon(status);
    
    // 更新输入框样式
    inputField.className = 'form-control ' + getInputClass(status);
    
    // 更新信息文本
    if (message) {
        infoDiv.innerHTML = `<span class="text-${type}">${message}</span>`;
        infoDiv.className = `form-text text-${type}`;
    } else {
        infoDiv.innerHTML = '指纹在设备中的编号，范围0-999';
        infoDiv.className = 'form-text';
    }
}

// 获取状态图标
function getStatusIcon(status) {
    switch (status) {
        case 'valid':
            return '<i class="fas fa-check-circle text-success"></i>';
        case 'invalid':
            return '<i class="fas fa-times-circle text-danger"></i>';
        case 'loading':
            return '<i class="fas fa-spinner fa-spin text-muted"></i>';
        default:
            return '<i class="fas fa-question-circle text-muted"></i>';
    }
}

// 获取输入框样式类
function getInputClass(status) {
    switch (status) {
        case 'valid':
            return 'is-valid';
        case 'invalid':
            return 'is-invalid';
        default:
            return '';
    }
}

// 防抖函数
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// 创建防抖版本的检查函数
const debouncedCheck = debounce(checkAvailability, 800);

// 添加事件监听器
document.getElementById('add_student_id').addEventListener('input', function() {
    if (this.value.trim()) {
        updateStudentStatus('', '', 'loading');
        debouncedCheck();
    } else {
        updateStudentStatus('', '', 'neutral');
    }
});

document.getElementById('add_fingerprint_id').addEventListener('input', function() {
    if (this.value !== '' && document.getElementById('add_device_id').value) {
        updateFingerprintStatus('', '', 'loading');
        debouncedCheck();
    } else {
        updateFingerprintStatus('', '', 'neutral');
    }
});

document.getElementById('add_device_id').addEventListener('change', function() {
    if (this.value && document.getElementById('add_fingerprint_id').value !== '') {
        updateFingerprintStatus('', '', 'loading');
        debouncedCheck();
                    } else {
        updateFingerprintStatus('', '', 'neutral');
    }
});

// 表单初始化
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('#addFingerprintModal form');
    if (form) {
        
        // 当模态框显示时初始化输入框
        const modal = document.getElementById('addFingerprintModal');
        if (modal) {
            modal.addEventListener('shown.bs.modal', function() {
                // 确保所有输入框可用且聚焦
                const inputs = form.querySelectorAll('input, select');
                inputs.forEach(input => {
                    input.disabled = false;
                    input.readOnly = false;
                });
                // 聚焦到第一个输入框
                const firstInput = form.querySelector('select, input');
                if (firstInput) {
                    firstInput.focus();
                }
            });
            
            modal.addEventListener('hidden.bs.modal', function() {
                isValidated = false;
                // 清除之前的警告消息
                const existingAlert = form.querySelector('.modal-body .alert');
                if (existingAlert) {
                    existingAlert.remove();
                }
                // 重置表单
                form.reset();
                // 清除学生信息显示
                const studentInfo = document.getElementById('student_info');
                if (studentInfo) {
                    studentInfo.innerHTML = '';
                    studentInfo.className = 'form-text';
                }
                // 确保输入框可用
                const inputs = form.querySelectorAll('input, select');
                inputs.forEach(input => {
                    input.disabled = false;
                    input.readOnly = false;
                });
            });
        }
    }
});

// 删除指纹映射相关变量
let currentMappingId = null;

// 删除指纹映射
function deleteFingerprintMapping(mappingId, studentId, fingerprintId) {
    currentMappingId = mappingId;
    document.getElementById('deleteFingerprintStudent').textContent = studentId;
    document.getElementById('deleteFingerprintId').textContent = fingerprintId;
    
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteFingerprintModal'));
    deleteModal.show();
}

// 确认删除按钮事件处理
document.addEventListener('DOMContentLoaded', function() {
    const confirmDeleteBtn = document.getElementById('confirmDeleteFingerprintBtn');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (!currentMappingId) {
                alert('删除失败：无效的映射ID');
                return;
            }
            
            // 禁用按钮防止重复点击
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> 删除中...';
            
            // 创建并提交隐藏表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo BASE_URL; ?>/fingerprint.php?action=delete';
            form.style.display = 'none';
            
            // 添加CSRF令牌
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo generateCSRFToken(); ?>';
            form.appendChild(csrfInput);
            
            // 添加映射ID
            const mappingIdInput = document.createElement('input');
            mappingIdInput.type = 'hidden';
            mappingIdInput.name = 'mapping_id';
            mappingIdInput.value = currentMappingId;
            form.appendChild(mappingIdInput);
            
            // 提交表单
            document.body.appendChild(form);
            form.submit();
        });
    }
});

// 批量上传相关功能
document.addEventListener('DOMContentLoaded', function() {
    const batchFileInput = document.getElementById('batch_file');
    const filePreview = document.getElementById('file_preview');
    const previewTbody = document.getElementById('preview_tbody');
    const previewSummary = document.getElementById('preview_summary');
    const uploadForm = document.getElementById('batchUploadForm');
    
    // 文件选择时预览
    if (batchFileInput) {
        batchFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) {
                filePreview.style.display = 'none';
                return;
            }
            
            // 检查文件类型
            if (!file.name.toLowerCase().endsWith('.csv')) {
                alert('请选择CSV文件');
                this.value = '';
                filePreview.style.display = 'none';
                return;
            }
            
            // 检查文件大小（限制10MB）
            if (file.size > 10 * 1024 * 1024) {
                alert('文件大小不能超过10MB');
                this.value = '';
                filePreview.style.display = 'none';
                return;
            }
            
            // 显示加载状态
            filePreview.style.display = 'block';
            previewTbody.innerHTML = '<tr><td colspan="5" class="text-center"><i class="fas fa-spinner fa-spin"></i> 正在处理文件编码...</td></tr>';
            previewSummary.textContent = '正在检测文件编码...';
            
            // 使用Ajax发送到后端处理编码
            const formData = new FormData();
            formData.append('preview_file', file);
            formData.append('preview_type', 'fingerprint');
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            // 获取预览行数限制
            const previewLimit = document.getElementById('fingerprint_preview_limit').value;
            formData.append('preview_limit', previewLimit);
            
            fetch('<?php echo BASE_URL; ?>/api/preview_csv_general.php?t=' + Date.now(), {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('指纹管理预览响应:', data);
                
                // 清空加载状态
                previewTbody.innerHTML = '';
                
                if (data.success) {
                    // 显示预览数据
                    let validRows = 0;
                    console.log('开始处理指纹数据，总行数:', data.data.length);
                    data.data.forEach((row, index) => {
                        console.log(`处理第${index + 1}行:`, row);
                        console.log(`学号检查: "${row.student_id}", trim后: "${(row.student_id || '').trim()}"`);
                        // 只要有学号就认为是有效行
                        if (row.student_id && row.student_id.trim()) {
                            console.log(`第${index + 1}行有效`);
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>${escapeHtml(row.student_id || '')}</td>
                                <td>${escapeHtml(row.device_id || '')}</td>
                                <td>${escapeHtml(row.fingerprint_id || '')}</td>
                                <td>${escapeHtml(row.finger_number || '未指定')}</td>
                                <td class="validation-status" style="display: none;">
                                    <span class="badge bg-secondary">待验证</span>
                                </td>
                            `;
                            tr.setAttribute('data-row-data', JSON.stringify({
                                student_id: row.student_id || '',
                                device_id: row.device_id || '',
                                fingerprint_id: row.fingerprint_id || '',
                                finger_number: row.finger_number || ''
                            }));
                            previewTbody.appendChild(tr);
                            validRows++;
                        } else {
                            console.log(`第${index + 1}行无效，学号为空或无效`);
                        }
                    });
                    
                    console.log(`处理完成，有效行数: ${validRows}, 总行数: ${data.data.length}`);
                    
                    // 更新统计信息
                    let summaryText = `总计 ${data.total} 行数据`;
                    if (data.is_full_preview) {
                        summaryText += `，已显示全部`;
                    } else if (data.total > data.preview_count) {
                        summaryText += `，已预览 ${data.preview_count} 行`;
                    }
                    if (data.encoding_detected && data.encoding_detected !== 'UTF-8') {
                        summaryText += ` (已从${data.encoding_detected}转换为UTF-8)`;
                    }
                    const invalidRows = data.preview_count - validRows;
                    if (invalidRows > 0) {
                        summaryText += `，发现 ${invalidRows} 行数据不完整`;
                    }
                    previewSummary.textContent = summaryText;
                } else {
                    // 显示错误信息
                    previewTbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> ${data.message}</td></tr>`;
                    previewSummary.textContent = '预览失败';
                }
            })
            .catch(error => {
                console.error('指纹管理预览请求失败:', error);
                previewTbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> 网络请求失败</td></tr>';
                previewSummary.textContent = '预览失败';
            });
        });
    }
    
    // 预览行数选择器变化时自动重新预览
    const fingerprintPreviewLimitSelect = document.getElementById('fingerprint_preview_limit');
    if (fingerprintPreviewLimitSelect && batchFileInput) {
        fingerprintPreviewLimitSelect.addEventListener('change', function() {
            // 如果已经选择了文件，自动重新预览
            if (batchFileInput.files[0]) {
                // 触发文件输入的change事件来重新预览
                const event = new Event('change');
                batchFileInput.dispatchEvent(event);
            }
        });
    }
    
    // 表单提交处理 (AJAX方式)
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault(); // 阻止默认提交
            
            const fileInput = document.getElementById('batch_file');
            if (!fileInput.files[0]) {
                alert('请选择要上传的CSV文件');
                return;
            }
            
            const submitBtn = document.getElementById('upload_submit_btn');
            const originalBtnText = submitBtn.innerHTML;
            
            // 防止重复提交
            if (submitBtn.disabled) {
                return;
            }
            
            // 更新按钮状态
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>上传中...';
            submitBtn.disabled = true;
            
            // 按钮恢复函数
            const restoreButton = () => {
                const btn = document.getElementById('upload_submit_btn');
                if (btn) {
                    btn.innerHTML = originalBtnText;
                    btn.disabled = false;
                }
            };
            
            // 创建FormData
            const formData = new FormData();
            formData.append('batch_file', fileInput.files[0]);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            // 发送AJAX请求
            fetch('<?php echo BASE_URL; ?>/fingerprint.php?action=batch_upload', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('响应状态:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('上传响应:', data);
                if (data.success) {
                    // 成功处理
                    const resultData = data.data || {};
                    
                    // 先恢复按钮状态
                    restoreButton();
                    
                    // 关闭模态框
                    const modal = bootstrap.Modal.getInstance(document.getElementById('batchUploadModal'));
                    modal.hide();
                    
                    // 显示结果 - 确保传递正确的数据格式
                    showUploadResult({
                        total: resultData.total || 0,
                        success: resultData.success || 0,
                        fail: resultData.fail || 0,
                        errors: resultData.errors || []
                    });
                    
                } else {
                    // 错误处理 - 恢复按钮状态
                    restoreButton();
                    
                    showUploadResult({ 
                        success: 0, 
                        fail: 1, 
                        total: 1, 
                        errors: [data.message] 
                    });
                }
            })
            .catch(error => {
                // 恢复按钮状态
                restoreButton();
                
                console.error('上传错误:', error);
                alert('上传过程中发生错误，请重试');
            });
        });
    }
    
    // 在线验证功能
    const validateBtn = document.getElementById('validate_data_btn');
    if (validateBtn) {
        validateBtn.addEventListener('click', function() {
            const fileInput = document.getElementById('batch_file');
            if (!fileInput.files[0]) {
                alert('请先选择CSV文件');
                return;
            }
            
            // 更新按钮状态
            const originalText = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>验证中...';
            this.disabled = true;
            
            // 读取整个文件进行验证
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const csv = e.target.result;
                    const lines = csv.split('\n').filter(line => line.trim());
                    
                    if (lines.length < 2) {
                        alert('CSV文件至少需要包含表头和一行数据');
                        validateBtn.innerHTML = originalText;
                        validateBtn.disabled = false;
                        return;
                    }
                    
                    // 解析所有数据（跳过表头）
                    const dataToValidate = [];
                    console.log('在线验证 - 文件总行数:', lines.length);
                    for (let i = 1; i < lines.length; i++) {
                        const row = parseCSVRow(lines[i]);
                        console.log(`第${i}行解析结果:`, row);
                        if (row.length >= 3) {
                            dataToValidate.push({
                                student_id: row[0] || '',
                                device_id: row[1] || '',
                                fingerprint_id: row[2] || '',
                                finger_index: row[3] || ''
                            });
                        }
                    }
                    console.log('解析后的数据条数:', dataToValidate.length);
                    
                    if (dataToValidate.length === 0) {
                        alert('没有有效数据可以验证');
                        validateBtn.innerHTML = originalText;
                        validateBtn.disabled = false;
                        return;
                    }
                    
                    // 显示验证列（仅对预览行）
                    const rows = document.querySelectorAll('#preview_tbody tr[data-row-data]');
                    if (rows.length > 0) {
                        document.getElementById('validation_header').style.display = '';
                        document.querySelectorAll('.validation-status').forEach(cell => {
                            cell.style.display = '';
                        });
                    }
                    
                    // 发送验证请求
                    fetch('<?php echo BASE_URL; ?>/fingerprint.php?action=validate_data', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            data: dataToValidate,
                            csrf_token: document.querySelector('input[name="csrf_token"]').value
                        })
                    })
                    .then(response => response.json())
                    .then(result => {
                        // 恢复按钮状态
                        validateBtn.innerHTML = originalText;
                        validateBtn.disabled = false;
                        
                        if (result.success) {
                            // 更新验证结果（仅对预览行）
                            const rows = document.querySelectorAll('#preview_tbody tr[data-row-data]');
                            console.log('预览行数:', rows.length, '验证结果数:', result.validation_results.length);
                            if (rows.length > 0 && result.validation_results.length > 0) {
                                updateValidationDisplayForPreview(result.validation_results, rows);
                            }
                            showValidationSummary(result.validation_results);
                        } else {
                            alert('验证失败: ' + result.message);
                        }
                    })
                    .catch(error => {
                        // 恢复按钮状态
                        validateBtn.innerHTML = originalText;
                        validateBtn.disabled = false;
                        
                        console.error('验证错误:', error);
                        alert('验证过程中发生错误');
                    });
                    
                } catch (error) {
                    // 文件读取错误
                    validateBtn.innerHTML = originalText;
                    validateBtn.disabled = false;
                    console.error('文件读取错误:', error);
                    alert('文件读取失败，请检查文件格式');
                }
            };
            
            reader.readAsText(fileInput.files[0], 'UTF-8');
        });
    }
    
    // 模态框关闭时重置表单
    const batchUploadModal = document.getElementById('batchUploadModal');
    if (batchUploadModal) {
        batchUploadModal.addEventListener('hidden.bs.modal', function() {
            // 重置表单
            if (uploadForm) {
                uploadForm.reset();
            }
            
            // 隐藏预览
            if (filePreview) {
                filePreview.style.display = 'none';
            }
            
            // 隐藏验证相关元素
            const validationHeader = document.getElementById('validation_header');
            const validationResult = document.getElementById('validation_result');
            if (validationHeader) validationHeader.style.display = 'none';
            if (validationResult) validationResult.style.display = 'none';
            
            // 隐藏所有验证状态列
            document.querySelectorAll('.validation-status').forEach(cell => {
                cell.style.display = 'none';
            });
            
            // 重置按钮状态
            const submitBtn = document.getElementById('upload_submit_btn');
            const validateBtn = document.getElementById('validate_data_btn');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-upload me-1"></i>开始上传';
                submitBtn.disabled = false;
            }
            if (validateBtn) {
                validateBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>在线验证';
                validateBtn.disabled = false;
            }
        });
    }
});

// 更新验证显示
function updateValidationDisplay(validationResults) {
    const rows = document.querySelectorAll('#preview_tbody tr[data-row-data]');
    
    rows.forEach((row, index) => {
        if (validationResults[index]) {
            const result = validationResults[index];
            const statusCell = row.querySelector('.validation-status');
            
            if (result.valid) {
                statusCell.innerHTML = '<span class="badge bg-success" title="验证通过">✅ 通过</span>';
            } else {
                statusCell.innerHTML = `<span class="badge bg-danger" title="${escapeHtml(result.errors.join(', '))}">❌ 失败</span>`;
            }
        }
    });
}

// 更新预览行的验证显示（用于全文件验证）
function updateValidationDisplayForPreview(validationResults, previewRows) {
    previewRows.forEach((row, index) => {
        if (validationResults[index]) {
            const result = validationResults[index];
            const statusCell = row.querySelector('.validation-status');
            
            if (result.valid) {
                if (result.warnings && result.warnings.length > 0) {
                    // 通过但有警告
                    const warningTitle = escapeHtml(result.warnings.join(', '));
                    statusCell.innerHTML = `<span class="badge bg-warning" title="${warningTitle}">⚠️ 警告</span>`;
                } else {
                    // 完全通过
                    statusCell.innerHTML = '<span class="badge bg-success" title="验证通过">✅ 通过</span>';
                }
            } else {
                // 有错误，失败
                const errorTitle = escapeHtml(result.errors?.join(', ') || '未知错误');
                statusCell.innerHTML = `<span class="badge bg-danger" title="${errorTitle}">❌ 失败</span>`;
            }
        }
    });
}

// 显示验证摘要
function showValidationSummary(validationResults) {
    const perfectCount = validationResults.filter(r => r.valid === true && (!r.warnings || r.warnings.length === 0)).length;
    const warningCount = validationResults.filter(r => r.valid === true && r.warnings && r.warnings.length > 0).length;
    const errorCount = validationResults.filter(r => r.valid === false).length;
    
    const summaryHtml = `
        <div class="alert alert-${errorCount > 0 ? 'danger' : (warningCount > 0 ? 'warning' : 'success')} mb-0">
            <h6 class="mb-2"><i class="fas fa-clipboard-check me-2"></i>验证结果摘要</h6>
            <div class="row text-center">
                <div class="col-3">
                    <div class="h5 text-primary">${validationResults.length}</div>
                    <small>总记录数</small>
                </div>
                <div class="col-3">
                    <div class="h5 text-success">${perfectCount}</div>
                    <small>完美通过</small>
                </div>
                <div class="col-3">
                    <div class="h5 text-warning">${warningCount}</div>
                    <small>有警告</small>
                </div>
                <div class="col-3">
                    <div class="h5 text-danger">${errorCount}</div>
                    <small>有错误</small>
                </div>
            </div>
            ${errorCount > 0 ? `
                <hr class="my-2">
                <div class="mb-0">
                    <strong>常见问题：</strong>
                    <ul class="mb-0 mt-1">
                        <li>学号不存在 → 请检查学号是否正确，或先在学生管理中添加学生</li>
                        <li>设备ID不存在 → 请检查设备ID是否正确，或先在设备管理中添加设备</li>
                        <li>设备未激活 → 请在设备管理中将设备状态设为"激活"</li>
                        <li>指纹ID范围错误 → 请确保指纹ID在0-999范围内</li>
                    </ul>
                </div>
            ` : `
                <hr class="my-2">
                <div class="text-success mb-0">
                    <i class="fas fa-check-circle me-1"></i>
                    所有数据验证通过，可以安全上传！
                </div>
            `}
        </div>
    `;
    
    const validationResult = document.getElementById('validation_result');
    validationResult.innerHTML = summaryHtml;
    validationResult.style.display = 'block';
}

// 解析CSV行的简单函数
function parseCSVRow(str) {
    const result = [];
    let current = '';
    let inQuotes = false;
    
    for (let i = 0; i < str.length; i++) {
        const char = str[i];
        
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

// HTML转义函数
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 显示上传结果（美观的模态框版本）
function showUploadResult(data) {
    const successRate = data.total > 0 ? Math.round((data.success / data.total) * 100) : 0;
    const isSuccess = data.fail === 0;
    
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
                    <li><strong>学号不存在：</strong>请先在 <a href="students.php" target="_blank">学生管理</a> 中添加学生信息</li>
                    <li><strong>设备ID不存在：</strong>请先在 <a href="devices.php" target="_blank">设备管理</a> 中添加设备</li>
                    <li><strong>设备未激活：</strong>请在设备管理中将设备状态设为"激活"</li>
                    <li><strong>指纹ID重复：</strong>同一设备上的指纹ID不能重复使用</li>
                </ul>
            </div>
        `;
    } else if (isSuccess) {
        errorsHtml = `
            <div class="alert alert-success mb-0">
                <div class="text-center">
                    <i class="fas fa-thumbs-up fa-3x text-success mb-3"></i>
                    <h5>完美！</h5>
                    <p class="mb-0">所有数据都已成功导入，可以在上方列表中查看。</p>
                </div>
            </div>
        `;
    }
    
    // 组合完整内容
    const modalContent = statsHtml + summaryHtml + errorsHtml;
    
    // 更新模态框标题
    const modalTitle = document.getElementById('uploadResultModalLabel');
    modalTitle.innerHTML = `
        <i class="fas fa-${isSuccess ? 'check-circle text-success' : 'chart-line text-warning'} me-2"></i>
        批量上传${isSuccess ? '成功' : '结果'}
    `;
    
    // 插入内容到模态框
    const modalBody = document.getElementById('uploadResultModalBody');
    modalBody.innerHTML = modalContent;
    
    // 显示模态框
    const resultModal = new bootstrap.Modal(document.getElementById('uploadResultModal'));
    resultModal.show();
}

// 批量删除功能
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const batchDeleteBtn = document.getElementById('batchDeleteBtn');
    const deleteAllBtn = document.getElementById('deleteAllBtn');
    const selectedCount = document.getElementById('selectedCount');
    
    // 全选/取消全选
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            rowCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBatchDeleteButton();
        });
    }
    
    // 单个复选框变化
    rowCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateBatchDeleteButton();
            
            // 更新全选状态
            if (selectAllCheckbox) {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                selectAllCheckbox.checked = checkedCount === rowCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < rowCheckboxes.length;
            }
        });
    });
    
    // 更新批量删除按钮状态
    function updateBatchDeleteButton() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        
        if (batchDeleteBtn) {
            batchDeleteBtn.disabled = checkedCount === 0;
        }
        
        if (selectedCount) {
            selectedCount.textContent = `已选择 ${checkedCount} 项`;
        }
    }
    
    // 批量删除按钮点击
    if (batchDeleteBtn) {
        batchDeleteBtn.addEventListener('click', function() {
            const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
            const count = checkedBoxes.length;
            
            if (count === 0) {
                alert('请选择要删除的记录');
                return;
            }
            
            // 更新模态框中的数量
            document.getElementById('batchDeleteCount').textContent = count;
            
            // 显示确认模态框
            const batchDeleteModal = new bootstrap.Modal(document.getElementById('batchDeleteModal'));
            batchDeleteModal.show();
        });
    }
    
    // 全部删除按钮点击
    if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', function() {
            const deleteAllModal = new bootstrap.Modal(document.getElementById('deleteAllModal'));
            deleteAllModal.show();
        });
    }
    
    // 确认批量删除
    const confirmBatchDeleteBtn = document.getElementById('confirmBatchDeleteBtn');
    if (confirmBatchDeleteBtn) {
        confirmBatchDeleteBtn.addEventListener('click', function() {
            const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
            const ids = Array.from(checkedBoxes).map(box => box.value);
            
            if (ids.length === 0) {
                alert('请选择要删除的记录');
                return;
            }
            
            // 禁用按钮并显示加载状态
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>删除中...';
            
            // 发送删除请求
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');
            ids.forEach(id => formData.append('ids[]', id));
            
            fetch('<?php echo BASE_URL; ?>/fingerprint.php?action=batch_delete', {
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
                    this.innerHTML = '<i class="fas fa-trash me-1"></i>确认删除';
                }
            })
            .catch(error => {
                console.error('删除错误:', error);
                alert('删除过程中发生错误');
                // 恢复按钮状态
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-trash me-1"></i>确认删除';
            });
        });
    }
    
    // 全部删除确认复选框
    const confirmDeleteAllCheckbox = document.getElementById('confirmDeleteAll');
    const confirmDeleteAllBtn = document.getElementById('confirmDeleteAllBtn');
    
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
            
            fetch('<?php echo BASE_URL; ?>/fingerprint.php?action=delete_all', {
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
});


</script>

<?php require_once 'templates/footer.php'; ?>