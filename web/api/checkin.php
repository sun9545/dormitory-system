<?php
/**
 * 指纹签到API接口
 * 用于接收ESP32指纹识别设备上传的签到数据
 */

// 引入必要的配置
require_once '../config/config.php';
require_once '../utils/cache.php';

// 设置JSON响应头
header('Content-Type: application/json');

// 记录API访问日志
$logFile = ROOT_PATH . '/logs/api_access.log';
$requestData = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');
$logEntry = "[{$timestamp}] ========== 新请求 ==========\n";
$logEntry .= "[{$timestamp}] 接收数据: {$requestData}\n";
$logEntry .= "[{$timestamp}] 请求方法: " . $_SERVER['REQUEST_METHOD'] . "\n";
$logEntry .= "[{$timestamp}] 客户端IP: " . ($_SERVER['REMOTE_ADDR'] ?? '未知') . "\n";

// 确保日志目录存在
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

// 写入访问日志
file_put_contents($logFile, $logEntry, FILE_APPEND);

// 验证API令牌
$headers = getallheaders();
$apiToken = isset($headers['X-Api-Token']) ? $headers['X-Api-Token'] : '';
$deviceId = '';

// 检查是否需要API令牌验证
if (defined('ENV_API_TOKEN_REQUIRED') && ENV_API_TOKEN_REQUIRED) {
    if (empty($apiToken) || $apiToken !== ENV_API_TOKEN) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => '未授权访问'
        ]);
        
        // 记录未授权访问
        $unauthorizedLog = "[{$timestamp}] 未授权访问: Token=" . $apiToken . "\n";
        file_put_contents($logFile, $unauthorizedLog, FILE_APPEND);
        exit;
    }
}

// 解析JSON数据
$data = json_decode($requestData, true);

// 检查数据完整性 - 支持指纹ID和学生ID两种方式
if (!$data || !isset($data['device_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '缺少设备ID参数'
    ]);
    exit;
}

// 支持两种数据格式：
// 1. 传统方式：student_id (直接学号)
// 2. 指纹方式：fingerprint_id (需要查询映射表获取学号)
$studentId = '';
$isFingerprint = false;

if (isset($data['student_id'])) {
    // 传统方式
    $studentId = $data['student_id'];
} elseif (isset($data['fingerprint_id'])) {
    // ⭐ 指纹方式 - 使用优化后的JOIN查询
    $isFingerprint = true;
    $fingerprintId = $data['fingerprint_id'];
    
    // ⭐ 优化：使用JOIN一次性查询所有数据，避免两次数据库往返
    require_once '../models/fingerprint.php';
    $fingerprintModel = new Fingerprint();
    $studentData = $fingerprintModel->getStudentInfoByFingerprintIdOptimized($data['device_id'], $fingerprintId);
    
    if (!$studentData) {
        http_response_code(200);  // 使用200状态码避免nginx干扰
        echo json_encode([
            'success' => false,
            'message' => '未找到该学生'
        ]);
        
        // 记录映射查找失败的日志
        $mappingLog = "[{$timestamp}] 映射查找失败: 设备={$data['device_id']}, 指纹ID={$fingerprintId} - 未找到该学生\n";
        file_put_contents($logFile, $mappingLog, FILE_APPEND);
        exit;
    }
    
    $studentId = $studentData['student_id'];
    
    // ⭐ 优化：student信息已经通过JOIN获取，无需再次查询
    // 记录指纹映射查找成功的日志
    $mappingLog = "[{$timestamp}] ⚡优化查询成功: 设备={$data['device_id']}, 指纹ID={$fingerprintId} -> 学生ID={$studentId}\n";
    file_put_contents($logFile, $mappingLog, FILE_APPEND);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '缺少学生ID或指纹ID参数'
    ]);
    exit;
}

$deviceId = $data['device_id'];

// 解析设备ID格式：FP001-楼号-序号
$buildingNumber = null;
$deviceSequence = null;
$deviceName = $deviceId;

