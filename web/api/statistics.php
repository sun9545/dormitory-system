<?php
/**
 * 设备统计数据API接口
 * 为ESP32设备提供实时查寝统计数据
 */
require_once '../config/config.php';
require_once '../utils/auth.php';
require_once '../models/student.php';
require_once '../models/check_record.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 验证API令牌
$headers = getallheaders();
$apiToken = $headers['X-Api-Token'] ?? '';

if ($apiToken !== ENV_API_TOKEN) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

try {
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $deviceId = $input['device_id'] ?? '';
    $date = $input['date'] ?? date('Y-m-d');
    
    // 记录API调用日志
    error_log("统计API调用: 设备ID={$deviceId}, 日期={$date}");
    
    // 创建数据库连接
    $student = new Student();
    $checkRecord = new CheckRecord();
    
    // 获取当天的统计数据（和控制面板使用同样的方法）
    $studentStats = $checkRecord->getAllStudentsStatusByDate($date);
    
    // 统计各种状态的人数
    $totalStudents = count($studentStats);
    $totalPresent = 0;
    $totalAbsent = 0;
    $totalLeave = 0;
    $totalNotChecked = 0;
    
    foreach ($studentStats as $row) {
        switch ($row['status']) {
            case '在寝':
                $totalPresent++;
                break;
            case '离寝':
                $totalAbsent++;
                break;
            case '请假':
                $totalLeave++;
                break;
            case '未签到':
            default:
                $totalNotChecked++;
                break;
        }
    }
    
    // 计算在寝率
    $presentRate = $totalStudents > 0 ? round(($totalPresent / $totalStudents) * 100, 1) : 0;
    
    // 获取今日设备签到次数
    $todayCheckins = $checkRecord->getTodayCheckinCountByDevice($deviceId, $date);
    
    // 按楼栋+区域统计数据
    $buildingStats = [];
    foreach ($studentStats as $row) {
        $building = $row['building'] ?? '未知';
        $buildingArea = $row['building_area'] ?? 'A';
        $buildingKey = $building . $buildingArea; // 例如: "1A", "1B", "2A"
        $buildingDisplayName = $building . $buildingArea; // 显示为: "1A", "1B"
        
        if (!isset($buildingStats[$buildingKey])) {
            $buildingStats[$buildingKey] = [
                'building_name' => $buildingDisplayName,
                'total_students' => 0,
                'total_present' => 0,
                'total_absent' => 0,
                'total_leave' => 0,
                'total_not_checked' => 0
            ];
        }
        
        $buildingStats[$buildingKey]['total_students']++;
        
        switch ($row['status']) {
            case '在寝':
                $buildingStats[$buildingKey]['total_present']++;
                break;
            case '离寝':
                $buildingStats[$buildingKey]['total_absent']++;
                break;
            case '请假':
                $buildingStats[$buildingKey]['total_leave']++;
                break;
            case '未签到':
            default:
                $buildingStats[$buildingKey]['total_not_checked']++;
                break;
        }
    }
    
    // 转换为数组格式
    $buildingsArray = array_values($buildingStats);
    
    $response = [
        'success' => true,
        'message' => '统计数据获取成功',
        'data' => [
            'date' => $date,
            'device_id' => $deviceId,
            'total_students' => $totalStudents,
            'total_present' => $totalPresent,
            'total_absent' => $totalAbsent,
            'total_leave' => $totalLeave,
            'total_not_checked' => $totalNotChecked,
            'present_rate' => $presentRate,
            'today_checkins' => $todayCheckins,
            'buildings' => $buildingsArray,
            'building_count' => count($buildingsArray),
            'update_time' => date('Y-m-d H:i:s')
        ]
    ];
    
    // 记录成功日志
    error_log("统计数据返回: 总人数={$totalStudents}, 在寝={$totalPresent}, 离寝={$totalAbsent}");
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("统计API错误: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => '服务器错误',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>