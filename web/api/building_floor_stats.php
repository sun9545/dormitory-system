<?php
/**
 * 楼栋楼层统计API
 * 根据设备ID返回该楼栋所有楼层的统计数据（按区域+楼层分组）
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 只引入必要文件避免header冲突
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

// API Token验证
$headers = getallheaders();
$receivedToken = isset($headers['X-Api-Token']) ? $headers['X-Api-Token'] : '';

if ($receivedToken !== ENV_API_TOKEN) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授权的访问']);
    exit;
}

// 获取请求数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$deviceId = $data['device_id'] ?? '';
$date = $data['date'] ?? date('Y-m-d');

if (empty($deviceId)) {
    echo json_encode(['success' => false, 'message' => '设备ID不能为空']);
    exit;
}

// 设备ID到楼栋映射
$deviceMapping = [
    'FP001-7-1' => ['building' => 7, 'area' => 'B', 'floors' => [1,2,3]],
    'FP001-9-1' => ['building' => 9, 'area' => 'A', 'floors' => [1,2,3]],
    'FP001-9-2' => ['building' => 9, 'area' => 'A', 'floors' => [4,5,6]],
    'FP001-9-3' => ['building' => 9, 'area' => 'B', 'floors' => [1,2,3]],
    'FP001-9-4' => ['building' => 9, 'area' => 'B', 'floors' => [4,5,6]],
    'FP001-9-5' => ['building' => 9, 'area' => 'A', 'floors' => [1,2,3]],
    'FP001-9-6' => ['building' => 9, 'area' => 'A', 'floors' => [4,5,6]],
    'FP001-10-1' => ['building' => 10, 'area' => 'A', 'floors' => [1,2,3]],
    'FP001-10-2' => ['building' => 10, 'area' => 'A', 'floors' => [4,5,6]],
];

// 检查设备是否存在
if (!isset($deviceMapping[$deviceId])) {
    echo json_encode([
        'success' => false,
        'message' => '未知的设备ID: ' . $deviceId
    ]);
    exit;
}

$deviceInfo = $deviceMapping[$deviceId];
$building = $deviceInfo['building'];
$currentArea = $deviceInfo['area'];
$currentFloors = $deviceInfo['floors'];

try {
    $db = getDBConnection();
    
    // 查询该楼栋所有楼层的统计数据
    // ⭐ 修复：使用和控制面板完全一致的SQL逻辑
    $query = "
        SELECT 
            s.building,
            s.building_area as area,
            s.building_floor as floor,
            COUNT(s.student_id) as total_students,
            SUM(CASE WHEN IFNULL(latest.status, '未签到') = '在寝' THEN 1 ELSE 0 END) as total_present,
            SUM(CASE WHEN IFNULL(latest.status, '未签到') = '离寝' THEN 1 ELSE 0 END) as total_absent,
            SUM(CASE WHEN IFNULL(latest.status, '未签到') = '请假' THEN 1 ELSE 0 END) as total_leave,
            SUM(CASE WHEN IFNULL(latest.status, '未签到') = '未签到' THEN 1 ELSE 0 END) as total_not_checked
        FROM students s
        LEFT JOIN (
            SELECT c1.student_id, c1.status
            FROM check_records c1
            INNER JOIN (
                SELECT student_id, 
                       MAX(CONCAT(check_time, '-', LPAD(id, 10, '0'))) as max_combined
                FROM check_records
                WHERE DATE(check_time) = ?
                GROUP BY student_id
            ) c2 ON c1.student_id = c2.student_id 
                AND CONCAT(c1.check_time, '-', LPAD(c1.id, 10, '0')) = c2.max_combined
            WHERE DATE(c1.check_time) = ?
        ) latest ON s.student_id = latest.student_id
        WHERE s.building = ?
        GROUP BY s.building, s.building_area, s.building_floor
        ORDER BY s.building_area ASC, s.building_floor ASC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$date, $date, $building]);
    
    $floors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 标记当前设备负责的楼层
    foreach ($floors as &$floor) {
        $floor['is_current_device'] = (
            $floor['area'] === $currentArea && 
            in_array((int)$floor['floor'], $currentFloors)
        );
        
        // 转换为整数类型
        $floor['total_students'] = (int)$floor['total_students'];
        $floor['total_present'] = (int)$floor['total_present'];
        $floor['total_absent'] = (int)$floor['total_absent'];
        $floor['total_leave'] = (int)$floor['total_leave'];
        $floor['total_not_checked'] = (int)$floor['total_not_checked'];
        $floor['floor'] = (int)$floor['floor'];
    }
    unset($floor); // 释放引用，避免后续foreach覆盖最后一个元素
    
    // 计算总计
    $totalStudents = 0;
    $totalPresent = 0;
    $totalAbsent = 0;
    $totalLeave = 0;
    $totalNotChecked = 0;
    
    foreach ($floors as $floor) {
        $totalStudents += $floor['total_students'];
        $totalPresent += $floor['total_present'];
        $totalAbsent += $floor['total_absent'];
        $totalLeave += $floor['total_leave'];
        $totalNotChecked += $floor['total_not_checked'];
    }
    
    echo json_encode([
        'success' => true,
        'device_id' => $deviceId,
        'building' => $building . '号楼',
        'date' => $date,
        'current_area' => $currentArea,
        'current_floors' => $currentFloors,
        'total_students' => $totalStudents,
        'total_present' => $totalPresent,
        'total_absent' => $totalAbsent,
        'total_leave' => $totalLeave,
        'total_not_checked' => $totalNotChecked,
        'floor_count' => count($floors),
        'floors' => $floors
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("楼栋楼层统计查询失败: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库查询失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