if (preg_match('/^FP001-(\d+)-(\d+)$/', $deviceId, $matches)) {
    $buildingNumber = (int)$matches[1];
    $deviceSequence = (int)$matches[2];
    $deviceName = "{$buildingNumber}号楼设备{$deviceSequence}";
    
    // 记录设备信息解析日志
    $deviceLog = "[{$timestamp}] 设备ID解析: {$deviceId} -> 楼号:{$buildingNumber}, 序号:{$deviceSequence}\n";
    file_put_contents($logFile, $deviceLog, FILE_APPEND);
}

try {
    $db = getDBConnection();
    
    // ⭐ 优化：如果是指纹方式，学生信息已通过JOIN获取，无需再查询
    if ($isFingerprint && isset($studentData)) {
        $student = $studentData; // 直接使用已获取的数据
        $queryLog = "[{$timestamp}] ⚡ 跳过第二次查询（数据已缓存）\n";
        file_put_contents($logFile, $queryLog, FILE_APPEND);
    } else {
        // 传统方式：查询学生信息
        $stmt = $db->prepare("SELECT student_id, name, class_name, building, building_area, building_floor, room_number, bed_number FROM students WHERE student_id = :student_id");
        $stmt->execute(['student_id' => $studentId]);
        $student = $stmt->fetch();
        
        if (!$student) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => $isFingerprint ? "未找到指纹ID为 {$studentId} 的学生" : "未找到学号为 {$studentId} 的学生"
            ]);
            
            // 记录未找到学生的日志
            $notFoundLog = "[{$timestamp}] 未找到学生: {$studentId} (指纹方式: " . ($isFingerprint ? 'true' : 'false') . ")\n";
            file_put_contents($logFile, $notFoundLog, FILE_APPEND);
            exit;
        }
    }
    
    // 更新设备在线状态
    try {
        // 获取客户端IP地址
        $clientIP = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $clientIP = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $clientIP = $_SERVER['REMOTE_ADDR'];
        }
        
        // 更新设备的last_seen和ip_address
        $updateDeviceStmt = $db->prepare("
            UPDATE devices 
            SET last_seen = CURRENT_TIMESTAMP, ip_address = ? 
            WHERE device_id = ?
        ");
        $updateDeviceStmt->execute([$clientIP, $deviceId]);
        
        // 记录设备状态更新日志
        $deviceLog = "[{$timestamp}] 设备状态更新: {$deviceId}, IP: {$clientIP}\n";
        file_put_contents($logFile, $deviceLog, FILE_APPEND);
        
    } catch (Exception $deviceUpdateError) {
        // 设备状态更新失败不影响签到流程，只记录日志
        $errorLog = "[{$timestamp}] 设备状态更新失败: {$deviceUpdateError->getMessage()}\n";
        file_put_contents($logFile, $errorLog, FILE_APPEND);
    }
    
    // 处理签到数据
    $timestamp = date('Y-m-d H:i:s');
    $checkTime = $timestamp; // 使用服务器当前时间

    // 记录请求数据
    $logData = "[{$timestamp}] 接收数据: " . json_encode($data) . "\n";
    file_put_contents($logFile, $logData, FILE_APPEND);
    
    // 获取当前日期时间
    $currentDate = date('Y-m-d', strtotime($checkTime));
    
    // 查询学生当天的最新状态
    $stmt = $db->prepare("SELECT status FROM check_records 
                         WHERE student_id = :student_id 
                         AND DATE(check_time) = :current_date
                         ORDER BY check_time DESC LIMIT 1");
    $stmt->execute([
        'student_id' => $studentId,
        'current_date' => $currentDate
    ]);
    $todayRecord = $stmt->fetch();
    
    // 确定新状态 - 修改状态逻辑
    $newStatus = '在寝'; // 默认为在寝状态
    
    // 查询学生当天的请假状态（⭐ 签到后自动取消请假）
    $stmt = $db->prepare("SELECT status FROM check_records 
                         WHERE student_id = :student_id 
                         AND status = '请假'
                         AND DATE(check_time) = :current_date
                         ORDER BY check_time DESC LIMIT 1");
    $stmt->execute([
        'student_id' => $studentId,
        'current_date' => $currentDate
    ]);
    $leaveRecord = $stmt->fetch();
    
    // ⭐ 签到后取消请假：无论是否有请假记录，签到都设置为"在寝"
    // 这样在"当前请假"列表中不会显示，但"请假历史"中保留记录
    if ($isFingerprint || (isset($data['device_id']) && preg_match('/^(ESP32|FP001)/', $data['device_id']))) {
        $newStatus = '在寝';
        $deviceType = $isFingerprint ? '指纹设备' : 'ESP32设备';
        
        if ($leaveRecord) {
            // 有请假记录，签到后取消请假
            $logData = "[{$timestamp}] ⭐ 学生 {$studentId} 原本请假，通过{$deviceType}({$deviceId})签到，自动取消请假，设置为在寝状态\n";
        } else {
            // 没有请假记录，正常签到
            $logData = "[{$timestamp}] 学生 {$studentId} 通过{$deviceType}({$deviceId})签到，设置为在寝状态\n";
        }
        file_put_contents($logFile, $logData, FILE_APPEND);
    }
    
    // 记录新的签到状态
    $stmt = $db->prepare("INSERT INTO check_records (student_id, check_time, status, device_id) 
                          VALUES (:student_id, :check_time, :status, :device_id)");
    $stmt->execute([
        'student_id' => $studentId,
        'check_time' => $checkTime,
        'status' => $newStatus,
        'device_id' => $deviceId
    ]);
    
    // 清除相关缓存 - 签到后强力清理
    if (function_exists('clearStudentRelatedCache')) {
        clearStudentRelatedCache($studentId);
        $cacheLog = "[{$timestamp}] 已强力清除学生 {$studentId} 的所有相关缓存\n";
        file_put_contents($logFile, $cacheLog, FILE_APPEND);
    } else if (function_exists('clearCache')) {
        // 清除学生状态缓存
        clearCache(CACHE_KEY_STUDENT_STATUS, ['student_id' => $studentId]);
        clearCache(CACHE_KEY_STUDENT_STATUS, ['student_id' => $studentId, 'type' => 'latest']);
        clearCache(CACHE_KEY_STUDENT_STATUS, ['student_id' => $studentId, 'type' => 'today']);
        
        // 清除统计缓存
        clearCache(CACHE_KEY_ALL_STATUS_DATE, ['date' => date('Y-m-d')]);
        clearCache(CACHE_KEY_ALL_STUDENTS);
        
        // 记录缓存清理日志
        $cacheLog = "[{$timestamp}] 已清除学生 {$studentId} 的缓存和统计缓存\n";
        file_put_contents($logFile, $cacheLog, FILE_APPEND);
    }
    
    // 返回成功响应 - 包含更详细信息
    // ⭐ 优化：如果是指纹方式且已有dormitory，直接使用
    if ($isFingerprint && isset($student['dormitory']) && !empty($student['dormitory'])) {
        $dormitory = $student['dormitory'];
    } else {
        $dormitory = $student['building'] . '号楼' . $student['building_area'] . $student['building_floor'] . '-' . $student['room_number'] . ' (' . $student['bed_number'] . '号床)';
    }
    
    $response = [
        'success' => true,
        'student_id' => $studentId,
        'name' => $student['name'],
        'class_name' => $student['class_name'],
        'dormitory' => $dormitory,
        'building' => $student['building'],
        'building_area' => $student['building_area'],
        'building_floor' => $student['building_floor'],
        'room_number' => $student['room_number'],
        'bed_number' => $student['bed_number'],
        'status' => $newStatus,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'check_time' => $checkTime,
        'is_fingerprint' => $isFingerprint
    ];
    
    // 如果有楼号信息，添加到响应中
    if ($buildingNumber !== null) {
        $response['building_number'] = $buildingNumber;
        $response['device_sequence'] = $deviceSequence;
    }
    $responseJson = json_encode($response);

    // 记录响应
    $logData = "[{$timestamp}] 响应: {$responseJson}\n";
    file_put_contents($logFile, $logData, FILE_APPEND);

    echo $responseJson;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误'
    ]);
    
    // 记录错误日志
    $errorLog = "[{$timestamp}] 错误: " . $e->getMessage() . "\n";
    file_put_contents($logFile, $errorLog, FILE_APPEND);
}
?> 