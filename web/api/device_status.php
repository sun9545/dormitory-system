<?php
/**
 * 设备状态实时检测API
 * 用于前端AJAX轮询获取设备状态
 */

require_once '../config/config.php';
require_once '../utils/auth.php';
require_once '../models/device.php';

// 检查用户是否已登录
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未登录'
    ]);
    exit;
}

// 设置JSON响应头
header('Content-Type: application/json');

try {
    $deviceModel = new Device();
    $devices = $deviceModel->getAllDevices();
    
    $deviceStatuses = [];
    
    foreach ($devices as $device) {
        $statusClass = '';
        $statusText = '';
        $isRecentlyOnline = false;
        
        // 智能在线检测：结合签到和心跳  
        if ($device['last_seen']) {
            $lastSeenTime = strtotime($device['last_seen']);
            $currentTime = time();
            $timeDiff = $currentTime - $lastSeenTime;
            
            // 智能阈值策略：
            // 1. 最近30秒内有活动（签到）-> 立即在线
            // 2. 最近120秒内有活动（心跳）-> 在线
            $isRecentlyOnline = ($timeDiff <= 120); // 120秒 = 心跳阈值
        }
        
        switch ($device['status']) {
            case 'active':
                if ($isRecentlyOnline) {
                    $statusClass = 'success';
                    $statusText = '在线';
                } else {
                    $statusClass = 'danger';
                    $statusText = '离线';
                }
                break;
            case 'inactive':
                $statusClass = 'secondary';
                $statusText = '已停用';
                break;
            case 'maintenance':
                $statusClass = 'warning';
                $statusText = '维护中';
                break;
        }
        
        $deviceStatuses[] = [
            'device_id' => $device['device_id'],
            'status_class' => $statusClass,
            'status_text' => $statusText,
            'last_seen' => $device['last_seen'],
            'last_seen_formatted' => $device['last_seen'] ? date('Y-m-d H:i:s', strtotime($device['last_seen'])) : null,
            'time_diff' => $device['last_seen'] ? (time() - strtotime($device['last_seen'])) : null,
            'is_online' => $isRecentlyOnline,
            'management_status' => $device['status']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'devices' => $deviceStatuses,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("设备状态API错误: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误'
    ]);
}
?>