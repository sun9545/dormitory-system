<?php
/**
 * 缓存工具类
 * 提供基于文件的缓存系统，减少数据库查询
 */

/**
 * 清除缓存
 * @param string $key 缓存键，如果为null则清除所有缓存
 * @param array $params 参数数组
 * @return bool 是否成功清除
 */
function clearCache($key = null, $params = []) {
    $cache = getCacheInstance();
    
    if ($key === null) {
        return $cache->flush();
    } else {
        return $cache->delete($key, $params);
    }
}

/**
 * 清除所有相关缓存 - 强力清理
 * 用于数据变更后确保所有相关缓存都被清除
 */
function clearAllRelatedCache() {
    $cache = getCacheInstance();
    
    // 清除所有主要缓存键的所有变体
    $mainKeys = [
        CACHE_KEY_ALL_STUDENTS,
        CACHE_KEY_ALL_STATUS_DATE,
        CACHE_KEY_STUDENT_STATUS,
        CACHE_KEY_BUILDING_STATS,
        CACHE_KEY_FLOOR_STATS,
        CACHE_KEY_STUDENT_HISTORY
    ];
    
    $success = true;
    
    foreach ($mainKeys as $key) {
        // 清除无参数版本
        if (!$cache->delete($key, [])) {
            $success = false;
        }
        
        // 清除可能的参数版本 - 通过删除匹配的文件
        $cacheKey = md5($key);
        $files = glob($cache->getCacheDir() . '/' . $cacheKey . '*.cache');
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
                error_log("无法删除缓存文件: " . $file);
            }
        }
    }
    
    // 清除所有以缓存键前缀开头的文件
    $prefixPattern = $cache->getCacheDir() . '/*.cache';
    $files = glob($prefixPattern);
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content && strpos($content, CACHE_KEY_PREFIX) !== false) {
            if (!unlink($file)) {
                $success = false;
                error_log("无法删除缓存文件: " . $file);
            }
        }
    }
    
    error_log("强力清理缓存: " . ($success ? "成功" : "部分失败"));
    return $success;
}

/**
 * 清除学生相关的所有缓存
 * @param string|null $studentId 特定学生ID，为null时清除所有学生缓存
 */
function clearStudentRelatedCache($studentId = null) {
    $cache = getCacheInstance();
    $success = true;
    
    // 强力清除所有相关缓存 - 直接删除所有匹配的缓存文件
    $cacheDir = $cache->getCacheDir();
    $today = date('Y-m-d');
    
    // 要清除的缓存键列表
    $cacheKeys = [
        CACHE_KEY_ALL_STUDENTS,
        CACHE_KEY_ALL_STATUS_DATE,
        CACHE_KEY_STUDENT_STATUS,
        CACHE_KEY_BUILDING_STATS,
        CACHE_KEY_FLOOR_STATS,
        CACHE_KEY_STUDENT_HISTORY
    ];
    
    // 对每个缓存键，删除所有可能的文件
    foreach ($cacheKeys as $key) {
        $keyHash = md5($key);
        $files = glob($cacheDir . '/' . $keyHash . '*.cache');
        
        foreach ($files as $file) {
            if (@unlink($file)) {
                error_log("删除缓存文件: " . basename($file));
            } else {
                $success = false;
                error_log("删除缓存文件失败: " . basename($file));
            }
        }
    }
    
    // 额外的强制清除：删除所有包含今天日期的缓存
    $allCacheFiles = glob($cacheDir . '/*.cache');
    foreach ($allCacheFiles as $file) {
        $content = @file_get_contents($file);
        if ($content && (strpos($content, $today) !== false || ($studentId && strpos($content, $studentId) !== false))) {
            if (@unlink($file)) {
                error_log("删除包含今日数据的缓存文件: " . basename($file));
            }
        }
    }
    
    error_log("强力清除学生相关缓存" . ($studentId ? " (学生ID: $studentId)" : "") . ": " . ($success ? "成功" : "部分失败"));
    return $success;
}

class Cache {
    // 缓存目录
    private $cacheDir;
    // 缓存过期时间（秒）
    private $expireTime;
    
