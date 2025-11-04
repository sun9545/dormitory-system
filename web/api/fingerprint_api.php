<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/fingerprint.php';
require_once __DIR__ . '/../models/device.php';

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '仅支持POST请求'
    ]);
    exit;
}

// 获取请求数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '无效的JSON数据'
    ]);
    exit;
}

// 验证必需参数
if (empty($data['action'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '缺少action参数'
    ]);
    exit;
}

$fingerprintModel = new Fingerprint();
$deviceModel = new Device();
$action = $data['action'];

try {
    switch ($action) {
        case 'verify':
            // 指纹验证
            if (empty($data['device_id']) || !isset($data['fingerprint_id'])) {
                throw new Exception('缺少必需参数: device_id, fingerprint_id');
            }
            
            $deviceId = $data['device_id'];
            $fingerprintId = intval($data['fingerprint_id']);
            
            // 验证设备是否存在且激活
            $device = $deviceModel->getDeviceById($deviceId);
            if (!$device || $device['status'] !== 'active') {
                throw new Exception('设备不存在或未激活');
            }
            
            // 验证指纹
            $student = $fingerprintModel->verifyFingerprint($deviceId, $fingerprintId);
            if (!$student) {
                echo json_encode([
                    'success' => false,
                    'message' => '指纹验证失败',
                    'code' => 'FINGERPRINT_NOT_FOUND'
                ]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'message' => '指纹验证成功',
                'data' => [
                    'student_id' => $student['student_id'],
                    'name' => $student['name'],
                    'class' => $student['class'],
                    'dormitory_number' => $student['dormitory_number'],
                    'finger_index' => $student['finger_index'],
                    'checkin_count' => $student['checkin_count'],
                    'last_checkin' => $student['last_checkin']
                ]
            ]);
            break;
            
        case 'checkin':
            // 记录签到
            if (empty($data['device_id']) || !isset($data['fingerprint_id'])) {
                throw new Exception('缺少必需参数: device_id, fingerprint_id');
            }
            
            $deviceId = $data['device_id'];
            $fingerprintId = intval($data['fingerprint_id']);
            $checkinType = $data['checkin_type'] ?? 'in';
            $confidence = isset($data['confidence']) ? floatval($data['confidence']) : null;
            
            // 验证设备
            $device = $deviceModel->getDeviceById($deviceId);
            if (!$device || $device['status'] !== 'active') {
                throw new Exception('设备不存在或未激活');
            }
            
            // 验证指纹并获取学生信息
            $student = $fingerprintModel->verifyFingerprint($deviceId, $fingerprintId);
            if (!$student) {
                echo json_encode([
                    'success' => false,
                    'message' => '指纹验证失败',
                    'code' => 'FINGERPRINT_NOT_FOUND'
                ]);
                exit;
            }
            
            // 记录签到
            $result = $fingerprintModel->recordCheckin(
                $student['student_id'], 
                $deviceId, 
                $fingerprintId, 
                $checkinType, 
                $confidence
            );
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => '签到记录成功',
                    'data' => [
                        'student_id' => $student['student_id'],
                        'name' => $student['name'],
                        'checkin_type' => $checkinType,
                        'checkin_time' => date('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                throw new Exception('签到记录失败');
            }
            break;
            
        case 'enroll_status':
            // 更新指纹录入状态
            if (empty($data['student_id']) || empty($data['device_id']) || 
                !isset($data['finger_index']) || empty($data['status'])) {
                throw new Exception('缺少必需参数: student_id, device_id, finger_index, status');
            }
            
            $result = $fingerprintModel->updateEnrollmentStatus(
                $data['student_id'],
                $data['device_id'],
                intval($data['finger_index']),
                $data['status']
            );
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => '录入状态更新成功'
                ]);
            } else {
                throw new Exception('录入状态更新失败');
            }
            break;
            
        case 'device_status':
            // 获取设备状态
            if (empty($data['device_id'])) {
                throw new Exception('缺少必需参数: device_id');
            }
            
            $device = $deviceModel->getDeviceById($data['device_id']);
            if (!$device) {
                throw new Exception('设备不存在');
            }
            
            // 检查设备在线状态
            $onlineStatus = $deviceModel->checkDeviceOnline($data['device_id']);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'device_id' => $device['device_id'],
                    'device_name' => $device['device_name'],
                    'status' => $device['status'],
                    'building_number' => $device['building_number'],
                    'current_fingerprints' => $device['current_fingerprints'],
                    'max_fingerprints' => $device['max_fingerprints'],
                    'usage_percentage' => $device['usage_percentage'],
                    'online' => $onlineStatus['online'],
                    'ip_address' => $device['ip_address']
                ]
            ]);
            break;
            
        case 'assign_fingerprint':
            // 分配指纹编号
            if (empty($data['student_id']) || empty($data['device_id'])) {
                throw new Exception('缺少必需参数: student_id, device_id');
            }
            
            $result = $fingerprintModel->assignFingerprintId(
                $data['student_id'],
                $data['device_id'],
                $data['finger_index'] ?? 1
            );
            
            echo json_encode($result);
            break;
            
        case 'assign_fingerprint_multidevice':
            // 分配指纹编号（支持多设备录入）
            if (empty($data['student_id']) || empty($data['device_id'])) {
                throw new Exception('缺少必需参数: student_id, device_id');
            }
            
            $result = $fingerprintModel->assignFingerprintIdMultiDevice(
                $data['student_id'],
                $data['device_id'],
                $data['finger_index'] ?? 1
            );
            
            echo json_encode($result);
            break;
            
        case 'get_student_info':
            // 获取学生信息（⭐ 优化：支持同时获取指纹ID）
            if (empty($data['student_id'])) {
                throw new Exception('缺少必需参数: student_id');
            }
            
            // ⭐ device_id 是可选参数，如果提供了就查询该设备上的指纹ID
            $deviceId = !empty($data['device_id']) ? $data['device_id'] : null;
            $result = $fingerprintModel->getStudentInfo($data['student_id'], $deviceId);
            
            echo json_encode($result);
            break;
            
        case 'remove_fingerprint':
            // 删除指纹映射
            if (empty($data['student_id']) || empty($data['device_id']) || !isset($data['finger_index'])) {
                throw new Exception('缺少必需参数: student_id, device_id, finger_index');
            }
            
            $result = $fingerprintModel->removeFingerprintMapping(
                $data['student_id'],
                $data['device_id'],
                intval($data['finger_index'])
            );
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => '指纹映射删除成功'
                ]);
            } else {
                throw new Exception('指纹映射删除失败');
            }
            break;
            
        case 'get_student_fingerprints':
            // 获取学生指纹信息
            if (empty($data['student_id'])) {
                throw new Exception('缺少必需参数: student_id');
            }
            
            $fingerprints = $fingerprintModel->getStudentFingerprints($data['student_id']);
            echo json_encode([
                'success' => true,
                'data' => $fingerprints
            ]);
            break;
            
        case 'get_device_fingerprints':
            // 获取设备指纹信息
            if (empty($data['device_id'])) {
                throw new Exception('缺少必需参数: device_id');
            }
            
            $limit = intval($data['limit'] ?? 50);
            $offset = intval($data['offset'] ?? 0);
            
            $fingerprints = $fingerprintModel->getDeviceFingerprints($data['device_id'], $limit, $offset);
            echo json_encode([
                'success' => true,
                'data' => $fingerprints
            ]);
            break;
            
        case 'checkin_stats':
            // 获取签到统计
            $filters = $data['filters'] ?? [];
            $stats = $fingerprintModel->getCheckinStats($filters);
            
            echo json_encode([
                'success' => true,
                'data' => $stats
            ]);
            break;
            
        case 'check_duplicate':
            // 检查重复映射
            if (empty($data['device_id']) || !isset($data['fingerprint_id']) || empty($data['student_id'])) {
                throw new Exception('缺少必需参数: device_id, fingerprint_id, student_id');
            }
            
            $deviceId = $data['device_id'];
            $fingerprintId = intval($data['fingerprint_id']);
            $studentId = $data['student_id'];
            
            // 执行重复检查
            $duplicateCheck = $fingerprintModel->checkDuplicateMapping($deviceId, $fingerprintId, $studentId);
            
            echo json_encode([
                'success' => true,
                'has_duplicate' => $duplicateCheck['has_duplicate'],
                'message' => $duplicateCheck['message']
            ]);
            break;
            
        default:
            throw new Exception('不支持的操作: ' . $action);
    }
    
} catch (Exception $e) {
    error_log("指纹API错误: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>