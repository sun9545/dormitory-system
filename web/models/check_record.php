<?php
/**
 * 查寝记录数据模型
 */
require_once __DIR__ . '/../utils/cache.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/auth.php';

class CheckRecord {
    private $db;
    private $cache;
    
    public function __construct() {
        $this->db = getDBConnection();
        // 初始化缓存
        $this->cache = CACHE_ENABLED ? getCacheInstance() : null;
    }
    
    /**
     * 获取学生的最新状态
     * 
     * @param string $studentId 学生ID
     * @return array|null 学生最新状态
     */
    public function getStudentLatestStatus($studentId) {
        // 尝试从缓存获取
        $cacheKey = CACHE_KEY_STUDENT_STATUS;
        $params = ['student_id' => $studentId, 'type' => 'latest'];
        
        if ($this->cache && CACHE_ENABLED) {
            $result = $this->cache->get($cacheKey, $params);
            if ($result !== null) {
                return $result;
            }
        }
        
        // 缓存未命中，从数据库查询
        $query = "SELECT c.*, s.name FROM check_records c
                  JOIN students s ON c.student_id = s.student_id
                  WHERE c.student_id = :student_id
                  ORDER BY c.check_time DESC
                  LIMIT 1";
                  
        $stmt = $this->db->prepare($query);
        $stmt->execute(['student_id' => $studentId]);
        $result = $stmt->fetch();
        
        // 缓存查询结果
        if ($this->cache && CACHE_ENABLED && $result) {
            $this->cache->set($cacheKey, $result, $params, CACHE_EXPIRE_CHECK_RECORDS);
        }
        
        return $result;
    }
    
