<?php
/**
 * 缓存配置文件
 */

// 缓存过期时间配置（秒）
define('CACHE_EXPIRE_SHORT', 10);          // 10秒 - 更短的缓存时间
define('CACHE_EXPIRE_MEDIUM', 30);         // 30秒
define('CACHE_EXPIRE_LONG', 120);          // 2分钟 - 缩短长缓存时间
define('CACHE_EXPIRE_VERY_LONG', 600);     // 10分钟 - 大幅缩短
define('CACHE_EXPIRE_DAY', 86400);         // 1天

// 统计数据缓存时间 - 需要更快更新
define('CACHE_EXPIRE_STATISTICS', 15);     // 15秒

// 学生信息缓存时间 - 相对静态数据但需要快速更新
define('CACHE_EXPIRE_STUDENTS', 60);       // 1分钟

// 查寝记录缓存时间 - 频繁变化的数据，需要快速更新
define('CACHE_EXPIRE_CHECK_RECORDS', 10);  // 10秒

// 用户信息缓存时间
define('CACHE_EXPIRE_USERS', 1800);        // 30分钟

// 缓存开关 - 用于轻松禁用/启用缓存
define('CACHE_ENABLED', false); // ⚠️ 已禁用缓存（性能会下降）

// 缓存键前缀
define('CACHE_KEY_PREFIX', 'checkin_');

/**
 * 缓存键定义 - 使缓存键更加一致和可管理
 */
define('CACHE_KEY_BUILDING_STATS', CACHE_KEY_PREFIX . 'building_stats');
define('CACHE_KEY_FLOOR_STATS', CACHE_KEY_PREFIX . 'floor_stats');
define('CACHE_KEY_STUDENT_STATUS', CACHE_KEY_PREFIX . 'student_status');
define('CACHE_KEY_ALL_STUDENTS', CACHE_KEY_PREFIX . 'all_students');
define('CACHE_KEY_STUDENT_HISTORY', CACHE_KEY_PREFIX . 'student_history');
define('CACHE_KEY_ALL_STATUS_DATE', CACHE_KEY_PREFIX . 'all_status_date');

/**
 * 获取缓存实例
 * @return Cache 缓存实例
 */
function getCacheInstance() {
    static $cache = null;
    if ($cache === null) {
        require_once ROOT_PATH . '/utils/cache.php';
        $cache = new Cache();
    }
    return $cache;
}