    /**
     * 构造函数
     * @param string $cacheDir 缓存目录路径
     * @param int $expireTime 默认过期时间（秒）
     */
    public function __construct($cacheDir = null, $expireTime = 300) {
        $this->cacheDir = $cacheDir ?? ROOT_PATH . '/cache';
        $this->expireTime = $expireTime;
        
        // 确保缓存目录存在
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true)) {
                error_log("无法创建缓存目录: " . $this->cacheDir);
            }
        }
    }
    
    /**
     * 获取缓存目录路径
     * @return string 缓存目录路径
     */
    public function getCacheDir() {
        return $this->cacheDir;
    }
    
    /**
     * 生成缓存键
     * @param string $key 原始键
     * @param array $params 参数数组
     * @return string 缓存键
     */
    private function generateCacheKey($key, $params = []) {
        $paramsString = '';
        if (!empty($params)) {
            ksort($params); // 确保相同参数不同顺序产生相同的键
            $paramsString = md5(serialize($params));
        }
        return md5($key . $paramsString);
    }
    
    /**
     * 获取缓存文件路径
     * @param string $key 缓存键
     * @return string 缓存文件路径
     */
    private function getCacheFilePath($key) {
        return $this->cacheDir . '/' . $key . '.cache';
    }
    
    /**
     * 设置缓存
     * @param string $key 缓存键
     * @param mixed $data 要缓存的数据
     * @param array $params 参数数组（用于生成唯一键）
     * @param int $expireTime 过期时间（秒）
     * @return bool 是否成功设置缓存
     */
    public function set($key, $data, $params = [], $expireTime = null) {
        $cacheKey = $this->generateCacheKey($key, $params);
        $expireTime = $expireTime ?? $this->expireTime;
        $cacheData = [
            'data' => $data,
            'expire' => time() + $expireTime
        ];
        
        $filePath = $this->getCacheFilePath($cacheKey);
        return file_put_contents($filePath, serialize($cacheData)) !== false;
    }
    
    /**
     * 获取缓存
     * @param string $key 缓存键
     * @param array $params 参数数组（用于生成唯一键）
     * @return mixed 缓存的数据，如果不存在或已过期则返回null
     */
    public function get($key, $params = []) {
        $cacheKey = $this->generateCacheKey($key, $params);
        $filePath = $this->getCacheFilePath($cacheKey);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        $cacheData = unserialize(file_get_contents($filePath));
        
        // 检查是否过期
        if ($cacheData['expire'] < time()) {
            // 过期了，删除缓存文件
            @unlink($filePath);
            return null;
        }
        
        return $cacheData['data'];
    }
    
    /**
     * 检查缓存是否存在且有效
     * @param string $key 缓存键
     * @param array $params 参数数组
     * @return bool 缓存是否存在且有效
     */
    public function has($key, $params = []) {
        $cacheKey = $this->generateCacheKey($key, $params);
        $filePath = $this->getCacheFilePath($cacheKey);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        $cacheData = unserialize(file_get_contents($filePath));
        return $cacheData['expire'] >= time();
    }
    
    /**
     * 删除缓存
     * @param string $key 缓存键
     * @param array $params 参数数组
     * @return bool 是否成功删除
     */
    public function delete($key, $params = []) {
        $cacheKey = $this->generateCacheKey($key, $params);
        $filePath = $this->getCacheFilePath($cacheKey);
        
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        
        return true;
    }
    
    /**
     * 清除所有缓存
     * @return bool 是否成功清除
     */
    public function flush() {
        $files = glob($this->cacheDir . '/*.cache');
        $success = true;
        
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
                error_log("无法删除缓存文件: " . $file);
            }
        }
        
        return $success;
    }
    
    /**
     * 缓存回调函数的结果
     * @param string $key 缓存键
     * @param callable $callback 回调函数，执行实际数据查询
     * @param array $params 参数数组
     * @param int $expireTime 过期时间（秒）
     * @return mixed 缓存的数据或回调函数的执行结果
     */
    public function remember($key, callable $callback, $params = [], $expireTime = null) {
        $data = $this->get($key, $params);
        
        if ($data === null) {
            $data = $callback();
            $this->set($key, $data, $params, $expireTime);
        }
        
        return $data;
    }
    
    /**
     * 获取缓存统计信息
     * @return array 缓存统计信息
     */
    public function getStats() {
        $files = glob($this->cacheDir . '/*.cache');
        $totalSize = 0;
        $count = 0;
        $expired = 0;
        $now = time();
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
            $count++;
            
            $cacheData = unserialize(file_get_contents($file));
            if ($cacheData['expire'] < $now) {
                $expired++;
            }
        }
        
        return [
            'count' => $count,
            'expired' => $expired,
            'total_size' => $totalSize,
            'size_formatted' => $this->formatSize($totalSize),
            'directory' => $this->cacheDir
        ];
    }
    
    /**
     * 格式化文件大小
     * @param int $bytes 字节数
     * @return string 格式化后的大小
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
?> 