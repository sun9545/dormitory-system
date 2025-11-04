<?php
/**
 * 学生数据模型
 */
require_once __DIR__ . '/../utils/cache.php';

class Student {
    private $db;
    
    public function __construct() {
        $this->db = getDBConnection();
    }
    
    /**
     * 获取所有学生列表
     * 
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param array $filters 过滤条件
     * @return array 学生列表和分页信息
     */
    public function getAllStudents($page = 1, $limit = PAGINATION_LIMIT, $filters = []) {
        $offset = ($page - 1) * $limit;
        
        // 构建基本查询
        $query = "SELECT * FROM students";
        $countQuery = "SELECT COUNT(*) FROM students";
        $params = [];
        
        // 添加过滤条件
        if (!empty($filters)) {
            $whereClause = [];
            
            if (isset($filters['search']) && !empty($filters['search'])) {
                $search = '%' . $filters['search'] . '%';
                $whereClause[] = "(student_id LIKE :search_student_id OR name LIKE :search_name OR class_name LIKE :search_class)";
                $params['search_student_id'] = $search;
                $params['search_name'] = $search;
                $params['search_class'] = $search;
            }
            
            if (isset($filters['building']) && !empty($filters['building'])) {
                $whereClause[] = "building = :building";
                $params['building'] = $filters['building'];
            }
            
            if (isset($filters['building_area']) && !empty($filters['building_area'])) {
                $whereClause[] = "building_area = :building_area";
                $params['building_area'] = $filters['building_area'];
            }
            
            if (isset($filters['building_floor']) && !empty($filters['building_floor'])) {
                $whereClause[] = "building_floor = :building_floor";
                $params['building_floor'] = $filters['building_floor'];
            }
            
            if (isset($filters['class_name']) && !empty($filters['class_name'])) {
                $whereClause[] = "class_name = :class_name";
                $params['class_name'] = $filters['class_name'];
            }
            
            if (isset($filters['counselor']) && !empty($filters['counselor'])) {
                $whereClause[] = "counselor = :counselor";
                $params['counselor'] = $filters['counselor'];
            }
            
            if (!empty($whereClause)) {
                $query .= " WHERE " . implode(' AND ', $whereClause);
                $countQuery .= " WHERE " . implode(' AND ', $whereClause);
            }
        }
        
        // 添加排序和分页
        $query .= " ORDER BY building, building_area, building_floor, room_number, bed_number LIMIT :limit OFFSET :offset";
        
        // 准备和执行查询
        $stmt = $this->db->prepare($query);
        
        // 绑定参数
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $students = $stmt->fetchAll();
        
        // 获取总记录数
        $countStmt = $this->db->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $totalCount = $countStmt->fetchColumn();
        
        // 计算总页数
        $totalPages = ceil($totalCount / $limit);
        
        return [
            'students' => $students,
            'pagination' => [
                'total' => $totalCount,
                'perPage' => $limit,
                'currentPage' => $page,
                'totalPages' => $totalPages
            ]
        ];
    }
    
    /**
     * 根据ID获取学生信息
     * 
     * @param string $studentId 学生ID
     * @return array|false 学生信息
     */
    public function getStudentById($studentId) {
        $stmt = $this->db->prepare("SELECT * FROM students WHERE student_id = :student_id");
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetch();
    }
    
