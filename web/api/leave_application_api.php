<?php
/**
 * 请假申请API接口
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../utils/session_manager.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/captcha.php';
require_once __DIR__ . '/../models/leave_application.php';

// 启动session（使用统一管理器）
SessionManager::start();

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 获取请求动作
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 创建模型实例
$leaveApp = new LeaveApplication();

try {
    switch ($action) {
        // 验证学生身份
        case 'verify_student':
            if (getRequestMethod() !== 'POST') {
                echo json_encode(['success' => false, 'message' => '请求方法错误']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $studentId = isset($data['student_id']) ? trim($data['student_id']) : '';
            $name = isset($data['name']) ? trim($data['name']) : '';
            $captcha = isset($data['captcha']) ? trim($data['captcha']) : '';
            
            if (empty($studentId) || empty($name)) {
                echo json_encode(['success' => false, 'message' => '学号和姓名不能为空']);
                exit;
            }
            
            if (empty($captcha)) {
                echo json_encode(['success' => false, 'message' => '请输入验证码']);
                exit;
            }
            
            // 验证验证码
            $captchaResult = Captcha::verify($captcha);
            
            // 调试信息
            error_log("验证码调试 - 输入: {$captcha}, Session中的: " . (isset($_SESSION['captcha_code']) ? $_SESSION['captcha_code'] : '(不存在)') . ", Session ID: " . session_id() . ", 验证结果: " . ($captchaResult ? '成功' : '失败'));
            
            if (!$captchaResult) {
                echo json_encode([
                    'success' => false, 
                    'message' => '验证码错误或已过期',
                    'debug' => [
                        'input' => $captcha,
                        'session_code' => isset($_SESSION['captcha_code']) ? $_SESSION['captcha_code'] : '(不存在)',
                        'session_id' => session_id()
                    ]
                ]);
                exit;
            }
            
            // 验证学生身份
            $result = $leaveApp->verifyStudent($studentId, $name);
            echo json_encode($result);
            break;
        
        // 提交请假申请
        case 'submit_application':
            if (getRequestMethod() !== 'POST') {
                echo json_encode(['success' => false, 'message' => '请求方法错误']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $studentId = isset($data['student_id']) ? trim($data['student_id']) : '';
            $name = isset($data['name']) ? trim($data['name']) : '';
            $leaveDates = isset($data['leave_dates']) ? $data['leave_dates'] : [];
            $reason = isset($data['reason']) ? trim($data['reason']) : '';
            
            if (empty($studentId) || empty($name)) {
                echo json_encode(['success' => false, 'message' => '学号和姓名不能为空']);
                exit;
            }
            
            if (empty($leaveDates) || !is_array($leaveDates)) {
                echo json_encode(['success' => false, 'message' => '请选择请假日期']);
                exit;
            }
            
            if (empty($reason)) {
                echo json_encode(['success' => false, 'message' => '请填写请假原因']);
                exit;
            }
            
            // 再次验证学生身份
            $verifyResult = $leaveApp->verifyStudent($studentId, $name);
            if (!$verifyResult['success']) {
                echo json_encode($verifyResult);
                exit;
            }
            
            // 提交申请
            $result = $leaveApp->submitApplication($studentId, $leaveDates, $reason, 'student');
            echo json_encode($result);
            break;
        
        // 获取学生的申请列表
        case 'get_my_applications':
            if (getRequestMethod() !== 'POST') {
                echo json_encode(['success' => false, 'message' => '请求方法错误']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $studentId = isset($data['student_id']) ? trim($data['student_id']) : '';
            $name = isset($data['name']) ? trim($data['name']) : '';
            
            if (empty($studentId) || empty($name)) {
                echo json_encode(['success' => false, 'message' => '学号和姓名不能为空']);
                exit;
            }
            
            // 验证学生身份
            $verifyResult = $leaveApp->verifyStudent($studentId, $name);
            if (!$verifyResult['success']) {
                echo json_encode($verifyResult);
                exit;
            }
            
            // 获取申请列表
            $result = $leaveApp->getApplicationsByStudent($studentId);
            echo json_encode($result);
            break;
        
        // 撤销申请
        case 'cancel_application':
            if (getRequestMethod() !== 'POST') {
                echo json_encode(['success' => false, 'message' => '请求方法错误']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $applicationId = isset($data['application_id']) ? (int)$data['application_id'] : 0;
            $studentId = isset($data['student_id']) ? trim($data['student_id']) : '';
            $name = isset($data['name']) ? trim($data['name']) : '';
            
            if (empty($studentId) || empty($name)) {
                echo json_encode(['success' => false, 'message' => '学号和姓名不能为空']);
                exit;
            }
            
            if ($applicationId <= 0) {
                echo json_encode(['success' => false, 'message' => '申请ID无效']);
                exit;
            }
            
            // 验证学生身份
            $verifyResult = $leaveApp->verifyStudent($studentId, $name);
            if (!$verifyResult['success']) {
                echo json_encode($verifyResult);
                exit;
            }
            
            // 撤销申请
            $result = $leaveApp->cancelApplication($applicationId, $studentId);
            echo json_encode($result);
            break;
        
        // ========== 以下为辅导员/管理员接口（需要登录） ==========
        
        // 获取待审批列表
        case 'get_pending_applications':
            if (!isLoggedIn()) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            
            if ($_SESSION['role'] === 'admin') {
                $result = $leaveApp->getAllApplications($status);
            } else if ($_SESSION['role'] === 'counselor') {
                $result = $leaveApp->getApplicationsByCounselor($_SESSION['username'], $status);
            } else {
                echo json_encode(['success' => false, 'message' => '权限不足']);
                exit;
            }
            
            echo json_encode($result);
            break;
        
        // 审批通过
        case 'approve_application':
            if (!isLoggedIn()) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'counselor') {
                echo json_encode(['success' => false, 'message' => '权限不足']);
                exit;
            }
            
            if (getRequestMethod() !== 'POST') {
                echo json_encode(['success' => false, 'message' => '请求方法错误']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $applicationId = isset($data['application_id']) ? (int)$data['application_id'] : 0;
            
            if ($applicationId <= 0) {
                echo json_encode(['success' => false, 'message' => '申请ID无效']);
                exit;
            }
            
            // 审批
            $result = $leaveApp->approveApplication($applicationId, $_SESSION['user_id']);
            
            // 记录操作日志
            if ($result['success']) {
                logOperation($_SESSION['user_id'], '审批请假', "批准请假申请 ID: {$applicationId}");
            }
            
            echo json_encode($result);
            break;
        
        // 拒绝申请
        case 'reject_application':
            if (!isLoggedIn()) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'counselor') {
                echo json_encode(['success' => false, 'message' => '权限不足']);
                exit;
            }
            
            if (getRequestMethod() !== 'POST') {
                echo json_encode(['success' => false, 'message' => '请求方法错误']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $applicationId = isset($data['application_id']) ? (int)$data['application_id'] : 0;
            
            if ($applicationId <= 0) {
                echo json_encode(['success' => false, 'message' => '申请ID无效']);
                exit;
            }
            
            // 拒绝
            $result = $leaveApp->rejectApplication($applicationId, $_SESSION['user_id']);
            
            // 记录操作日志
            if ($result['success']) {
                logOperation($_SESSION['user_id'], '审批请假', "拒绝请假申请 ID: {$applicationId}");
            }
            
            echo json_encode($result);
            break;
        
        // 取消已批准的请假
        case 'revoke_leave':
            if (!isLoggedIn()) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'counselor') {
                echo json_encode(['success' => false, 'message' => '权限不足']);
                exit;
            }
            
            if (getRequestMethod() !== 'POST') {
                echo json_encode(['success' => false, 'message' => '请求方法错误']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $applicationId = isset($data['application_id']) ? (int)$data['application_id'] : 0;
            
            if ($applicationId <= 0) {
                echo json_encode(['success' => false, 'message' => '申请ID无效']);
                exit;
            }
            
            // 取消请假
            $result = $leaveApp->revokeApprovedLeave($applicationId);
            
            // 记录操作日志
            if ($result['success']) {
                logOperation($_SESSION['user_id'], '取消请假', "取消已批准的请假 ID: {$applicationId}");
            }
            
            echo json_encode($result);
            break;
        
        default:
            echo json_encode(['success' => false, 'message' => '未知的操作']);
            break;
    }
} catch (Exception $e) {
    error_log("请假申请API错误: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '系统错误：' . $e->getMessage()]);
}
?>

