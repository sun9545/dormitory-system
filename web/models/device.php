<?php
require_once __DIR__ . '/../config/database.php';

class Device {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * 添加新设备
     * @param array $deviceData 设备数据
     * @return array 返回操作结果
     */
    public function addDevice($deviceData) {
        try {
            // 验证必需字段
            $required = ['device_name', 'building_number', 'device_sequence'];
            foreach ($required as $field) {
                if (empty($deviceData[$field])) {
                    throw new Exception("缺少必需字段: $field");
                }
            }
            
            // 生成设备编号
            $deviceId = $this->generateDeviceId($deviceData['building_number'], $deviceData['device_sequence']);
            
            // 检查设备编号是否已存在
            $stmt = $this->pdo->prepare("SELECT id FROM devices WHERE device_id = ?");
            $stmt->execute([$deviceId]);
            if ($stmt->fetch()) {
                throw new Exception("设备编号已存在");
            }
            
            // 检查同楼同序号是否已存在
            $stmt = $this->pdo->prepare("SELECT id FROM devices WHERE building_number = ? AND device_sequence = ?");
            $stmt->execute([$deviceData['building_number'], $deviceData['device_sequence']]);
            if ($stmt->fetch()) {
                throw new Exception("该楼栋该序号的设备已存在");
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO devices (device_id, device_name, building_number, device_sequence, location, status, max_fingerprints, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $deviceId,
                $deviceData['device_name'],
                $deviceData['building_number'],
                $deviceData['device_sequence'],
                $deviceData['location'] ?? null,
                $deviceData['status'] ?? 'active',
                $deviceData['max_fingerprints'] ?? 1000,
                $deviceData['ip_address'] ?? null
            ]);
            
            if ($result) {
                return [
                    'success' => true,
                    'device_id' => $deviceId,
                    'message' => '设备添加成功'
                ];
            } else {
                throw new Exception("设备添加失败");
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 生成设备编号
     * @param int $buildingNumber 楼号
     * @param int $deviceSequence 设备序号
     * @return string 设备编号
     */
    private function generateDeviceId($buildingNumber, $deviceSequence) {
        return "FP001-{$buildingNumber}-{$deviceSequence}";
    }
    
    /**
     * 获取所有设备
     * @param array $filters 过滤条件
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getAllDevices($filters = [], $limit = 50, $offset = 0) {
        try {
            $where = "WHERE 1=1";
            $params = [];
            
            if (!empty($filters['building_number'])) {
                $where .= " AND building_number = ?";
                $params[] = $filters['building_number'];
            }
            
            if (!empty($filters['status'])) {
                $where .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['search'])) {
                $where .= " AND (device_name LIKE ? OR device_id LIKE ? OR location LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $stmt = $this->pdo->prepare("
                SELECT *, 
                       ROUND((current_fingerprints / max_fingerprints) * 100, 2) as usage_percentage
                FROM devices 
                $where 
                ORDER BY building_number, device_sequence 
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取设备列表失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取设备总数
     * @param array $filters 过滤条件
     * @return int
     */
    public function getDeviceCount($filters = []) {
        try {
            $where = "WHERE 1=1";
            $params = [];
            
            if (!empty($filters['building_number'])) {
                $where .= " AND building_number = ?";
                $params[] = $filters['building_number'];
            }
            
            if (!empty($filters['status'])) {
                $where .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['search'])) {
                $where .= " AND (device_name LIKE ? OR device_id LIKE ? OR location LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM devices $where");
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("获取设备总数失败: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 根据设备ID获取设备信息
     * @param string $deviceId 设备编号
     * @return array|false
     */
    public function getDeviceById($deviceId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT *, 
                       ROUND((current_fingerprints / max_fingerprints) * 100, 2) as usage_percentage
                FROM devices 
                WHERE device_id = ?
            ");
            $stmt->execute([$deviceId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取设备信息失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新设备信息
     * @param string $deviceId 设备编号
     * @param array $updateData 更新数据
     * @return bool
     */
    public function updateDevice($deviceId, $updateData) {
        try {
            $allowedFields = ['device_name', 'location', 'status', 'max_fingerprints', 'ip_address'];
            $setFields = [];
            $params = [];
            
            foreach ($updateData as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $setFields[] = "$field = ?";
                    $params[] = $value;
                }
            }
            
            if (empty($setFields)) {
                return false;
            }
            
            $params[] = $deviceId;
            $sql = "UPDATE devices SET " . implode(', ', $setFields) . " WHERE device_id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
            
        } catch (Exception $e) {
            error_log("更新设备信息失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除设备
     * @param string $deviceId 设备编号
     * @return array 返回操作结果
     */
    public function deleteDevice($deviceId) {
        try {
            $this->pdo->beginTransaction();
            
            // 检查设备是否存在指纹映射
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM fingerprint_mapping WHERE device_id = ?");
            $stmt->execute([$deviceId]);
            $fingerprintCount = $stmt->fetchColumn();
            
            // 先删除相关的指纹映射记录
            if ($fingerprintCount > 0) {
                $stmt = $this->pdo->prepare("DELETE FROM fingerprint_mapping WHERE device_id = ?");
                $stmt->execute([$deviceId]);
            }
            
            // 删除相关的签到记录
            $stmt = $this->pdo->prepare("DELETE FROM fingerprint_checkin_logs WHERE device_id = ?");
            $stmt->execute([$deviceId]);
            
            // 删除设备
            $stmt = $this->pdo->prepare("DELETE FROM devices WHERE device_id = ?");
            $result = $stmt->execute([$deviceId]);
            
            if (!$result) {
                throw new Exception("删除设备失败");
            }
            
            $this->pdo->commit();
            return [
                'success' => true,
                'message' => '设备删除成功'
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取楼栋列表
     * @return array
     */
    public function getBuildingNumbers() {
        try {
            $stmt = $this->pdo->prepare("SELECT DISTINCT building_number FROM devices ORDER BY building_number");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("获取楼栋列表失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取指定楼栋的设备列表
     * @param int $buildingNumber 楼号
     * @return array
     */
    public function getDevicesByBuilding($buildingNumber) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT device_id, device_name, device_sequence, location, status,
                       current_fingerprints, max_fingerprints,
                       ROUND((current_fingerprints / max_fingerprints) * 100, 2) as usage_percentage
                FROM devices 
                WHERE building_number = ? 
                ORDER BY device_sequence
            ");
            $stmt->execute([$buildingNumber]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取楼栋设备失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 更新设备状态
     * @param string $deviceId 设备编号
     * @param string $status 状态: active, inactive, maintenance
     * @return bool
     */
    public function updateDeviceStatus($deviceId, $status) {
        try {
            $allowedStatus = ['active', 'inactive', 'maintenance'];
            if (!in_array($status, $allowedStatus)) {
                return false;
            }
            
            $stmt = $this->pdo->prepare("UPDATE devices SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE device_id = ?");
            return $stmt->execute([$status, $deviceId]);
        } catch (Exception $e) {
            error_log("更新设备状态失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取设备统计信息
     * @return array
     */
    public function getDeviceStats() {
        try {
            $stats = [];
            
            // 总设备数
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM devices");
            $stmt->execute();
            $stats['total_devices'] = $stmt->fetchColumn();
            
            // 按状态统计
            $stmt = $this->pdo->prepare("SELECT status, COUNT(*) as count FROM devices GROUP BY status");
            $stmt->execute();
            $statusStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['active_devices'] = $statusStats['active'] ?? 0;
            $stats['inactive_devices'] = $statusStats['inactive'] ?? 0;
            $stats['maintenance_devices'] = $statusStats['maintenance'] ?? 0;
            
            // 按楼栋统计
            $stmt = $this->pdo->prepare("SELECT building_number, COUNT(*) as count FROM devices GROUP BY building_number ORDER BY building_number");
            $stmt->execute();
            $stats['building_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 指纹容量统计
            $stmt = $this->pdo->prepare("
                SELECT 
                    SUM(max_fingerprints) as total_capacity,
                    SUM(current_fingerprints) as used_capacity,
                    ROUND(AVG((current_fingerprints / max_fingerprints) * 100), 2) as avg_usage
                FROM devices 
                WHERE status = 'active'
            ");
            $stmt->execute();
            $capacityStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_capacity'] = $capacityStats['total_capacity'] ?? 0;
            $stats['used_capacity'] = $capacityStats['used_capacity'] ?? 0;
            $stats['avg_usage'] = $capacityStats['avg_usage'] ?? 0;
            
            return $stats;
        } catch (Exception $e) {
            error_log("获取设备统计失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 检查设备是否在线
     * @param string $deviceId 设备编号
     * @return array 返回检查结果
     */
    public function checkDeviceOnline($deviceId) {
        try {
            $device = $this->getDeviceById($deviceId);
            if (!$device || empty($device['ip_address'])) {
                return [
                    'online' => false,
                    'message' => '设备不存在或未配置IP地址'
                ];
            }
            
            $ip = $device['ip_address'];
            
            // 验证IP地址格式
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return [
                    'online' => false,
                    'message' => '无效的IP地址格式: ' . htmlspecialchars($ip),
                    'ip_address' => $ip
                ];
            }
            
            // 使用socket连接检测设备在线状态（替代不安全的exec命令）
            $result = $this->checkDeviceConnection($ip);
            
            return [
                'online' => $result['online'],
                'message' => $result['message'],
                'ip_address' => $ip,
                'method' => 'socket',
                'details' => $result['details'] ?? null
            ];
        } catch (Exception $e) {
            return [
                'online' => false,
                'message' => '检测失败: ' . $e->getMessage(),
                'method' => 'socket'
            ];
        }
    }
    
    /**
     * 使用Socket检测设备连接状态
     * @param string $ip IP地址
     * @param int $port 端口号
     * @param int $timeout 超时时间（秒）
     * @return array
     */
    private function checkDeviceConnection($ip, $port = 80, $timeout = 3) {
        // 尝试多个常用端口来检测设备
        $ports = [80, 8080, 443, 23, 22];
        
        foreach ($ports as $testPort) {
            $startTime = microtime(true);
            $fp = @fsockopen($ip, $testPort, $errno, $errstr, $timeout);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($fp) {
                fclose($fp);
                return [
                    'online' => true,
                    'message' => "设备在线 (端口 {$testPort})",
                    'details' => [
                        'port' => $testPort,
                        'response_time' => $responseTime . 'ms',
                        'error_code' => 0
                    ]
                ];
            }
        }
        
        // 所有端口都无法连接
        return [
            'online' => false,
            'message' => "设备离线 (所有端口无响应)",
            'details' => [
                'tested_ports' => $ports,
                'last_error' => $errstr,
                'error_code' => $errno
            ]
        ];
    }
}
?>