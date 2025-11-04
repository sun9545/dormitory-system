<?php
/**
 * 请假管理数据模型
 */
class Leave {
    private $db;
    
    public function __construct() {
        $this->db = getDBConnection();
    }
    
    /**
     * 添加请假记录
     * 
     * @param array $data 请假数据
     * @param int $userId 审批人ID
     * @return bool|array 成功返回新记录ID，失败返回false
     */
    public function addLeave($data, $userId) {
        try {
            // 验证数据
            if (empty($data['student_id']) || empty($data['start_date']) || empty($data['end_date'])) {
                return ['success' => false, 'message' => '学生ID、开始日期和结束日期不能为空'];
            }
            
            // 检查学生是否存在
            $checkStudent = $this->db->prepare("SELECT student_id FROM students WHERE student_id = :student_id");
            $checkStudent->execute(['student_id' => $data['student_id']]);
            
            if (!$checkStudent->fetch()) {
                return ['success' => false, 'message' => '学生不存在'];
            }
            
            // 验证日期
            $startDate = new DateTime($data['start_date']);
            $endDate = new DateTime($data['end_date']);
            
            if ($endDate < $startDate) {
                return ['success' => false, 'message' => '结束日期不能早于开始日期'];
            }
            
            // 检查日期冲突
            $checkConflict = $this->db->prepare("
                SELECT id FROM leave_records 
                WHERE student_id = :student_id 
                AND (
                    (start_date <= :end_date AND end_date >= :start_date) OR
                    (start_date <= :start_date AND end_date >= :start_date) OR
                    (start_date <= :end_date AND end_date >= :end_date)
                )
            ");
            $checkConflict->execute([
                'student_id' => $data['student_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date']
            ]);
            
            if ($checkConflict->fetch()) {
                return ['success' => false, 'message' => '该学生在所选时间段内已有请假记录'];
            }
            
            // 添加请假记录
            $stmt = $this->db->prepare("
                INSERT INTO leave_records (student_id, start_date, end_date, reason, approved_by)
                VALUES (:student_id, :start_date, :end_date, :reason, :approved_by)
            ");
            
            $result = $stmt->execute([
                'student_id' => $data['student_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'reason' => $data['reason'] ?? '',
                'approved_by' => $userId
            ]);
            
            if ($result) {
                $leaveId = $this->db->lastInsertId();
                
                // 同时更新学生状态为"请假"
                $checkRecord = new CheckRecord();
                $checkRecord->updateStudentStatus($data['student_id'], '请假', $userId);
                
                // 记录操作日志
                logOperation($userId, '添加请假', "为学生 {$data['student_id']} 添加请假记录，期间: {$data['start_date']} 至 {$data['end_date']}");
                
                return ['success' => true, 'id' => $leaveId];
            } else {
                return ['success' => false, 'message' => '添加请假记录失败'];
            }
            
        } catch (Exception $e) {
            error_log("添加请假记录失败: " . $e->getMessage());
            return ['success' => false, 'message' => '系统错误: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取请假记录列表
     * 
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array 请假记录列表和分页信息
     */
    public function getLeaveList($filters = [], $page = 1, $limit = 20) {
        try {
            $offset = ($page - 1) * $limit;
            $where = [];
            $params = [];
            
            // 构建查询条件
            if (!empty($filters['student_id'])) {
                $where[] = "l.student_id = :student_id";
                $params['student_id'] = $filters['student_id'];
            }
            
            if (!empty($filters['start_date'])) {
                $where[] = "l.start_date >= :start_date";
                $params['start_date'] = $filters['start_date'];
            }
            
            if (!empty($filters['end_date'])) {
                $where[] = "l.end_date <= :end_date";
                $params['end_date'] = $filters['end_date'];
            }
            
            if (!empty($filters['active'])) {
                $today = date('Y-m-d');
                $where[] = "l.end_date >= :today";
                $params['today'] = $today;
            }
            
            // 构建查询语句
            $query = "
                SELECT l.*, s.name as student_name, s.class_name, u.name as approver_name
                FROM leave_records l
                JOIN students s ON l.student_id = s.student_id
                JOIN users u ON l.approved_by = u.id
            ";
            
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            
            $query .= " ORDER BY l.start_date DESC";
            
            // 获取总记录数
            $countQuery = "SELECT COUNT(*) FROM leave_records l";
            if (!empty($where)) {
                $countQuery .= " WHERE " . implode(" AND ", $where);
            }
            
            $countStmt = $this->db->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue(':' . $key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();
            
            // 添加分页
            $query .= " LIMIT :limit OFFSET :offset";
            $params['limit'] = $limit;
            $params['offset'] = $offset;
            
            // 执行查询
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                if ($key === 'limit' || $key === 'offset') {
                    $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':' . $key, $value);
                }
            }
            $stmt->execute();
            $records = $stmt->fetchAll();
            
            return [
                'records' => $records,
                'pagination' => [
                    'total' => $total,
                    'perPage' => $limit,
                    'currentPage' => $page,
                    'totalPages' => ceil($total / $limit)
                ]
            ];
            
        } catch (Exception $e) {
            error_log("获取请假记录失败: " . $e->getMessage());
            return [
                'records' => [],
                'pagination' => [
                    'total' => 0,
                    'perPage' => $limit,
                    'currentPage' => $page,
                    'totalPages' => 0
                ],
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取请假记录详情
     * 
     * @param int $id 请假记录ID
     * @return array|false 请假记录详情
     */
    public function getLeaveById($id) {
        try {
            $query = "
                SELECT l.*, s.name as student_name, s.class_name, u.name as approver_name
                FROM leave_records l
                JOIN students s ON l.student_id = s.student_id
                JOIN users u ON l.approved_by = u.id
                WHERE l.id = :id
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute(['id' => $id]);
            
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("获取请假记录详情失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取学生当前有效的请假记录
     * 
     * @param string $studentId 学生ID
     * @return array|false 请假记录详情
     */
    public function getActiveLeaveByStudent($studentId) {
        try {
            $today = date('Y-m-d');
            
            $query = "
                SELECT l.*, s.name as student_name, s.class_name, u.name as approver_name
                FROM leave_records l
                JOIN students s ON l.student_id = s.student_id
                JOIN users u ON l.approved_by = u.id
                WHERE l.student_id = :student_id
                AND l.start_date <= :today AND l.end_date >= :today
                ORDER BY l.created_at DESC
                LIMIT 1
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                'student_id' => $studentId,
                'today' => $today
            ]);
            
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("获取学生请假记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 取消请假记录
     * 
     * @param int $id 请假记录ID
     * @param int $userId 操作用户ID
     * @return bool 是否取消成功
     */
    public function cancelLeave($id, $userId) {
        try {
            // 获取请假记录
            $leave = $this->getLeaveById($id);
            if (!$leave) {
                return ['success' => false, 'message' => '请假记录不存在'];
            }
            
            // 删除请假记录
            $stmt = $this->db->prepare("DELETE FROM leave_records WHERE id = :id");
            $result = $stmt->execute(['id' => $id]);
            
            if ($result) {
                // 更新学生状态为"在寝"
                $checkRecord = new CheckRecord();
                $checkRecord->updateStudentStatus($leave['student_id'], '在寝', $userId);
                
                // 记录操作日志
                logOperation($userId, '取消请假', "取消学生 {$leave['student_id']} 的请假记录，期间: {$leave['start_date']} 至 {$leave['end_date']}");
                
                return ['success' => true];
            } else {
                return ['success' => false, 'message' => '取消请假记录失败'];
            }
        } catch (Exception $e) {
            error_log("取消请假记录失败: " . $e->getMessage());
            return ['success' => false, 'message' => '系统错误: ' . $e->getMessage()];
        }
    }
    
    /**
     * 批量导入请假记录
     * 
     * @param array $leaveData 请假数据数组
     * @param int $userId 操作用户ID
     * @return array 导入结果
     */
    public function importLeave($leaveData, $userId) {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        if (empty($leaveData)) {
            $result['errors'][] = '无有效数据导入';
            return $result;
        }
        
        $this->db->beginTransaction();
        
        try {
            foreach ($leaveData as $index => $data) {
                $addResult = $this->addLeave($data, $userId);
                
                if ($addResult['success']) {
                    $result['success']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "第" . ($index + 1) . "行: " . ($addResult['message'] ?? '未知错误');
                }
            }
            
            $this->db->commit();
            
            // 记录批量操作日志
            logOperation($userId, '批量导入请假', "成功导入 {$result['success']} 条请假记录");
            
        } catch (Exception $e) {
            $this->db->rollBack();
            
            $result['success'] = 0;
            $result['failed'] = count($leaveData);
            $result['errors'][] = "批量导入失败: " . $e->getMessage();
            
            error_log("批量导入请假记录失败: " . $e->getMessage());
        }
        
        return $result;
    }
}
?> 