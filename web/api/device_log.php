<?php
/**
 * 设备日志API
 * 用于接收ESP32设备的WiFi日志
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');

require_once '../config/config.php';
require_once '../utils/helpers.php';

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// API令牌验证
$headers = getallheaders();
$apiToken = $headers['X-Api-Token'] ?? '';

if ($apiToken !== 'GENERATE_YOUR_OWN_API_TOKEN') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 确保日志目录存在
$logDir = ROOT_PATH . '/logs/device';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

// 获取当前日期
$currentDate = date('Y-m-d');
$logFile = $logDir . '/' . $currentDate . '_device.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 接收设备日志
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    
    // 验证必要字段
    $requiredFields = ['device_id', 'timestamp', 'log_level', 'message'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing field: $field"]);
            exit;
        }
    }
    
    // 构建日志条目
    $timestamp = date('Y-m-d H:i:s', $data['timestamp']);
    $deviceId = $data['device_id'];
    $logLevel = strtoupper($data['log_level']);
    $component = $data['component'] ?? 'system';
    $message = $data['message'];
    $ipAddress = $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'];
    $memoryFree = $data['memory_free'] ?? 0;
    $uptime = $data['uptime'] ?? 0;
    
    // 格式化日志条目
    $logEntry = sprintf(
        "[%s] [%s] [%s] [%s] %s | IP: %s | MEM: %d | UP: %dms\n",
        $timestamp,
        $logLevel,
        $deviceId,
        $component,
        $message,
        $ipAddress,
        $memoryFree,
        $uptime
    );
    
    // 写入日志文件
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // 同时写入数据库（可选）
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO device_logs (device_id, log_level, component, message, ip_address, memory_free, uptime, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $deviceId,
            $logLevel,
            $component,
            $message,
            $ipAddress,
            $memoryFree,
            $uptime,
            $timestamp
        ]);
    } catch (Exception $e) {
        // 数据库写入失败，仅记录到文件日志
        error_log("Device log DB insert failed: " . $e->getMessage());
    }
    
    echo json_encode(['success' => true, 'message' => 'Log received']);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 查询设备日志
    $deviceId = $_GET['device_id'] ?? '';
    $logLevel = $_GET['log_level'] ?? '';
    $component = $_GET['component'] ?? '';
    $limit = (int)($_GET['limit'] ?? 100);
    $page = (int)($_GET['page'] ?? 1);
    $date = $_GET['date'] ?? $currentDate;
    
    $logs = [];
    
    // 从文件读取日志
    $targetLogFile = $logDir . '/' . $date . '_device.log';
    
    if (file_exists($targetLogFile)) {
        $lines = file($targetLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines); // 最新的在前
        
        foreach ($lines as $line) {
            // 解析日志行
            if (preg_match('/\[(.*?)\] \[(.*?)\] \[(.*?)\] \[(.*?)\] (.*?) \| IP: (.*?) \| MEM: (\d+) \| UP: (\d+)ms/', $line, $matches)) {
                $logEntry = [
                    'timestamp' => $matches[1],
                    'log_level' => $matches[2],
                    'device_id' => $matches[3],
                    'component' => $matches[4],
                    'message' => $matches[5],
                    'ip_address' => $matches[6],
                    'memory_free' => (int)$matches[7],
                    'uptime' => (int)$matches[8]
                ];
                
                // 应用过滤器
                if ($deviceId && $logEntry['device_id'] !== $deviceId) continue;
                if ($logLevel && $logEntry['log_level'] !== strtoupper($logLevel)) continue;
                if ($component && $logEntry['component'] !== $component) continue;
                
                $logs[] = $logEntry;
            }
        }
    }
    
    // 分页
    $total = count($logs);
    $offset = ($page - 1) * $limit;
    $logs = array_slice($logs, $offset, $limit);
    
    echo json_encode([
        'success' => true,
        'data' => $logs,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>