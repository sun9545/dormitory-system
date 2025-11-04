<?php
/**
 * 设备信息API接口
 */
require_once '../config/config.php';
require_once '../utils/auth.php';
require_once '../utils/helpers.php';
require_once '../models/device.php';

// 设置JSON响应头
header('Content-Type: application/json');

// 检查登录状态
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 检查是否为AJAX请求
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的请求']);
    exit;
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的请求数据']);
    exit;
}

$action = isset($input['action']) ? $input['action'] : '';
$deviceModel = new Device();

try {
    switch ($action) {
        case 'get_device':
            if (!isset($input['device_id'])) {
                throw new Exception('缺少设备ID参数');
            }
            
            $deviceId = sanitizeInput($input['device_id']);
            $device = $deviceModel->getDeviceById($deviceId);
            
            if (!$device) {
                throw new Exception('设备不存在');
            }
            
            echo json_encode([
                'success' => true,
                'device' => $device
            ]);
            break;
            
        case 'get_all_devices':
            $devices = $deviceModel->getAllDevices();
            echo json_encode([
                'success' => true,
                'devices' => $devices
            ]);
            break;
            
        default:
            throw new Exception('无效的操作');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>