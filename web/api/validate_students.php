<?php
// 学号验证接口
require_once '../config/config.php';
require_once '../utils/auth.php';
require_once '../models/student.php';

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

// 获取学号列表
$studentIds = isset($_POST['student_ids']) ? $_POST['student_ids'] : [];

if (empty($studentIds) || !is_array($studentIds)) {
    echo json_encode(['success' => false, 'message' => '学号列表不能为空']);
    exit;
}

// 限制批量验证数量
if (count($studentIds) > 100) {
    echo json_encode(['success' => false, 'message' => '一次最多验证100个学号']);
    exit;
}

try {
    $db = getDBConnection();
    $student = new Student();
    
    $results = [];
    
    foreach ($studentIds as $studentId) {
        $studentId = trim($studentId);
        if (empty($studentId)) {
            continue;
        }
        
        // 查询学生信息
        $stmt = $db->prepare("SELECT student_id, name, class_name FROM students WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $studentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($studentInfo) {
            $results[$studentId] = [
                'exists' => true,
                'name' => $studentInfo['name'],
                'class' => $studentInfo['class_name'],
                'status' => 'valid'
            ];
        } else {
            $results[$studentId] = [
                'exists' => false,
                'name' => null,
                'class' => null,
                'status' => 'not_found'
            ];
        }
    }
    
    // 统计结果
    $validCount = count(array_filter($results, function($r) { return $r['exists']; }));
    $invalidCount = count($results) - $validCount;
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'summary' => [
            'total' => count($results),
            'valid' => $validCount,
            'invalid' => $invalidCount
        ]
    ]);
    
} catch (Exception $e) {
    error_log("学号验证错误: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '验证失败：' . $e->getMessage()]);
}