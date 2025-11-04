<?php
/**
 * 设备心跳API接口
 * 用于ESP32设备定期发送心跳，保持在线状态
 */

// 引入必要的配置
require_once '../config/config.php';

// 设置JSON响应头
header('Content-Type: application/json');

// 记录心跳访问日志
$logFile = ROOT_PATH . '/logs/heartbeat.log';
$requestData = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');

// 确保日志目录存在
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

// 验证API令牌
$headers = getallheaders();
$apiToken = isset($headers['X-Api-Token']) ? $headers['X-Api-Token'] : '';

// 检查是否需要API令牌验证
if (defined('ENV_API_TOKEN_REQUIRED') && ENV_API_TOKEN_REQUIRED) {
    if (empty($apiToken) || $apiToken !== ENV_API_TOKEN) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => '未授权访问'
        ]);
        
        // 记录未授权访问
        $unauthorizedLog = "[{$timestamp}] 心跳未授权访问: Token=" . $apiToken . "\n";
        file_put_contents($logFile, $unauthorizedLog, FILE_APPEND);
        exit;
    }
}

// 解析JSON数据
$data = json_decode($requestData, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '无效的JSON数据'
    ]);
    exit;
}

// 验证必需参数
if (empty($data['device_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '缺少设备ID参数'
    ]);
    exit;
}

$deviceId = $data['device_id'];

try {
    $db = getDBConnection();
    
    // 验证设备是否存在
    $stmt = $db->prepare("SELECT device_id FROM devices WHERE device_id = ?");
    $stmt->execute([$deviceId]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => '设备不存在'
        ]);
        
        // 记录设备不存在日志
        $notFoundLog = "[{$timestamp}] 心跳请求 - 设备不存在: {$deviceId}\n";
        file_put_contents($logFile, $notFoundLog, FILE_APPEND);
        exit;
    }
    
    // 获取客户端IP地址
    $clientIP = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $clientIP = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $clientIP = $_SERVER['REMOTE_ADDR'];
    }
    
    // 更新设备心跳时间
    $updateStmt = $db->prepare("
        UPDATE devices 
        SET last_seen = CURRENT_TIMESTAMP, ip_address = ? 
        WHERE device_id = ?
    ");
    $result = $updateStmt->execute([$clientIP, $deviceId]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => '心跳更新成功',
            'device_id' => $deviceId,
            'timestamp' => $timestamp,
            'ip_address' => $clientIP
        ]);
        
        // 记录成功日志（仅每10次记录一次，避免日志过多）
        $heartbeatCount = intval($data['heartbeat_count'] ?? 0);
        if ($heartbeatCount % 10 === 0 || $heartbeatCount <= 5) {
            $successLog = "[{$timestamp}] 心跳成功: {$deviceId}, IP: {$clientIP}, 次数: {$heartbeatCount}\n";
            file_put_contents($logFile, $successLog, FILE_APPEND);
        }
    } else {
        throw new Exception('数据库更新失败');
    }
    
} catch (Exception $e) {
    error_log("设备心跳API错误: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误'
    ]);
    
    // 记录错误日志
    $errorLog = "[{$timestamp}] 心跳错误: {$deviceId} - {$e->getMessage()}\n";
    file_put_contents($logFile, $errorLog, FILE_APPEND);
}
?>