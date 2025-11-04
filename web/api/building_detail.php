<?php
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
    $input = json_decode(file_get_contents('php://input'), true);
    $deviceId = $input['device_id'] ?? '';
    $buildingName = $input['building_name'] ?? '';
    $date = $input['date'] ?? date('Y-m-d');
    
    if (empty($buildingName)) {
        throw new Exception('楼栋名称不能为空');
    }
    
    // 创建数据库连接
    $student = new Student();
    $checkRecord = new CheckRecord();
    
    // 解析楼栋名称，例如 "1A" -> building=1, area=A
    $building = '';
    $buildingArea = '';
    if (preg_match('/^(\d+)([AB])$/', $buildingName, $matches)) {
        $building = $matches[1];
        $buildingArea = $matches[2];
    } else {
        throw new Exception('楼栋名称格式不正确，应为如"1A"、"2B"的格式');
    }
    
    // 获取该楼栋区域的所有学生信息，按楼层分组
    $buildingStudents = $checkRecord->getBuildingAreaDetailByDate($building, $buildingArea, $date);
    
    // 按楼层分组统计
    $floorStats = [];
    $buildingTotal = [
        'total_students' => 0,
        'total_present' => 0,
        'total_absent' => 0,
        'total_leave' => 0,
        'total_not_checked' => 0
    ];
    
    foreach ($buildingStudents as $studentData) {
        $floor = $studentData['floor'] ?? '未知楼层';
        
        if (!isset($floorStats[$floor])) {
            $floorStats[$floor] = [
                'floor' => $floor,
                'total_students' => 0,
                'total_present' => 0,
                'total_absent' => 0,
                'total_leave' => 0,
                'total_not_checked' => 0,
                // 不返回学生详细信息，减少数据量
            ];
        }
        
        $floorStats[$floor]['total_students']++;
        $buildingTotal['total_students']++;
        
        // 不存储学生详细信息，只统计数量
        
        // 统计各状态人数
        switch ($studentData['status']) {
            case '在寝':
                $floorStats[$floor]['total_present']++;
                $buildingTotal['total_present']++;
                break;
            case '离寝':
                $floorStats[$floor]['total_absent']++;
                $buildingTotal['total_absent']++;
                break;
            case '请假':
                $floorStats[$floor]['total_leave']++;
                $buildingTotal['total_leave']++;
                break;
            case '未签到':
            default:
                $floorStats[$floor]['total_not_checked']++;
                $buildingTotal['total_not_checked']++;
                break;
        }
    }
    
    // 按楼层号排序
    ksort($floorStats);
    
    $response = [
        'success' => true,
        'message' => '楼栋详细信息获取成功',
        'data' => [
            'building_name' => $buildingName,
            'date' => $date,
            'device_id' => $deviceId,
            'building_total' => $buildingTotal,
            'floor_count' => count($floorStats),
            'floors' => array_values($floorStats),
            'update_time' => date('Y-m-d H:i:s')
        ]
    ];
    
    // 记录成功日志
    error_log("楼栋详细信息返回: {$buildingName}楼，共" . count($floorStats) . "层，{$buildingTotal['total_students']}人");
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("楼栋详细信息API错误: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => '服务器错误',
        'error' => $e->getMessage()
    ]);
}
?>