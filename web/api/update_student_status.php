<?php
/**
 * 学生状态更新API接口
 * 用于AJAX请求更新学生状态
 */

require_once '../config/config.php';
require_once '../utils/helpers.php';
require_once '../utils/auth.php';
require_once '../models/check_record.php';

// 设置JSON响应头
header('Content-Type: application/json');

// 检查用户登录状态
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '请先登录'
    ]);
    exit;
}

// 检查请求方法
if (getRequestMethod() !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '不支持的请求方法'
    ]);
    exit;
}

// 获取POST数据
$input = json_decode(file_get_contents('php://input'), true);

// 验证必要参数
if (!isset($input['student_id']) || !isset($input['status']) || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '缺少必要参数'
    ]);
    exit;
}

// 验证CSRF令牌
if (!verifyCSRFToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '安全验证失败'
    ]);
    exit;
}

// 验证状态值
$validStatuses = ['在寝', '离寝', '请假'];
if (!in_array($input['status'], $validStatuses)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '无效的状态值'
    ]);
    exit;
}

try {
    // 清理输入数据
    $studentId = sanitizeInput($input['student_id']);
    $status = sanitizeInput($input['status']);
    
    // 获取当前用户信息
    $currentUser = getCurrentUser();
    
    // 更新学生状态
    $checkRecord = new CheckRecord();
    $result = $checkRecord->updateStudentStatus($studentId, $status, $currentUser['id']);
    
    if ($result) {
        // 记录操作日志
        $logMessage = "手动更新学生 {$studentId} 状态为 {$status}";
        logSecurityEvent('STATUS_UPDATE', $logMessage);
        
        // 获取状态样式类
        $statusClass = getStatusClass($status);
        
        echo json_encode([
            'success' => true,
            'message' => '状态更新成功',
            'data' => [
                'student_id' => $studentId,
                'status' => $status,
                'status_class' => $statusClass,
                'updated_by' => $currentUser['name'],
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '状态更新失败，请重试'
        ]);
    }
    
} catch (Exception $e) {
    error_log('Status update error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误'
    ]);
}
?>