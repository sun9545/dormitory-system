<?php
/**
 * 改进的系统维护脚本
 * 定期清理日志、缓存和优化系统性能
 */

require_once 'config/config.php';

// 检查是否从命令行运行
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    // 如果从Web访问，需要验证管理员权限
    require_once 'utils/auth.php';
    requireLogin();
    $currentUser = getCurrentUser();
    if ($currentUser['role'] !== 'admin') {
        http_response_code(403);
        die('只有管理员可以执行维护操作');
    }
}

echo "=== 学生查寝系统维护脚本 ===\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

/**
 * 清理旧日志文件
 */
function cleanupLogs() {
    echo "正在清理旧日志文件...\n";
    
    $logDir = ROOT_PATH . '/logs';
    $daysToKeep = 30; // 保留30天的日志
    $cutoffTime = time() - ($daysToKeep * 24 * 3600);
    
    if (!is_dir($logDir)) {
        echo "日志目录不存在\n";
        return;
    }
    
    $files = glob($logDir . '/*.log');
    $deletedCount = 0;
    $totalSize = 0;
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            $size = filesize($file);
            if (unlink($file)) {
                $deletedCount++;
                $totalSize += $size;
                echo "删除: " . basename($file) . " (" . formatBytes($size) . ")\n";
            }
        }
    }
    
    echo "清理完成: 删除 {$deletedCount} 个文件，释放空间 " . formatBytes($totalSize) . "\n\n";
}

/**
 * 清理缓存文件
 */
function cleanupCache() {
    echo "正在清理缓存文件...\n";
    
    $cacheDir = ROOT_PATH . '/cache';
    
    if (!is_dir($cacheDir)) {
        echo "缓存目录不存在\n";
        return;
    }
    
    $files = glob($cacheDir . '/*');
    $deletedCount = 0;
    $totalSize = 0;
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $size = filesize($file);
            if (unlink($file)) {
                $deletedCount++;
                $totalSize += $size;
            }
        }
    }
    
    echo "清理完成: 删除 {$deletedCount} 个缓存文件，释放空间 " . formatBytes($totalSize) . "\n\n";
}

/**
 * 优化数据库表
 */
function optimizeDatabase() {
    echo "正在优化数据库表...\n";
    
    try {
        // 为每个操作创建新的连接以避免缓冲查询问题
        $tables = ['students', 'check_records', 'users', 'operation_logs', 'login_logs', 'leave_batch', 'leave_records', 'password_history'];
        
        foreach ($tables as $table) {
            echo "优化表: {$table}... ";
            try {
                $db = getDBConnection();
                $db->exec("OPTIMIZE TABLE `{$table}`");
                $db = null; // 关闭连接
                echo "完成\n";
            } catch (Exception $e) {
                echo "失败: " . $e->getMessage() . "\n";
            }
        }
        
        echo "数据库优化完成\n\n";
    } catch (Exception $e) {
        echo "数据库优化失败: " . $e->getMessage() . "\n\n";
    }
}

/**
 * 检查系统状态
 */
function checkSystemStatus() {
    echo "正在检查系统状态...\n";
    
    // 检查磁盘空间
    $diskFree = disk_free_space('/');
    $diskTotal = disk_total_space('/');
    $diskUsed = $diskTotal - $diskFree;
    $diskUsagePercent = round(($diskUsed / $diskTotal) * 100, 2);
    
    echo "磁盘使用率: {$diskUsagePercent}% (" . formatBytes($diskUsed) . " / " . formatBytes($diskTotal) . ")\n";
    
    if ($diskUsagePercent > 80) {
        echo "⚠️  警告: 磁盘使用率超过80%\n";
    }
    
    // 检查内存使用
    $memUsage = memory_get_usage(true);
    $memPeak = memory_get_peak_usage(true);
    
    echo "PHP内存使用: " . formatBytes($memUsage) . " (峰值: " . formatBytes($memPeak) . ")\n";
    
    // 检查重要目录权限
    $directories = ['logs', 'cache', 'uploads', 'config'];
    echo "目录权限检查:\n";
    
    foreach ($directories as $dir) {
        $fullPath = ROOT_PATH . '/' . $dir;
        if (is_dir($fullPath)) {
            $perms = substr(sprintf('%o', fileperms($fullPath)), -3);
            echo "  {$dir}: {$perms}";
            if (is_writable($fullPath)) {
                echo " ✓\n";
            } else {
                echo " ❌ (不可写)\n";
            }
        } else {
            echo "  {$dir}: 不存在 ❌\n";
        }
    }
    
    echo "\n";
}

/**
 * 生成系统报告
 */
function generateSystemReport() {
    echo "正在生成系统报告...\n";
    
    try {
        // 统计数据
        $stats = [];
        
        // 为每个查询创建独立连接
        $db1 = getDBConnection();
        $stmt = $db1->query("SELECT COUNT(*) FROM students");
        $stats['total_students'] = $stmt->fetchColumn();
        $db1 = null;
        
        $db2 = getDBConnection();
        $stmt = $db2->query("SELECT COUNT(*) FROM check_records WHERE DATE(check_time) = CURDATE()");
        $stats['today_checkins'] = $stmt->fetchColumn();
        $db2 = null;
        
        $db3 = getDBConnection();
        $stmt = $db3->query("SELECT COUNT(*) FROM users");
        $stats['total_users'] = $stmt->fetchColumn();
        $db3 = null;
        
        $db4 = getDBConnection();
        $stmt = $db4->query("SELECT COUNT(*) FROM check_records");
        $stats['total_records'] = $stmt->fetchColumn();
        $db4 = null;
        
        echo "系统统计:\n";
        echo "  学生总数: {$stats['total_students']}\n";
        echo "  今日签到: {$stats['today_checkins']}\n";
        echo "  用户总数: {$stats['total_users']}\n";
        echo "  总签到记录: {$stats['total_records']}\n";
        
        // 保存报告到文件
        $reportFile = ROOT_PATH . '/logs/system_report_' . date('Y-m-d') . '.log';
        $reportContent = "=== 系统报告 " . date('Y-m-d H:i:s') . " ===\n";
        $reportContent .= "学生总数: {$stats['total_students']}\n";
        $reportContent .= "今日签到: {$stats['today_checkins']}\n";
        $reportContent .= "用户总数: {$stats['total_users']}\n";
        $reportContent .= "总签到记录: {$stats['total_records']}\n";
        $reportContent .= "磁盘使用: " . round((1 - disk_free_space('/') / disk_total_space('/')) * 100, 2) . "%\n";
        $reportContent .= "内存使用: " . formatBytes(memory_get_usage(true)) . "\n";
        $reportContent .= "=====================================\n\n";
        
        file_put_contents($reportFile, $reportContent, FILE_APPEND | LOCK_EX);
        echo "报告已保存到: {$reportFile}\n\n";
        
    } catch (Exception $e) {
        echo "生成报告失败: " . $e->getMessage() . "\n\n";
    }
}

/**
 * 格式化字节数
 */
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// 执行维护任务
try {
    cleanupLogs();
    cleanupCache();
    optimizeDatabase();
    checkSystemStatus();
    generateSystemReport();
    
    echo "=== 维护完成 ===\n";
    echo "结束时间: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "维护过程中发生错误: " . $e->getMessage() . "\n";
    error_log("Maintenance error: " . $e->getMessage());
}
?>