    /**
     * 添加学生
     * 
     * @param array $data 学生数据
     * @return bool 是否添加成功
     */
    public function addStudent($data) {
        // 数据验证
        $validationResult = $this->validateStudentData($data);
        if (!$validationResult['valid']) {
            error_log("学生数据验证失败: " . $validationResult['message']);
            return false;
        }
        
        try {
            // 检查学生ID是否已存在
            $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM students WHERE student_id = :student_id");
            $checkStmt->execute(['student_id' => $data['student_id']]);
            $exists = $checkStmt->fetchColumn();
            
            if ($exists > 0) {
                error_log("添加学生失败: 学号 {$data['student_id']} 已存在");
                return false;
            }
            
            $stmt = $this->db->prepare("INSERT INTO students 
                (student_id, name, gender, class_name, building, building_area, building_floor, 
                room_number, bed_number, counselor, counselor_phone) 
                VALUES 
                (:student_id, :name, :gender, :class_name, :building, :building_area, :building_floor,
                :room_number, :bed_number, :counselor, :counselor_phone)");
            
            // 记录SQL参数
            error_log("添加学生SQL参数: " . json_encode([
                'student_id' => $data['student_id'],
                'name' => $data['name'],
                'gender' => $data['gender'],
                'class_name' => $data['class_name'],
                'building' => $data['building'],
                'building_area' => $data['building_area'],
                'building_floor' => $data['building_floor'],
                'room_number' => $data['room_number'],
                'bed_number' => $data['bed_number'],
                'counselor' => $data['counselor'],
                'counselor_phone' => $data['counselor_phone']
            ]));
            
            $result = $stmt->execute([
                'student_id' => $data['student_id'],
                'name' => $data['name'],
                'gender' => $data['gender'],
                'class_name' => $data['class_name'],
                'building' => $data['building'],
                'building_area' => $data['building_area'],
                'building_floor' => $data['building_floor'],
                'room_number' => $data['room_number'],
                'bed_number' => $data['bed_number'],
                'counselor' => $data['counselor'],
                'counselor_phone' => $data['counselor_phone']
            ]);
            
            if ($result) {
                error_log("添加学生成功: 学号 {$data['student_id']}, 姓名 {$data['name']}");
                
                // 清除相关缓存 - 使用强力清理
                if (function_exists('clearStudentRelatedCache')) {
                    clearStudentRelatedCache($data['student_id']);
                } else if (function_exists('clearCache')) {
                    clearCache(CACHE_KEY_ALL_STUDENTS);
                    clearCache(CACHE_KEY_ALL_STATUS_DATE);
                    error_log("已清除学生相关缓存");
                }
            } else {
                error_log("添加学生失败: " . json_encode($stmt->errorInfo()));
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("添加学生异常: " . $e->getMessage() . ", 学号: {$data['student_id']}");
            return false;
        }
    }
    
    /**
     * 更新学生信息
     * 
     * @param array $data 学生数据
     * @return bool 是否更新成功
     */
    public function updateStudent($data) {
        try {
            $stmt = $this->db->prepare("UPDATE students SET 
                name = :name, gender = :gender, class_name = :class_name, 
                building = :building, building_area = :building_area, building_floor = :building_floor,
                room_number = :room_number, bed_number = :bed_number, 
                counselor = :counselor, counselor_phone = :counselor_phone 
                WHERE student_id = :student_id");
            
            $result = $stmt->execute([
                'student_id' => $data['student_id'],
                'name' => $data['name'],
                'gender' => $data['gender'],
                'class_name' => $data['class_name'],
                'building' => $data['building'],
                'building_area' => $data['building_area'],
                'building_floor' => $data['building_floor'],
                'room_number' => $data['room_number'],
                'bed_number' => $data['bed_number'],
                'counselor' => $data['counselor'],
                'counselor_phone' => $data['counselor_phone']
            ]);
            
            // 检查SQL执行是否成功
            if ($result) {
                $rowCount = $stmt->rowCount();
                
                if ($rowCount > 0) {
                    error_log("更新学生成功: 学号 {$data['student_id']}，影响行数: {$rowCount}");
                    
                    // 清除相关缓存 - 使用强力清理
                    if (function_exists('clearStudentRelatedCache')) {
                        clearStudentRelatedCache($data['student_id']);
                    } else if (function_exists('clearCache')) {
                        clearCache(CACHE_KEY_ALL_STUDENTS);
                        clearCache(CACHE_KEY_ALL_STATUS_DATE);
                        error_log("已清除学生相关缓存");
                    }
                } else {
                    error_log("学生信息无变化: 学号 {$data['student_id']}");
                }
                
                // 无论是否有变化，SQL执行成功就返回true
                return true;
            } else {
                error_log("更新学生SQL执行失败: 学号 {$data['student_id']}");
                return false;
            }
        } catch (PDOException $e) {
            error_log("更新学生失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除学生
     * 
     * @param string $studentId 学生ID
     * @return bool 是否删除成功
     */
    public function deleteStudent($studentId) {
        try {
            // 开始事务
            $this->db->beginTransaction();
            
            // 1. 删除请假申请记录（有RESTRICT外键约束）
            $leaveAppStmt = $this->db->prepare("DELETE FROM leave_applications WHERE student_id = :student_id");
            $leaveAppResult = $leaveAppStmt->execute(['student_id' => $studentId]);
            
            if (!$leaveAppResult) {
                error_log("删除学生关联的请假申请失败: " . implode(', ', $leaveAppStmt->errorInfo()));
                $this->db->rollBack();
                return false;
            }
            
            $deletedLeaveApps = $leaveAppStmt->rowCount();
            if ($deletedLeaveApps > 0) {
                error_log("已删除 {$deletedLeaveApps} 条请假申请，学生ID: {$studentId}");
            }
            
            // 2. 删除查寝记录（有RESTRICT外键约束）
            $checkStmt = $this->db->prepare("DELETE FROM check_records WHERE student_id = :student_id");
            $checkResult = $checkStmt->execute(['student_id' => $studentId]);
            
            if (!$checkResult) {
                error_log("删除学生关联的查寝记录失败: " . implode(', ', $checkStmt->errorInfo()));
                $this->db->rollBack();
                return false;
            }
            
            $deletedRecords = $checkStmt->rowCount();
            if ($deletedRecords > 0) {
                error_log("已删除 {$deletedRecords} 条查寝记录，学生ID: {$studentId}");
            }
            
            // 3. 删除学生（leave_records会自动级联删除）
            $studentStmt = $this->db->prepare("DELETE FROM students WHERE student_id = :student_id");
            $studentResult = $studentStmt->execute(['student_id' => $studentId]);
            
            if (!$studentResult) {
                error_log("删除学生失败: " . implode(', ', $studentStmt->errorInfo()));
                $this->db->rollBack();
                return false;
            }
            
            // 提交事务
            $this->db->commit();
            error_log("删除学生成功: 学号 {$studentId}，共删除 {$deletedLeaveApps} 条请假申请、{$deletedRecords} 条查寝记录");
            
            // 清除相关缓存 - 使用强力清理
            if (function_exists('clearStudentRelatedCache')) {
                clearStudentRelatedCache($studentId);
            } else if (function_exists('clearCache')) {
                clearCache(CACHE_KEY_ALL_STUDENTS);
                clearCache(CACHE_KEY_ALL_STATUS_DATE);
                error_log("已清除学生相关缓存");
            }
            
            return true;
        } catch (PDOException $e) {
            // 回滚事务
            $this->db->rollBack();
            error_log("删除学生异常: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 批量导入学生
     * 
     * @param array $students 学生数据数组
     * @return array 导入结果
     */
    public function importStudents($students) {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        // 确保日志目录存在
        $logDir = 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $logFile = $logDir . '/student_import.log';
        
        // 添加调试信息
        $debugLog = @fopen($logFile, 'a');
        if ($debugLog === false) {
            error_log("无法打开日志文件: " . $logFile);
            // 即使没有日志，也继续执行导入
        } else {
            fwrite($debugLog, "开始导入学生: " . date('Y-m-d H:i:s') . "\n");
            fwrite($debugLog, "学生数量: " . count($students) . "\n");
        }
        
        $this->db->beginTransaction();
        
        try {
            foreach ($students as $index => $student) {
                if ($debugLog !== false) {
                    fwrite($debugLog, "处理第" . ($index + 1) . "个学生: {$student['student_id']} - {$student['name']}\n");
                }
                
                if ($this->getStudentById($student['student_id'])) {
                    // 如果学生已存在，则更新
                    if ($debugLog !== false) {
                        fwrite($debugLog, "学生 {$student['student_id']} 已存在，执行更新\n");
                    }
                    if ($this->updateStudent($student)) {
                        $result['success']++;
                        if ($debugLog !== false) {
                            fwrite($debugLog, "更新成功\n");
                        }
                    } else {
                        $result['failed']++;
                        $result['errors'][] = "更新学生 {$student['student_id']} 失败";
                        if ($debugLog !== false) {
                            fwrite($debugLog, "更新失败\n");
                        }
                    }
                } else {
                    // 如果学生不存在，则添加
                    if ($debugLog !== false) {
                        fwrite($debugLog, "学生 {$student['student_id']} 不存在，执行添加\n");
                    }
                    if ($this->addStudent($student)) {
                        $result['success']++;
                        if ($debugLog !== false) {
                            fwrite($debugLog, "添加成功\n");
                        }
                    } else {
                        $result['failed']++;
                        $result['errors'][] = "添加学生 {$student['student_id']} 失败";
                        if ($debugLog !== false) {
                            fwrite($debugLog, "添加失败\n");
                        }
                    }
                }
            }
            
            $this->db->commit();
            
            // 清除相关缓存 - 批量导入后强力清理
            if (function_exists('clearAllRelatedCache')) {
                clearAllRelatedCache();
                error_log("批量导入后已强力清除所有相关缓存");
            } else if (function_exists('clearCache')) {
                clearCache(CACHE_KEY_ALL_STUDENTS);
                clearCache(CACHE_KEY_ALL_STATUS_DATE);
                error_log("批量导入后已清除学生相关缓存");
            }
            
            return [
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $result['failed'] = count($students);
            $result['errors'][] = "批量导入失败: " . $e->getMessage();
            if ($debugLog !== false) {
                fwrite($debugLog, "异常: " . $e->getMessage() . "\n");
                fwrite($debugLog, "事务回滚\n");
            }
        }
        
        if ($debugLog !== false) {
            fwrite($debugLog, "导入结果: 成功=" . $result['success'] . ", 失败=" . $result['failed'] . "\n");
            if (!empty($result['errors'])) {
                fwrite($debugLog, "错误信息: " . implode(', ', $result['errors']) . "\n");
            }
            fwrite($debugLog, "导入结束: " . date('Y-m-d H:i:s') . "\n\n");
            fclose($debugLog);
        }
        
        return $result;
    }
    
    /**
     * 获取楼栋统计信息
     * 
     * @return array 各楼栋学生统计
     */
    public function getBuildingStats() {
        $query = "SELECT building, COUNT(*) as total FROM students GROUP BY building ORDER BY building";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * 获取指定楼栋的楼层区域统计
     * 
     * @param int $building 楼栋号
     * @return array 楼层区域统计
     */
    public function getBuildingFloorStats($building) {
        $query = "SELECT building_area, building_floor, COUNT(*) as total 
                 FROM students 
                 WHERE building = :building 
                 GROUP BY building_area, building_floor 
                 ORDER BY building_area, building_floor";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute(['building' => $building]);
        return $stmt->fetchAll();
    }
    
    /**
     * 获取所有存在的楼栋、区域、楼层组合
     * 
     * @return array 楼栋结构数组
     */
    public function getExistingBuildingStructure() {
        $query = "SELECT DISTINCT building, building_area, building_floor, COUNT(*) as student_count
                 FROM students 
                 GROUP BY building, building_area, building_floor 
                 ORDER BY building, building_area, building_floor";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        // 组织数据结构
        $structure = [];
        foreach ($results as $row) {
            $building = $row['building'];
            $area = $row['building_area'];
            $floor = $row['building_floor'];
            
            if (!isset($structure[$building])) {
                $structure[$building] = [];
            }
            if (!isset($structure[$building][$area])) {
                $structure[$building][$area] = [];
            }
            
            $structure[$building][$area][$floor] = $row['student_count'];
        }
        
        return $structure;
    }
    
    /**
     * 获取所有班级列表
     * 
     * @return array 班级列表
     */
    public function getAllClasses() {
        $query = "SELECT DISTINCT class_name FROM students ORDER BY class_name";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * 获取所有辅导员列表
     * 
     * @return array 辅导员列表
     */
    public function getAllCounselors() {
        $query = "SELECT counselor, MAX(counselor_phone) as counselor_phone FROM students 
                  WHERE counselor IS NOT NULL AND counselor != '' AND TRIM(counselor) != ''
                  GROUP BY counselor
                  ORDER BY counselor";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * 验证学生数据
     * 
     * @param array $data 学生数据
     * @return array 验证结果
     */
    private function validateStudentData($data) {
        // 检查必填字段
        $requiredFields = ['student_id', 'name', 'gender', 'class_name', 'building', 'building_area', 'building_floor', 'room_number', 'bed_number'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return ['valid' => false, 'message' => "字段 {$field} 不能为空"];
            }
        }
        
        // 验证学号格式
        if (!preg_match('/^\d+$/', $data['student_id'])) {
            return ['valid' => false, 'message' => '学号格式不正确，应为纯数字'];
        }
        
        // 验证姓名长度
        if (strlen($data['name']) < 2 || strlen($data['name']) > 20) {
            return ['valid' => false, 'message' => '姓名长度应在2-20个字符之间'];
        }
        
        // 验证性别
        if (!in_array($data['gender'], ['男', '女'])) {
            return ['valid' => false, 'message' => '性别只能是男或女'];
        }
        
        // 验证楼栋号
        if (!is_numeric($data['building']) || $data['building'] < 1 || $data['building'] > 10) {
            return ['valid' => false, 'message' => '楼栋号应为1-10之间的数字'];
        }
        
        // 验证区域
        if (!in_array($data['building_area'], ['A', 'B'])) {
            return ['valid' => false, 'message' => '区域只能是A或B'];
        }
        
        // 验证楼层
        if (!is_numeric($data['building_floor']) || $data['building_floor'] < 1 || $data['building_floor'] > 6) {
            return ['valid' => false, 'message' => '楼层应为1-6之间的数字'];
        }
        
        // 验证床号
        if (!is_numeric($data['bed_number']) || $data['bed_number'] < 1 || $data['bed_number'] > 8) {
            return ['valid' => false, 'message' => '床号应为1-8之间的数字'];
        }
        
        // 验证辅导员电话（如果提供）
        if (!empty($data['counselor_phone']) && !preg_match('/^1[3-9]\d{9}$/', $data['counselor_phone'])) {
            return ['valid' => false, 'message' => '辅导员电话格式不正确'];
        }
        
        return ['valid' => true, 'message' => '验证通过'];
    }
    
    /**
     * 删除所有学生
     * @return int 实际删除的记录数
     */
    public function deleteAllStudents() {
        $stmt = $this->db->prepare("DELETE FROM students");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * 批量导入学生（AJAX版本）
     * @param array $studentsData 学生数据数组
     * @return array 导入结果
     */
    public function batchImportStudents($studentsData) {
        $result = [
            'total' => count($studentsData),
            'success' => 0,
            'fail' => 0,
            'errors' => []
        ];
        
        $this->db->beginTransaction();
        
        try {
            foreach ($studentsData as $index => $studentData) {
                try {
                    // 验证必填字段
                    if (empty($studentData['student_id']) || empty($studentData['name'])) {
                        $result['fail']++;
                        $result['errors'][] = "第{$studentData['row_number']}行：学号和姓名不能为空";
                        continue;
                    }
                    
                    // 验证性别
                    if (!in_array($studentData['gender'], ['男', '女'])) {
                        $result['fail']++;
                        $result['errors'][] = "第{$studentData['row_number']}行：性别必须是'男'或'女'";
                        continue;
                    }
                    
                    // 验证床号
                    if (!empty($studentData['bed_number']) && (!is_numeric($studentData['bed_number']) || $studentData['bed_number'] < 1 || $studentData['bed_number'] > 8)) {
                        $result['fail']++;
                        $result['errors'][] = "第{$studentData['row_number']}行：床号应为1-8之间的数字";
                        continue;
                    }
                    
                    // 检查学生是否已存在
                    $existingStudent = $this->getStudentById($studentData['student_id']);
                    
                    if ($existingStudent) {
                        // 更新现有学生
                        if ($this->updateStudent($studentData)) {
                            $result['success']++;
                        } else {
                            $result['fail']++;
                            $result['errors'][] = "第{$studentData['row_number']}行：更新学生 {$studentData['student_id']} 失败";
                        }
                    } else {
                        // 添加新学生
                        if ($this->addStudent($studentData)) {
                            $result['success']++;
                        } else {
                            $result['fail']++;
                            $result['errors'][] = "第{$studentData['row_number']}行：添加学生 {$studentData['student_id']} 失败";
                        }
                    }
                    
                } catch (Exception $e) {
                    $result['fail']++;
                    $result['errors'][] = "第{$studentData['row_number']}行：处理失败 - " . $e->getMessage();
                }
            }
            
            $this->db->commit();
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        
        return $result;
    }
}
?> 