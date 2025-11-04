<?php
/**
 * 学生信息API
 * 用于前端异步获取学生信息
 */
require_once '../config/config.php';
require_once '../utils/auth.php';
require_once '../utils/helpers.php';
require_once '../models/student.php';
require_once '../models/check_record.php';

// 设置响应头为JSON
header('Content-Type: application/json');

// 记录API访问
$logFile = ROOT_PATH . '/logs/api_access.log';
$timestamp = date('Y-m-d H:i:s');
$logEntry = "[{$timestamp}] API访问: student_info.php, IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

// 检查用户是否已登录
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未登录或会话已过期'
    ]);
    
    // 记录未授权访问
    $unauthorizedLog = "[{$timestamp}] 未授权访问: IP=" . $_SERVER['REMOTE_ADDR'] . ", URL=" . $_SERVER['REQUEST_URI'] . "\n";
    file_put_contents($logFile, $unauthorizedLog, FILE_APPEND);
    exit;
}

// CSRF保护
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '禁止的请求类型'
    ]);
    exit;
}

// 限制请求频率
$sessionKey = 'last_api_request_' . md5($_SERVER['REQUEST_URI']);
$currentTime = time();
$lastRequestTime = isset($_SESSION[$sessionKey]) ? $_SESSION[$sessionKey] : 0;
$minInterval = 1; // 最小请求间隔(秒)

if ($currentTime - $lastRequestTime < $minInterval) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => '请求过于频繁，请稍后再试'
    ]);
    exit;
}

$_SESSION[$sessionKey] = $currentTime;

// 获取和验证请求参数
$studentId = isset($_GET['student_id']) ? sanitizeInput($_GET['student_id']) : '';

// 验证学号
if (empty($studentId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '学号不能为空'
    ]);
    exit;
}

// 验证学号格式
if (!preg_match('/^\d{10,12}$/', $studentId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '无效的学号格式'
    ]);
    exit;
}

try {
    // 创建数据模型实例
    $student = new Student();
    $checkRecord = new CheckRecord();

    // 获取学生信息
    $studentData = $student->getStudentById($studentId);

    if ($studentData) {
        // 获取学生当天状态
        $todayDate = date('Y-m-d');
        $statusData = $checkRecord->getStudentTodayStatus($studentId, $todayDate);
        $status = is_array($statusData) ? $statusData['status'] : '未签到';
        
        echo json_encode([
            'success' => true,
            'student' => $studentData,
            'status' => $status
        ]);
        
        // 记录成功响应
        $responseLog = "[{$timestamp}] 响应成功: student_id=" . $studentId . "\n";
        file_put_contents($logFile, $responseLog, FILE_APPEND);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => '未找到该学号的学生'
        ]);
        
        // 记录未找到学生
        $notFoundLog = "[{$timestamp}] 未找到学生: student_id=" . $studentId . "\n";
        file_put_contents($logFile, $notFoundLog, FILE_APPEND);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误'
    ]);
    
    // 记录错误
    $errorLog = "[{$timestamp}] 错误: " . $e->getMessage() . "\n";
    file_put_contents($logFile, $errorLog, FILE_APPEND);
}
?> 