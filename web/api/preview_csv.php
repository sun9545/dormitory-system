<?php
// 请假管理CSV预览接口 - 重定向到统一接口
require_once '../config/config.php';
require_once '../utils/auth.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 开始安全会话
startSecureSession();

// 检查登录状态
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '方法不允许']);
    exit;
}

// 验证CSRF令牌
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 检查文件上传
if (!isset($_FILES['preview_file']) || $_FILES['preview_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '文件上传失败']);
    exit;
}

// 转发到统一接口
$_POST['preview_type'] = 'leave'; // 设置预览类型为请假

// 标识这是被包含的文件
define('INCLUDED_FROM_OTHER_FILE', true);

// 包含统一处理逻辑
require_once 'preview_csv_unified.php';