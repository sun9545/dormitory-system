<?php
/**
 * 数据库和系统维护脚本
 * 建议通过cron定期执行，如：
 * 0 3 * * * php /www/wwwroot/localhost/maintenance.php >> /www/wwwroot/localhost/logs/maintenance.log 2>&1
 */

// 设置无限执行时间
set_time_limit(0);

// 设置命令行模式
if (php_sapi_name() !== 'cli') {
    exit("此脚本只能在CLI模式下运行\n");
}

// 加载配置
require_once __DIR__ . '/config/config.php';

// 记录开始时间
$startTime = microtime(true);
$logOutput = "系统维护任务开始：" . date('Y-m-d H:i:s') . "\n";

// 数据库连接
try {
    $db = getDBConnection();
    $logOutput .= "数据库连接成功\n";
} catch (PDOException $e) {
    exit("数据库连接失败: " . $e->getMessage() . "\n");
}

// 优化表
$tables = ['students', 'check_records', 'users', 'operation_logs', 'login_logs'];
$optimizedTables = 0;

foreach ($tables as $table) {
    try {
        $stmt = $db->prepare("OPTIMIZE TABLE $table");
        $stmt->execute();
        $logOutput .= "表 '$table' 已优化\n";
        $optimizedTables++;
    } catch (PDOException $e) {
        $logOutput .= "优化表 '$table' 失败: " . $e->getMessage() . "\n";
    }
}

// 分析表，更新索引统计信息
$analyzedTables = 0;
foreach ($tables as $table) {
    try {
        $stmt = $db->prepare("ANALYZE TABLE $table");
        $stmt->execute();
        $logOutput .= "表 '$table' 已分析\n";
        $analyzedTables++;
    } catch (PDOException $e) {
        $logOutput .= "分析表 '$table' 失败: " . $e->getMessage() . "\n";
    }
}

// 清理过期缓存
try {
    // 加载缓存类
    require_once __DIR__ . '/utils/cache.php';
    $cache = new Cache();
    
    // 获取缓存状态
    $beforeStats = $cache->getStats();
    $logOutput .= "清理前缓存文件数: {$beforeStats['count']} (过期: {$beforeStats['expired']})\n";
    
    // 清理过期缓存
    $files = glob($cache->getStats()['directory'] . '/*.cache');
    $removed = 0;
    
    foreach ($files as $file) {
        $data = unserialize(file_get_contents($file));
        if ($data['expire'] < time()) {
            if (unlink($file)) {
                $removed++;
            }
        }
    }
    
    // 获取清理后状态
    $afterStats = $cache->getStats();
    $logOutput .= "已删除 {$removed} 个过期缓存文件\n";
    $logOutput .= "清理后缓存文件数: {$afterStats['count']} (过期: {$afterStats['expired']})\n";
} catch (Exception $e) {
    $logOutput .= "清理缓存失败: " . $e->getMessage() . "\n";
}

// 删除过旧的日志文件（超过30天）
$logDir = __DIR__ . '/logs';
$deletedLogs = 0;

if (is_dir($logDir)) {
    $logFiles = glob($logDir . '/*.log');
    $thirtyDaysAgo = time() - (30 * 86400);
    
    foreach ($logFiles as $file) {
        if (is_file($file) && filemtime($file) < $thirtyDaysAgo) {
            if (unlink($file)) {
                $deletedLogs++;
                $logOutput .= "已删除过期日志文件: " . basename($file) . "\n";
            }
        }
    }
}

// 自动优化数据库查询 - 检查慢查询日志
// 这里需要系统管理员配置MySQL启用慢查询日志

// 计算总用时
$endTime = microtime(true);
$executionTime = round($endTime - $startTime, 2);

$logOutput .= "系统维护总结：\n";
$logOutput .= "- 已优化的表: $optimizedTables\n";
$logOutput .= "- 已分析的表: $analyzedTables\n";
$logOutput .= "- 已删除的过期日志文件: $deletedLogs\n";
$logOutput .= "- 总执行时间: {$executionTime}秒\n";
$logOutput .= "系统维护任务结束：" . date('Y-m-d H:i:s') . "\n";
$logOutput .= "----------\n";

// 输出日志
echo $logOutput;

// 写入日志文件
$logFile = __DIR__ . '/logs/maintenance_' . date('Y-m-d') . '.log';
file_put_contents($logFile, $logOutput, FILE_APPEND);

exit(0); 