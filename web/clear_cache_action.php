<?php
/**
 * 缓存清理操作页面
 */

require_once 'config/config.php';
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'utils/cache.php';

// 验证CSRF令牌
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    header('Location: ' . BASE_URL . '/cache_management.php?error=' . urlencode('无效的请求令牌'));
    exit;
}

$clearType = $_POST['clear_type'] ?? 'all';
$message = '';
$success = false;

try {
    switch ($clearType) {
        case 'all':
            if (clearAllRelatedCache()) {
                $success = true;
                $message = '所有缓存已成功清除';
                error_log("管理员手动清除了所有缓存");
            } else {
                $message = '清除缓存时出现部分错误，请查看日志';
            }
            break;
            
        case 'students':
            if (clearStudentRelatedCache()) {
                $success = true;
                $message = '学生相关缓存已成功清除';
                error_log("管理员手动清除了学生相关缓存");
            } else {
                $message = '清除学生缓存时出现错误';
            }
            break;
            
        case 'flush':
            if (clearCache()) {
                $success = true;
                $message = '所有缓存文件已强制清除';
                error_log("管理员强制清除了所有缓存文件");
            } else {
                $message = '强制清除缓存时出现错误';
            }
            break;
            
        default:
            $message = '无效的清除类型';
    }
} catch (Exception $e) {
    $message = '清除缓存时发生异常: ' . $e->getMessage();
    error_log("清除缓存异常: " . $e->getMessage());
}

// 重定向回缓存管理页面
$status = $success ? 'success' : 'error';
header('Location: ' . BASE_URL . '/cache_management.php?' . $status . '=' . urlencode($message));
exit;
?>