    /**
     * 获取学生当天最新状态
     * 
     * @param string $studentId 学生ID
     * @param string $date 日期(YYYY-MM-DD)，默认今天
     * @return array|null 学生当天最新状态
     */
    public function getStudentTodayStatus($studentId, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        // 尝试从缓存获取
        $cacheKey = CACHE_KEY_STUDENT_STATUS;
        $params = ['student_id' => $studentId, 'date' => $date, 'type' => 'today'];
        
        if ($this->cache && CACHE_ENABLED) {
            $result = $this->cache->get($cacheKey, $params);
            if ($result !== null) {
                return $result;
            }
        }
        
        // 缓存未命中，从数据库查询
        $query = "SELECT c.*, s.name FROM check_records c
                  JOIN students s ON c.student_id = s.student_id
                  WHERE c.student_id = :student_id
                  AND DATE(c.check_time) = :date
                  ORDER BY c.check_time DESC
                  LIMIT 1";
                  
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            'student_id' => $studentId,
            'date' => $date
        ]);
        
        $result = $stmt->fetch();
        
        // 缓存查询结果
        if ($this->cache && CACHE_ENABLED) {
            $this->cache->set($cacheKey, $result, $params, CACHE_EXPIRE_CHECK_RECORDS);
        }
        
        return $result;
    }
    
    /**
     * 获取学生的请假状态
     * 
     * @param string $studentId 学生ID
     * @return array|null 学生请假状态
     */
    public function getStudentLeaveStatus($studentId) {
        // 尝试从缓存获取
        $cacheKey = CACHE_KEY_STUDENT_STATUS;
        $params = ['student_id' => $studentId, 'type' => 'leave'];
        
        if ($this->cache && CACHE_ENABLED) {
            $result = $this->cache->get($cacheKey, $params);
            if ($result !== null) {
                return $result;
            }
        }
        
        // 缓存未命中，从数据库查询
        $query = "SELECT c.* FROM check_records c
                  WHERE c.student_id = :student_id
                  AND c.status = '请假'
                  ORDER BY c.check_time DESC
                  LIMIT 1";
                  
        $stmt = $this->db->prepare($query);
        $stmt->execute(['student_id' => $studentId]);
        
        $result = $stmt->fetch();
        
        // 缓存查询结果
        if ($this->cache && CACHE_ENABLED) {
            $this->cache->set($cacheKey, $result, $params, CACHE_EXPIRE_CHECK_RECORDS);
        }
        
        return $result;
    }
    
    /**
     * 获取所有学生的最新状态
     * 
     * @param array $filters 过滤条件
     * @return array 所有学生的最新状态
     */
    public function getAllStudentsLatestStatus($filters = []) {
        // 尝试从缓存获取
        $cacheKey = CACHE_KEY_ALL_STUDENTS;
        
        if ($this->cache && CACHE_ENABLED) {
            $result = $this->cache->get($cacheKey, $filters);
            if ($result !== null) {
                return $result;
            }
        }
        
        // 缓存未命中，从数据库查询
        // 基本查询
        $query = "SELECT s.*, 
                  IFNULL(cr.status, '离寝') AS status,
                  IFNULL(cr.check_time, '') AS check_time,
                  IFNULL(cr.device_id, '') AS device_id
                  FROM students s
                  LEFT JOIN (
                      SELECT c1.student_id, c1.status, c1.check_time, c1.device_id
                      FROM check_records c1
                      INNER JOIN (
                          SELECT student_id, MAX(check_time) as max_time
                          FROM check_records
                          GROUP BY student_id
                      ) c2 ON c1.student_id = c2.student_id AND c1.check_time = c2.max_time
                  ) cr ON s.student_id = cr.student_id";
                  
        $params = [];
        $whereConditions = [];
        
        // 添加筛选条件
        if (isset($filters['building']) && $filters['building'] > 0) {
            $whereConditions[] = "s.building = :building";
            $params['building'] = $filters['building'];
        }
        
        if (isset($filters['building_area']) && !empty($filters['building_area'])) {
            $whereConditions[] = "s.building_area = :building_area";
            $params['building_area'] = $filters['building_area'];
        }
        
        if (isset($filters['building_floor']) && $filters['building_floor'] > 0) {
            $whereConditions[] = "s.building_floor = :building_floor";
            $params['building_floor'] = $filters['building_floor'];
        }
        
        if (isset($filters['class_name']) && !empty($filters['class_name'])) {
            $whereConditions[] = "s.class_name = :class_name";
            $params['class_name'] = $filters['class_name'];
        }
        
        if (isset($filters['status']) && !empty($filters['status'])) {
            $whereConditions[] = "IFNULL(cr.status, '离寝') = :status";
            $params['status'] = $filters['status'];
        }
        
        if (isset($filters['search']) && !empty($filters['search'])) {
            $whereConditions[] = "(s.student_id LIKE :search_student_id OR s.name LIKE :search_name)";
            $params['search_student_id'] = '%' . $filters['search'] . '%';
            $params['search_name'] = '%' . $filters['search'] . '%';
        }
        
        // 添加WHERE子句
        if (!empty($whereConditions)) {
            $query .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        // 添加排序和限制
        $query .= " ORDER BY s.building, s.building_area, s.building_floor, s.room_number, s.bed_number";
        
        // 添加限制条件
        if (isset($filters['limit']) && $filters['limit'] > 0) {
            $query .= " LIMIT :limit";
            $params['limit'] = $filters['limit'];
        }
        
        $stmt = $this->db->prepare($query);
        
        // 绑定参数
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        
        $stmt->execute();
        $result = $stmt->fetchAll();
        
        // 缓存查询结果
        if ($this->cache && CACHE_ENABLED) {
            // 限制记录的缓存时间较短
            $expireTime = isset($filters['limit']) ? CACHE_EXPIRE_SHORT : CACHE_EXPIRE_MEDIUM;
            $this->cache->set($cacheKey, $result, $filters, $expireTime);
        }
        
        return $result;
    }
    
    /**
     * 获取学生的查寝记录历史
     * 
     * @param string $studentId 学生ID
     * @param int $limit 限制数量
     * @return array 查寝记录历史
     */
    public function getStudentHistory($studentId, $limit = 20) {
        try {
            $query = "SELECT * FROM check_records 
                      WHERE student_id = :student_id 
                      ORDER BY check_time DESC 
                      LIMIT :limit";
                      
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':student_id', $studentId);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("获取学生历史记录失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取楼栋查寝统计
     * 
     * @param string $date 日期(YYYY-MM-DD)，默认今天
     * @return array 楼栋查寝统计
     */
    public function getBuildingStatusStatistics($date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        // 尝试从缓存获取
        $cacheKey = CACHE_KEY_BUILDING_STATS;
        $params = ['date' => $date];
        
        if ($this->cache && CACHE_ENABLED) {
            $result = $this->cache->get($cacheKey, $params);
            if ($result !== null) {
                return $result;
            }
        }
        
        // 缓存未命中，从数据库查询 - 优化版本
        $query = "SELECT 
                  s.building,
                  COUNT(s.student_id) AS total,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '在寝' THEN 1 ELSE 0 END) AS present,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '离寝' THEN 1 ELSE 0 END) AS absent,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '请假' THEN 1 ELSE 0 END) AS `leave`,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '未签到' THEN 1 ELSE 0 END) AS not_checked
                  FROM students s
                  LEFT JOIN (
                      SELECT c1.student_id, c1.status
                      FROM check_records c1
                      INNER JOIN (
                          SELECT student_id, MAX(check_time) as max_time
                          FROM check_records
                          WHERE DATE(check_time) = :date
                          GROUP BY student_id
                      ) c2 ON c1.student_id = c2.student_id AND c1.check_time = c2.max_time
                      WHERE DATE(c1.check_time) = :date
                  ) cr ON s.student_id = cr.student_id
                  GROUP BY s.building
                  ORDER BY s.building";
                  
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute(['date' => $date]);
            
            $result = $stmt->fetchAll();
            
            // 缓存查询结果
            if ($this->cache && CACHE_ENABLED) {
                $this->cache->set($cacheKey, $result, $params, CACHE_EXPIRE_STATISTICS);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("获取楼栋统计失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取指定楼栋的楼层区域查寝统计
     * 
     * @param int $building 楼栋号
     * @param string $date 日期(YYYY-MM-DD)，默认今天
     * @return array 楼层区域查寝统计
     */
    public function getBuildingFloorStatusStatistics($building, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        // 尝试从缓存获取
        $cacheKey = CACHE_KEY_FLOOR_STATS;
        $params = ['building' => $building, 'date' => $date];
        
        if ($this->cache && CACHE_ENABLED) {
            $result = $this->cache->get($cacheKey, $params);
            if ($result !== null) {
                return $result;
            }
        }
        
        // 缓存未命中，从数据库查询 - 优化版本
        $query = "SELECT 
                  s.building_area,
                  s.building_floor,
                  COUNT(s.student_id) AS total,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '在寝' THEN 1 ELSE 0 END) AS present,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '离寝' THEN 1 ELSE 0 END) AS absent,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '请假' THEN 1 ELSE 0 END) AS `leave`,
                  SUM(CASE WHEN IFNULL(cr.status, '未签到') = '未签到' THEN 1 ELSE 0 END) AS not_checked
                  FROM students s
                  LEFT JOIN (
                      SELECT c1.student_id, c1.status
                      FROM check_records c1
                      INNER JOIN (
                          SELECT student_id, MAX(check_time) as max_time
                          FROM check_records
                          WHERE DATE(check_time) = :date
                          GROUP BY student_id
                      ) c2 ON c1.student_id = c2.student_id AND c1.check_time = c2.max_time
                      WHERE DATE(c1.check_time) = :date
                  ) cr ON s.student_id = cr.student_id
                  WHERE s.building = :building
                  GROUP BY s.building_area, s.building_floor
                  ORDER BY s.building_area, s.building_floor";
                  
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                'building' => $building,
                'date' => $date
            ]);
            
            $result = $stmt->fetchAll();
            
            // 缓存查询结果
            if ($this->cache && CACHE_ENABLED) {
                $this->cache->set($cacheKey, $result, $params, CACHE_EXPIRE_STATISTICS);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("获取楼层统计失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取所有学生在指定日期的查寝状态（未签到显示为'未签到'）
     * @param string $date 日期(YYYY-MM-DD)
     * @param array $filters 过滤条件
     * @return array
     */
    public function getAllStudentsStatusByDate($date, $filters = []) {
        // 尝试从缓存获取
        $cacheKey = CACHE_KEY_ALL_STATUS_DATE;
        $params = array_merge(['date' => $date], $filters);
        
        if ($this->cache && CACHE_ENABLED) {
            $result = $this->cache->get($cacheKey, $params);
            if ($result !== null) {
                return $result;
            }
        }
        
        // 缓存未命中，从数据库查询 - 优化版本，避免N+1查询
        $query = "SELECT s.*, 
                  IFNULL(cr.status, '未签到') AS status,
                  IFNULL(cr.check_time, '') AS check_time,
                  IFNULL(cr.device_id, '') AS device_id
                  FROM students s
                  LEFT JOIN (
                      SELECT c1.student_id, c1.status, c1.check_time, c1.device_id
                      FROM check_records c1
                      INNER JOIN (
                          SELECT student_id, 
                                 MAX(CONCAT(check_time, '-', LPAD(id, 10, '0'))) as max_combined
                          FROM check_records
                          WHERE DATE(check_time) = :date1
                          GROUP BY student_id
                      ) c2 ON c1.student_id = c2.student_id 
                          AND CONCAT(c1.check_time, '-', LPAD(c1.id, 10, '0')) = c2.max_combined
                      WHERE DATE(c1.check_time) = :date2
                  ) cr ON s.student_id = cr.student_id";
        $queryParams = ['date1' => $date, 'date2' => $date];
        $whereConditions = [];
        
        if (isset($filters['building']) && $filters['building'] > 0) {
            $whereConditions[] = "s.building = :building";
            $queryParams['building'] = $filters['building'];
        }
        
        if (isset($filters['building_area']) && !empty($filters['building_area'])) {
            $whereConditions[] = "s.building_area = :building_area";
            $queryParams['building_area'] = $filters['building_area'];
        }
        
        if (isset($filters['building_floor']) && $filters['building_floor'] > 0) {
            $whereConditions[] = "s.building_floor = :building_floor";
            $queryParams['building_floor'] = $filters['building_floor'];
        }
        
        if (isset($filters['class_name']) && !empty($filters['class_name'])) {
            $whereConditions[] = "s.class_name = :class_name";
            $queryParams['class_name'] = $filters['class_name'];
        }
        
        if (isset($filters['status']) && !empty($filters['status'])) {
            $whereConditions[] = "IFNULL(cr.status, '未签到') = :status";
            $queryParams['status'] = $filters['status'];
        }
        
        if (isset($filters['search']) && !empty($filters['search'])) {
            $whereConditions[] = "(s.student_id LIKE :search_student_id OR s.name LIKE :search_name)";
            $queryParams['search_student_id'] = '%' . $filters['search'] . '%';
            $queryParams['search_name'] = '%' . $filters['search'] . '%';
        }
        
        if (isset($filters['student_id']) && !empty($filters['student_id'])) {
            $whereConditions[] = "s.student_id = :student_id";
            $queryParams['student_id'] = $filters['student_id'];
        }
        
        if (isset($filters['counselor']) && !empty($filters['counselor'])) {
            $whereConditions[] = "s.counselor = :counselor";
            $queryParams['counselor'] = $filters['counselor'];
        }
        
        if (!empty($whereConditions)) {
            $query .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        $query .= " ORDER BY s.building, s.building_area, s.building_floor, s.room_number, s.bed_number";
        
        try {
            $stmt = $this->db->prepare($query);
            foreach ($queryParams as $key => $value) {
                if (strpos($query, ':' . $key) !== false) {
                    if (is_int($value)) {
                        $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
                    } else {
                        $stmt->bindValue(':' . $key, $value);
                    }
                }
            }
            $stmt->execute();
            $result = $stmt->fetchAll();
            
            // 缓存查询结果
            if ($this->cache && CACHE_ENABLED) {
                $this->cache->set($cacheKey, $result, $params, CACHE_EXPIRE_STATISTICS);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("获取指定日期学生状态失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 更新学生状态时清除相关缓存
     * 
     * @param string $studentId 学生ID
     */
    private function clearStudentStatusCache($studentId) {
        if (!$this->cache || !CACHE_ENABLED) {
            return;
        }
        
        // 清除学生相关的缓存
        $this->cache->delete(CACHE_KEY_STUDENT_STATUS, ['student_id' => $studentId, 'type' => 'latest']);
        $this->cache->delete(CACHE_KEY_STUDENT_STATUS, ['student_id' => $studentId, 'type' => 'today']);
        $this->cache->delete(CACHE_KEY_STUDENT_STATUS, ['student_id' => $studentId, 'type' => 'leave']);
        
        // 清除统计数据缓存
        $this->cache->delete(CACHE_KEY_ALL_STUDENTS);
        $this->cache->delete(CACHE_KEY_BUILDING_STATS);
        $this->cache->delete(CACHE_KEY_FLOOR_STATS);
        $this->cache->delete(CACHE_KEY_ALL_STATUS_DATE);
    }
    
    /**
     * 更新学生状态
     * 
     * @param string $studentId 学生ID
     * @param string $status 状态
     * @param int $userId 操作用户ID
     * @param string $deviceId 设备ID
     * @return bool 更新是否成功
     */
    public function updateStudentStatus($studentId, $status, $userId, $deviceId = null) {
        try {
            // 开始事务
            $this->db->beginTransaction();
            
            // 检查学生是否存在
            $stmt = $this->db->prepare("SELECT student_id FROM students WHERE student_id = :student_id");
            $stmt->execute(['student_id' => $studentId]);
            
            if (!$stmt->fetch()) {
                $this->db->rollBack();
                error_log("更新状态失败: 学生 {$studentId} 不存在");
                return false;
            }
            
            $query = "INSERT INTO check_records (student_id, check_time, status, device_id) 
                     VALUES (:student_id, NOW(), :status, :device_id)";
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                'student_id' => $studentId,
                'status' => $status,
                'device_id' => $deviceId
            ]);
            
            // 添加详细的执行日志
            error_log("updateStudentStatus执行SQL: $query");
            error_log("updateStudentStatus参数: " . json_encode(['student_id' => $studentId, 'status' => $status, 'device_id' => $deviceId]));
            error_log("updateStudentStatus执行结果: " . ($result ? 'true' : 'false'));
            
            if (!$result) {
                error_log("updateStudentStatus SQL错误: " . json_encode($stmt->errorInfo()));
            }
            
            if ($result) {
                logOperation($userId, '更新状态', "更新学生 {$studentId} 的状态为 {$status}" . ($deviceId ? " (设备: {$deviceId})" : ""));
                // 记录日志
                logSystemMessage("学生 {$studentId} 状态更新为 {$status}" . ($deviceId ? " (设备: {$deviceId})" : ""), 'info');
                
                // 提交事务
                $this->db->commit();
                
                // 清除缓存 - 使用强力清理
                if (function_exists('clearStudentRelatedCache')) {
                    clearStudentRelatedCache($studentId);
                    error_log("更新学生状态后已强力清除学生 {$studentId} 的所有相关缓存");
                } else {
                    $this->clearStudentStatusCache($studentId);
                    
                    // 清除所有相关缓存
                    if (function_exists('clearCache')) {
                        clearCache(CACHE_KEY_ALL_STUDENTS);
                        clearCache(CACHE_KEY_ALL_STATUS_DATE);
                        error_log("更新学生状态后已清除所有相关缓存");
                    }
                }
                
                return true;
            } else {
                $this->db->rollBack();
                error_log("更新学生 {$studentId} 状态失败");
                return false;
            }
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("更新状态数据库错误: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 批量设置学生为请假状态
     * 
     * @param array $studentIds 学生ID数组
     * @param int $userId 操作用户ID
     * @param string $startDate 开始日期(可选)
     * @param string $endDate 结束日期(可选)
     * @return array 成功和失败的记录数
     */
    public function batchSetLeaveStatus($studentIds, $userId, $startDate = null, $endDate = null) {
        $success = 0;
        $failed = 0;
        
        if (empty($studentIds)) {
            return ['success' => $success, 'failed' => $failed];
        }
        
        try {
            $this->db->beginTransaction();
            
            foreach ($studentIds as $studentId) {
                $query = "INSERT INTO check_records (student_id, check_time, status) 
                         VALUES (:student_id, NOW(), '请假')";
                $stmt = $this->db->prepare($query);
                $result = $stmt->execute(['student_id' => $studentId]);
                
                if ($result) {
                    $success++;
                    // 记录操作
                    $leaveInfo = "设置学生 {$studentId} 为请假状态";
                    if ($startDate && $endDate) {
                        $leaveInfo .= " (请假期间: {$startDate} 至 {$endDate})";
                    }
                    logOperation($userId, '批量请假', $leaveInfo);
                    logSystemMessage($leaveInfo, 'info');
                    
                    // 清除相关缓存
                    $this->clearStudentStatusCache($studentId);
                } else {
                    $failed++;
                    error_log("设置学生 {$studentId} 为请假状态失败");
                }
            }
            
            // 提交事务
            $this->db->commit();
            
            // 清除所有相关缓存 - 批量操作后强力清理
            if (function_exists('clearAllRelatedCache')) {
                clearAllRelatedCache();
                error_log("批量设置请假状态后已强力清除所有相关缓存");
            } else if (function_exists('clearCache')) {
                clearCache(CACHE_KEY_ALL_STUDENTS);
                clearCache(CACHE_KEY_ALL_STATUS_DATE);
                error_log("批量设置请假状态后已清除所有相关缓存");
            }
            
            return [
                'success' => $success,
                'failed' => $failed,
                'errors' => []
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("批量设置请假状态失败: " . $e->getMessage());
            return [
                'success' => $success, 
                'failed' => count($studentIds) - $success,
                'errors' => [$e->getMessage()]
            ];
        }
    }
    
    /**
     * 取消学生请假状态
     * 删除请假记录，让学生回到默认的"离寝"状态
     * 
     * @param string $studentId 学生ID
     * @param string $userId 操作用户ID
     * @param string $date 要取消的日期 (格式: Y-m-d)
     * @return bool 是否成功
     */
    public function cancelLeaveStatus($studentId, $userId, $date = null) {
        try {
            // 开始事务
            $this->db->beginTransaction();
            
            // 检查学生是否存在
            $stmt = $this->db->prepare("SELECT student_id FROM students WHERE student_id = :student_id");
            $stmt->execute(['student_id' => $studentId]);
            
            if (!$stmt->fetch()) {
                $this->db->rollBack();
                error_log("取消请假失败: 学生 {$studentId} 不存在");
                return false;
            }
            
            // 删除该学生指定日期的请假记录
            if ($date) {
                // 删除指定日期的请假记录
                $query = "DELETE FROM check_records 
                         WHERE student_id = :student_id 
                         AND status = '请假'
                         AND DATE(check_time) = :date";
                
                $stmt = $this->db->prepare($query);
                $result = $stmt->execute([
                    'student_id' => $studentId,
                    'date' => $date
                ]);
            } else {
                // 如果没有指定日期，删除所有请假记录（兼容旧逻辑）
                $query = "DELETE FROM check_records 
                         WHERE student_id = :student_id 
                         AND status = '请假'";
                
                $stmt = $this->db->prepare($query);
                $result = $stmt->execute([
                    'student_id' => $studentId
                ]);
            }
            
            error_log("cancelLeaveStatus执行SQL: $query");
            error_log("cancelLeaveStatus参数: " . json_encode(['student_id' => $studentId]));
            error_log("cancelLeaveStatus执行结果: " . ($result ? 'true' : 'false'));
            error_log("cancelLeaveStatus影响行数: " . $stmt->rowCount());
            
            if ($result && $stmt->rowCount() > 0) {
                logOperation($userId, '取消请假', "取消学生 {$studentId} 的请假状态");
                logSystemMessage("学生 {$studentId} 请假已取消，状态已恢复", 'info');
                
                // 提交事务
                $this->db->commit();
                
                // 清除缓存
                if (function_exists('clearStudentRelatedCache')) {
                    clearStudentRelatedCache($studentId);
                    error_log("取消请假后已强力清除学生 {$studentId} 的所有相关缓存");
                } else {
                    $this->clearStudentStatusCache($studentId);
                    
                    if (function_exists('clearCache')) {
                        clearCache(CACHE_KEY_ALL_STUDENTS);
                        clearCache(CACHE_KEY_ALL_STATUS_DATE);
                        error_log("取消请假后已清除所有相关缓存");
                    }
                }
                
                return true;
            } else {
                $this->db->rollBack();
                error_log("取消学生 {$studentId} 请假失败: 没有找到可取消的请假记录");
                return false;
            }
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("取消学生 {$studentId} 请假时发生异常: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查设备是否已注册
     * 
     * @param string $deviceId 设备ID
     * @return bool 设备是否已注册
     */
    public function isDeviceRegistered($deviceId) {
        // 这里应该有一个设备表，但暂时简单实现
        // 实际项目中应有专门的设备管理表和注册逻辑
        return true;
    }
    
    /**
     * 获取今日指定设备的签到次数
     * 
     * @param string $deviceId 设备ID
     * @param string $date 日期 (Y-m-d格式)
     * @return int 签到次数
     */
    public function getTodayCheckinCountByDevice($deviceId, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        try {
            $query = "SELECT COUNT(*) as count FROM check_records 
                     WHERE device_id = :device_id 
                     AND DATE(check_time) = :date
                     AND status = '在寝'";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                'device_id' => $deviceId,
                'date' => $date
            ]);
            
            $result = $stmt->fetch();
            return (int)($result['count'] ?? 0);
            
        } catch (PDOException $e) {
            error_log("获取设备 {$deviceId} 今日签到次数失败: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 获取指定楼栋的详细信息（按楼层分组）
     * 
     * @param string $buildingName 楼栋名称
     * @param string $date 日期 (Y-m-d格式)
     * @return array 楼栋详细信息
     */
    public function getBuildingDetailByDate($buildingName, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        try {
            // 修复字段名称，使用正确的数据库字段
            $query = "SELECT 
                        s.student_id,
                        s.name,
                        s.building,
                        s.building_floor as floor,
                        s.room_number as room,
                        '未签到' as status,
                        NULL as check_time
                      FROM students s
                      WHERE s.building = :building_name
                      ORDER BY s.building_floor ASC, s.room_number ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                'building_name' => $buildingName
            ]);
            
            $results = $stmt->fetchAll();
            
            error_log("获取楼栋 {$buildingName} 详细信息成功，共 " . count($results) . " 条记录");
            return $results;
            
        } catch (PDOException $e) {
            error_log("获取楼栋 {$buildingName} 详细信息失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取指定日期的请假历史（包括已回寝的学生）
     * 
     * @param string $date 日期(YYYY-MM-DD)
     * @param array $filters 过滤条件
     * @return array 请假历史记录
     */
    public function getLeaveHistoryByDate($date, $filters = []) {
        try {
            // 查询当天有过请假记录的学生，并获取他们的最新状态
            $query = "
                SELECT 
                    s.*,
                    leave_info.first_leave_time as leave_time,
                    IFNULL(latest.status, '未签到') as current_status,
                    latest.check_time as latest_check_time,
                    CASE 
                        WHEN IFNULL(latest.status, '未签到') = '请假' THEN 0
                        ELSE 1
                    END as is_returned
                FROM students s
                INNER JOIN (
                    SELECT student_id, MIN(check_time) as first_leave_time
                    FROM check_records
                    WHERE DATE(check_time) = :date1
                    AND status = '请假'
                    GROUP BY student_id
                ) leave_info ON s.student_id = leave_info.student_id
                LEFT JOIN (
                    SELECT c1.student_id, c1.status, c1.check_time
                    FROM check_records c1
                    INNER JOIN (
                        SELECT student_id, 
                               MAX(CONCAT(check_time, '-', LPAD(id, 10, '0'))) as max_combined
                        FROM check_records
                        WHERE DATE(check_time) = :date2
                        GROUP BY student_id
                    ) c2 ON c1.student_id = c2.student_id 
                        AND CONCAT(c1.check_time, '-', LPAD(c1.id, 10, '0')) = c2.max_combined
                ) latest ON s.student_id = latest.student_id
            ";
            
            $queryParams = ['date1' => $date, 'date2' => $date];
            $whereConditions = [];
            
            // 添加筛选条件
            if (isset($filters['building']) && $filters['building'] > 0) {
                $whereConditions[] = "s.building = :building";
                $queryParams['building'] = $filters['building'];
            }
            
            if (isset($filters['building_area']) && !empty($filters['building_area'])) {
                $whereConditions[] = "s.building_area = :building_area";
                $queryParams['building_area'] = $filters['building_area'];
            }
            
            if (isset($filters['building_floor']) && $filters['building_floor'] > 0) {
                $whereConditions[] = "s.building_floor = :building_floor";
                $queryParams['building_floor'] = $filters['building_floor'];
            }
            
            if (isset($filters['class_name']) && !empty($filters['class_name'])) {
                $whereConditions[] = "s.class_name = :class_name";
                $queryParams['class_name'] = $filters['class_name'];
            }
            
            if (isset($filters['counselor']) && !empty($filters['counselor'])) {
                $whereConditions[] = "s.counselor = :counselor";
                $queryParams['counselor'] = $filters['counselor'];
            }
            
            if (isset($filters['search']) && !empty($filters['search'])) {
                $whereConditions[] = "(s.student_id LIKE :search_student_id OR s.name LIKE :search_name)";
                $queryParams['search_student_id'] = '%' . $filters['search'] . '%';
                $queryParams['search_name'] = '%' . $filters['search'] . '%';
            }
            
            if (!empty($whereConditions)) {
                $query .= " WHERE " . implode(" AND ", $whereConditions);
            }
            
            // 排序：先显示仍在请假的，再显示已回寝的，按请假时间倒序
            $query .= " ORDER BY is_returned ASC, leave_info.first_leave_time DESC";
            
            $stmt = $this->db->prepare($query);
            foreach ($queryParams as $key => $value) {
                if (is_int($value)) {
                    $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':' . $key, $value);
                }
            }
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("获取请假历史失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取指定楼栋区域的详细信息（按楼层分组）
     */
    public function getBuildingAreaDetailByDate($building, $buildingArea, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        try {
            // 查询指定楼栋和区域的学生信息，包含真实的签到状态
            $query = "SELECT 
                        s.student_id,
                        s.name,
                        s.building,
                        s.building_area,
                        CONCAT(s.building_area, s.building_floor) as floor,
                        s.building_floor,
                        s.room_number as room,
                        IFNULL(cr.status, '未签到') AS status,
                        IFNULL(cr.check_time, '') AS check_time,
                        IFNULL(cr.device_id, '') AS device_id
                      FROM students s
                      LEFT JOIN (
                          SELECT c1.student_id, c1.status, c1.check_time, c1.device_id
                          FROM check_records c1
                          WHERE DATE(c1.check_time) = :date1
                          AND c1.id = (
                              SELECT c2.id FROM check_records c2 
                              WHERE c2.student_id = c1.student_id AND DATE(c2.check_time) = :date2
                              ORDER BY c2.check_time DESC, c2.id DESC
                              LIMIT 1
                          )
                      ) cr ON s.student_id = cr.student_id
                      WHERE s.building = :building AND s.building_area = :building_area
                      ORDER BY s.building_floor ASC, s.room_number ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                'building' => $building,
                'building_area' => $buildingArea,
                'date1' => $date,
                'date2' => $date
            ]);
            
            $results = $stmt->fetchAll();
            
            // 统计各状态人数用于日志
            $statusCount = ['在寝' => 0, '离寝' => 0, '请假' => 0, '未签到' => 0];
            foreach ($results as $record) {
                $status = $record['status'] ?? '未签到';
                if (isset($statusCount[$status])) {
                    $statusCount[$status]++;
                }
            }
            
            error_log("获取楼栋 {$building}{$buildingArea} 详细信息成功，共 " . count($results) . " 条记录 - " .
                     "在寝:{$statusCount['在寝']}, 离寝:{$statusCount['离寝']}, 请假:{$statusCount['请假']}, 未签到:{$statusCount['未签到']}");
            return $results;
            
        } catch (PDOException $e) {
            error_log("获取楼栋 {$building}{$buildingArea} 详细信息失败: " . $e->getMessage());
            return [];
        }
    }
}
?> 