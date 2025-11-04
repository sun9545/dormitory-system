<?php
/**
 * 请假申请模型类
 */

class LeaveApplication {
    private $db;
    
    public function __construct() {
        // 获取数据库连接
        global $pdo;
        if ($pdo === null) {
            // 如果global $pdo不存在，则重新创建连接
            require_once __DIR__ . '/../config/database.php';
            $this->db = getDBConnection();
        } else {
            $this->db = $pdo;
        }
    }
    
    /**
     * 验证学号和姓名是否匹配
     * @param string $studentId 学号
     * @param string $name 姓名
     * @return array
     */
    public function verifyStudent($studentId, $name) {
        try {
            $stmt = $this->db->prepare("
                SELECT student_id, name, class_name, counselor 
                FROM students 
                WHERE student_id = ? AND name = ?
            ");
            $stmt->execute([$studentId, $name]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                return [
                    'success' => true,
                    'message' => '验证成功',
                    'data' => $student
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '学号或姓名不匹配，请检查后重试'
                ];
            }
        } catch (Exception $e) {
            error_log("验证学生信息失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '系统错误，请稍后重试'
            ];
        }
    }
    
    /**
     * 检查防刷限制
     * @param string $studentId 学号
     * @return array
     */
    public function checkRateLimit($studentId) {
        try {
            $stmt = $this->db->prepare("
                SELECT last_submit_time, submit_count 
                FROM leave_applications 
                WHERE student_id = ? 
                ORDER BY last_submit_time DESC 
                LIMIT 1
            ");
            $stmt->execute([$studentId]);
            $lastRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastRecord && $lastRecord['last_submit_time']) {
                $lastTime = strtotime($lastRecord['last_submit_time']);
                $now = time();
                $timeDiff = $now - $lastTime;
                
                // 1小时内
                if ($timeDiff < 3600) {
                    if ($lastRecord['submit_count'] >= 5) {
                        return [
                            'allowed' => false,
                            'message' => '提交过于频繁，请1小时后再试'
                        ];
                    }
                }
            }
            
            return ['allowed' => true];
        } catch (Exception $e) {
            error_log("检查防刷限制失败: " . $e->getMessage());
            return ['allowed' => true]; // 出错时允许提交
        }
    }
    
    /**
     * 提交请假申请
     * @param string $studentId 学号
     * @param array $leaveDates 请假日期数组
     * @param string $reason 请假原因
     * @param string $applyMethod 申请方式
     * @return array
     */
    public function submitApplication($studentId, $leaveDates, $reason, $applyMethod = 'student') {
        try {
            // 检查防刷限制
            $rateLimitCheck = $this->checkRateLimit($studentId);
            if (!$rateLimitCheck['allowed']) {
                return [
                    'success' => false,
                    'message' => $rateLimitCheck['message']
                ];
            }
            
            // 验证日期格式并排序
            $validDates = [];
            foreach ($leaveDates as $date) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $validDates[] = $date;
                }
            }
            
            if (empty($validDates)) {
                return [
                    'success' => false,
                    'message' => '请选择有效的请假日期'
                ];
            }
            
            sort($validDates); // 排序日期
            $leaveDatesJson = json_encode($validDates, JSON_UNESCAPED_UNICODE);
            
            // 计算提交次数
            $stmt = $this->db->prepare("
                SELECT submit_count 
                FROM leave_applications 
                WHERE student_id = ? 
                AND last_submit_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY last_submit_time DESC 
                LIMIT 1
            ");
            $stmt->execute([$studentId]);
            $lastRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            $submitCount = $lastRecord ? ($lastRecord['submit_count'] + 1) : 1;
            
            // 插入申请
            $stmt = $this->db->prepare("
                INSERT INTO leave_applications (
                    student_id, leave_dates, reason, status, 
                    apply_method, last_submit_time, submit_count
                ) VALUES (?, ?, ?, 'pending', ?, NOW(), ?)
            ");
            
            $result = $stmt->execute([
                $studentId, 
                $leaveDatesJson, 
                $reason, 
                $applyMethod, 
                $submitCount
            ]);
            
            if ($result) {
                return [
                    'success' => true,
                    'message' => '请假申请提交成功，请等待辅导员审批',
                    'application_id' => $this->db->lastInsertId()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '提交失败，请稍后重试'
                ];
            }
        } catch (Exception $e) {
            error_log("提交请假申请失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '系统错误: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取学生的申请列表
     * @param string $studentId 学号
     * @param string $status 状态筛选
     * @return array
     */
    public function getApplicationsByStudent($studentId, $status = null) {
        try {
            $sql = "
                SELECT la.*, s.name, s.class_name, u.username as counselor_name
                FROM leave_applications la
                LEFT JOIN students s ON la.student_id = s.student_id
                LEFT JOIN users u ON la.counselor_id = u.id
                WHERE la.student_id = ?
            ";
            
            $params = [$studentId];
            
            if ($status) {
                $sql .= " AND la.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY la.apply_time DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析JSON日期
            foreach ($applications as &$app) {
                $app['leave_dates'] = json_decode($app['leave_dates'], true);
                $app['leave_days'] = count($app['leave_dates']);
            }
            
            return [
                'success' => true,
                'data' => $applications
            ];
        } catch (Exception $e) {
            error_log("获取学生申请列表失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '获取申请列表失败'
            ];
        }
    }
    
    /**
     * 获取辅导员负责学生的申请列表
     * @param string $counselorName 辅导员姓名
     * @param string $status 状态筛选
     * @return array
     */
    public function getApplicationsByCounselor($counselorUsername, $status = null) {
        try {
            // 首先获取辅导员的真实姓名
            $userStmt = $this->db->prepare("SELECT name FROM users WHERE username = ? AND role = 'counselor'");
            $userStmt->execute([$counselorUsername]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => '辅导员不存在'
                ];
            }
            
            $counselorName = $user['name'];
            
            // 使用辅导员真实姓名查询
            $sql = "
                SELECT la.*, s.name as student_name, s.class_name, s.counselor,
                       CONCAT(s.building, '号楼', s.building_area, '区', s.building_floor, '层', s.room_number, '-', s.bed_number, '床') as dormitory,
                       u.name as reviewer
                FROM leave_applications la
                INNER JOIN students s ON la.student_id = s.student_id
                LEFT JOIN users u ON la.counselor_id = u.id
                WHERE s.counselor = ?
            ";
            
            $params = [$counselorName];
            
            if ($status && $status !== 'all') {
                $sql .= " AND la.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY la.apply_time DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析JSON日期
            foreach ($applications as &$app) {
                $app['leave_dates'] = json_decode($app['leave_dates'], true);
                $app['leave_days'] = count($app['leave_dates']);
            }
            
            return [
                'success' => true,
                'data' => $applications
            ];
        } catch (Exception $e) {
            error_log("获取辅导员申请列表失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '获取申请列表失败'
            ];
        }
    }
    
    /**
     * 获取所有申请列表（管理员）
     * @param string $status 状态筛选
     * @return array
     */
    public function getApplicationsByAdmin($status = null) {
        return $this->getAllApplications($status);
    }
    
    /**
     * 获取所有申请列表（管理员）- 内部实现
     * @param string $status 状态筛选
     * @return array
     */
    public function getAllApplications($status = null) {
        try {
            $sql = "
                SELECT la.*, s.name as student_name, s.class_name, s.counselor,
                       CONCAT(s.building, '号楼', s.building_area, '区', s.building_floor, '层', s.room_number, '-', s.bed_number, '床') as dormitory,
                       u.name as reviewer
                FROM leave_applications la
                INNER JOIN students s ON la.student_id = s.student_id
                LEFT JOIN users u ON la.counselor_id = u.id
            ";
            
            $params = [];
            
            if ($status && $status !== 'all') {
                $sql .= " WHERE la.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY la.apply_time DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析JSON日期
            foreach ($applications as &$app) {
                $app['leave_dates'] = json_decode($app['leave_dates'], true);
                $app['leave_days'] = count($app['leave_dates']);
            }
            
            return [
                'success' => true,
                'data' => $applications
            ];
        } catch (Exception $e) {
            error_log("获取所有申请列表失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '获取申请列表失败'
            ];
        }
    }
    
    /**
     * 审批通过申请
     * @param int $applicationId 申请ID
     * @param string $counselorUsername 审批人用户名
     * @return array
     */
    public function approveApplication($applicationId, $counselorUsername) {
        try {
            $this->db->beginTransaction();
            
            // 获取审批人ID
            $userStmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
            $userStmt->execute([$counselorUsername]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '审批人不存在'
                ];
            }
            
            $counselorId = $user['id'];
            
            // 获取申请信息
            $stmt = $this->db->prepare("
                SELECT student_id, leave_dates, status 
                FROM leave_applications 
                WHERE id = ?
            ");
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$application) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '申请不存在'
                ];
            }
            
            if ($application['status'] !== 'pending') {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '该申请已被处理'
                ];
            }
            
            // 更新申请状态
            $stmt = $this->db->prepare("
                UPDATE leave_applications 
                SET status = 'approved', 
                    counselor_id = ?, 
                    review_time = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$counselorId, $applicationId]);
            
            // 生成check_records记录
            $leaveDates = json_decode($application['leave_dates'], true);
            $stmt = $this->db->prepare("
                INSERT INTO check_records (student_id, check_time, status, device_id) 
                VALUES (?, ?, '请假', NULL)
            ");
            
            foreach ($leaveDates as $date) {
                $checkTime = $date . ' 00:00:00';
                $stmt->execute([$application['student_id'], $checkTime]);
            }
            
            $this->db->commit();
            
            // 清除缓存
            if (function_exists('clearAllRelatedCache')) {
                clearAllRelatedCache();
            }
            
            return [
                'success' => true,
                'message' => '审批通过，已自动生成请假记录'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("审批通过失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '审批失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 拒绝申请
     * @param int $applicationId 申请ID
     * @param string $counselorUsername 审批人用户名
     * @return array
     */
    public function rejectApplication($applicationId, $counselorUsername) {
        try {
            // 获取审批人ID
            $userStmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
            $userStmt->execute([$counselorUsername]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => '审批人不存在'
                ];
            }
            
            $counselorId = $user['id'];
            
            // 获取申请信息
            $stmt = $this->db->prepare("
                SELECT status FROM leave_applications WHERE id = ?
            ");
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$application) {
                return [
                    'success' => false,
                    'message' => '申请不存在'
                ];
            }
            
            if ($application['status'] !== 'pending') {
                return [
                    'success' => false,
                    'message' => '该申请已被处理'
                ];
            }
            
            // 更新申请状态
            $stmt = $this->db->prepare("
                UPDATE leave_applications 
                SET status = 'rejected', 
                    counselor_id = ?, 
                    review_time = NOW() 
                WHERE id = ?
            ");
            $result = $stmt->execute([$counselorId, $applicationId]);
            
            if ($result) {
                return [
                    'success' => true,
                    'message' => '已拒绝该申请'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '操作失败'
                ];
            }
        } catch (Exception $e) {
            error_log("拒绝申请失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '操作失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 撤销申请（学生自己撤销待审批的申请）
     * @param int $applicationId 申请ID
     * @param string $studentId 学号
     * @return array
     */
    public function cancelApplication($applicationId, $studentId) {
        try {
            // 验证申请归属和状态
            $stmt = $this->db->prepare("
                SELECT status, student_id 
                FROM leave_applications 
                WHERE id = ?
            ");
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$application) {
                return [
                    'success' => false,
                    'message' => '申请不存在'
                ];
            }
            
            if ($application['student_id'] !== $studentId) {
                return [
                    'success' => false,
                    'message' => '无权操作此申请'
                ];
            }
            
            if ($application['status'] !== 'pending') {
                return [
                    'success' => false,
                    'message' => '只能撤销待审批的申请'
                ];
            }
            
            // 删除申请
            $stmt = $this->db->prepare("DELETE FROM leave_applications WHERE id = ?");
            $result = $stmt->execute([$applicationId]);
            
            if ($result) {
                return [
                    'success' => true,
                    'message' => '申请已撤销'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '撤销失败'
                ];
            }
        } catch (Exception $e) {
            error_log("撤销申请失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '操作失败: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 取消已批准的请假（辅导员操作，删除check_records记录）
     * @param int $applicationId 申请ID
     * @return array
     */
    public function revokeApprovedLeave($applicationId) {
        try {
            $this->db->beginTransaction();
            
            // 获取申请信息
            $stmt = $this->db->prepare("
                SELECT student_id, leave_dates, status 
                FROM leave_applications 
                WHERE id = ?
            ");
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$application) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '申请不存在'
                ];
            }
            
            if ($application['status'] !== 'approved') {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '只能取消已批准的请假'
                ];
            }
            
            // 删除check_records中的请假记录
            $leaveDates = json_decode($application['leave_dates'], true);
            $stmt = $this->db->prepare("
                DELETE FROM check_records 
                WHERE student_id = ? 
                AND DATE(check_time) = ? 
                AND status = '请假'
                AND device_id IS NULL
            ");
            
            foreach ($leaveDates as $date) {
                $stmt->execute([$application['student_id'], $date]);
            }
            
            // 删除申请记录
            $stmt = $this->db->prepare("DELETE FROM leave_applications WHERE id = ?");
            $stmt->execute([$applicationId]);
            
            $this->db->commit();
            
            // 清除缓存
            if (function_exists('clearAllRelatedCache')) {
                clearAllRelatedCache();
            }
            
            return [
                'success' => true,
                'message' => '请假已取消'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("取消请假失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '取消失败: ' . $e->getMessage()
            ];
        }
    }
}
?>

