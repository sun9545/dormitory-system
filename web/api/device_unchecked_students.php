<?php
/**
 * 设备未签到学生查询API
 * 根据设备ID返回对应楼层的未签到学生明细
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';

// API Token验证
$headers = getallheaders();
$receivedToken = isset($headers['X-Api-Token']) ? $headers['X-Api-Token'] : '';

if ($receivedToken !== API_TOKEN) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授权的访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取请求数据
$requestData = json_decode(file_get_contents('php://input'), true);

if (!$requestData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的请求数据'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deviceId = isset($requestData['device_id']) ? trim($requestData['device_id']) : '';
$date = isset($requestData['date']) ? trim($requestData['date']) : date('Y-m-d');

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '设备ID不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== 设备与楼层映射关系 ====================
$deviceMapping = [
    'FP001-7-1' => [
        'building' => 7,
        'area' => 'B',
        'floors' => [1, 2, 3],
        'description' => '7号楼 B区 1-3层'
    ],
    'FP001-9-1' => [
        'building' => 9,
        'area' => 'A',
        'floors' => [1, 2, 3],
        'description' => '9号楼 A区 1-3层'
    ],
    'FP001-9-2' => [
        'building' => 9,
        'area' => 'A',
        'floors' => [4, 5, 6],
        'description' => '9号楼 A区 4-6层'
    ],
    'FP001-9-3' => [
        'building' => 9,
        'area' => 'B',
        'floors' => [1, 2, 3],
        'description' => '9号楼 B区 1-3层'
    ],
    'FP001-9-4' => [
        'building' => 9,
        'area' => 'B',
        'floors' => [4, 5, 6],
        'description' => '9号楼 B区 4-6层'
    ],
    'FP001-9-5' => [
        'building' => 9,
        'area' => 'A',
        'floors' => [1, 2, 3],
        'description' => '9号楼 A区 1-3层'
    ],
    'FP001-9-6' => [
        'building' => 9,
        'area' => 'A',
        'floors' => [4, 5, 6],
        'description' => '9号楼 A区 4-6层'
    ],
    'FP001-10-1' => [
        'building' => 10,
        'area' => 'A',
        'floors' => [1, 2, 3],
        'description' => '10号楼 A区 1-3层'
    ],
    'FP001-10-2' => [
        'building' => 10,
        'area' => 'A',
        'floors' => [4, 5, 6],
        'description' => '10号楼 A区 4-6层'
    ],
    'FP001-6-1' => [
        'building' => 6,
        'area' => 'A',
        'floors' => [5, 6],
        'description' => '6号楼 A区 5-6层'
    ],
];

// 检查设备是否存在
if (!isset($deviceMapping[$deviceId])) {
    error_log("未知的设备ID: {$deviceId}");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '未知的设备ID: ' . $deviceId], JSON_UNESCAPED_UNICODE);
    exit;
}

$deviceInfo = $deviceMapping[$deviceId];
$building = $deviceInfo['building'];
$area = $deviceInfo['area'];
$floors = $deviceInfo['floors'];
$description = $deviceInfo['description'];

error_log("设备 {$deviceId} 查询未签到学生 | {$description} | 日期: {$date}");

try {
    $db = getDBConnection();
    
    // 构建楼层条件（IN查询）
    $floorPlaceholders = implode(',', array_fill(0, count($floors), '?'));
    
    // SQL查询：获取未签到且未请假的学生
    // ⭐ 修复：使用和其他API一致的逻辑，只查询check_records当天最新状态
    $sql = "
        SELECT 
            s.student_id,
            s.name,
            CONCAT(s.building_area, s.room_number, '-', s.bed_number) as location,
            s.building_floor as floor
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
            AND s.building_area = ?
            AND s.building_floor IN ({$floorPlaceholders})
            AND latest.status IS NULL
        ORDER BY s.building_floor, s.room_number, s.bed_number
        LIMIT 100
    ";
    
    $stmt = $db->prepare($sql);
    
    // 绑定参数
    $params = [
        $date,                  // 查询日期（子查询1）
        $date,                  // 查询日期（子查询2）
        $building,              // 楼栋号
        $area                   // 区域
    ];
    
    // 添加楼层参数
    foreach ($floors as $floor) {
        $params[] = $floor;
    }
    
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 记录日志
    $totalUnchecked = count($students);
    error_log("查询结果: {$description} 未签到 {$totalUnchecked} 人");
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'device_id' => $deviceId,
        'device_info' => $description,
        'date' => $date,
        'total_unchecked' => $totalUnchecked,
        'students' => $students
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("数据库查询失败: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库查询失败'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("系统错误: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系统错误'], JSON_UNESCAPED_UNICODE);
}
?>

