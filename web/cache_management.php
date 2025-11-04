<?php
/**
 * 缓存管理页面
 */
require_once 'config/config.php';
$pageTitle = '缓存管理 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';

// 仅管理员可访问
requireAdmin();

// 处理缓存操作
$message = '';
$messageType = '';

// 获取缓存实例
$cache = getCacheInstance();

// 处理清除缓存请求
if (isset($_POST['action'])) {
    // 验证CSRF令牌
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = '安全验证失败，请刷新页面重试';
        $messageType = 'danger';
    } else {
        switch ($_POST['action']) {
            case 'flush_all':
                if ($cache->flush()) {
                    $message = '已成功清除所有缓存';
                    $messageType = 'success';
                    // 记录操作日志
                    logOperation($_SESSION['user_id'], '清除缓存', '清除所有系统缓存');
                } else {
                    $message = '清除缓存时发生错误';
                    $messageType = 'danger';
                }
                break;
                
            case 'flush_stats':
                $cacheKeys = [
                    CACHE_KEY_BUILDING_STATS,
                    CACHE_KEY_FLOOR_STATS,
                    CACHE_KEY_ALL_STATUS_DATE
                ];
                $success = true;
                
                foreach ($cacheKeys as $key) {
                    if (!$cache->delete($key)) {
                        $success = false;
                    }
                }
                
                if ($success) {
                    $message = '已成功清除统计数据缓存';
                    $messageType = 'success';
                    // 记录操作日志
                    logOperation($_SESSION['user_id'], '清除缓存', '清除统计数据缓存');
                } else {
                    $message = '清除统计数据缓存时发生错误';
                    $messageType = 'danger';
                }
                break;
                
            case 'toggle_cache':
                $enabled = $_POST['enabled'] === '1';
                
                // 更新配置（真实情况需要修改配置文件）
                $configFile = ROOT_PATH . '/config/cache_config.php';
                $configContent = file_get_contents($configFile);
                
                if ($enabled) {
                    $configContent = str_replace("define('CACHE_ENABLED', false)", "define('CACHE_ENABLED', true)", $configContent);
                    $message = '缓存功能已启用';
                } else {
                    $configContent = str_replace("define('CACHE_ENABLED', true)", "define('CACHE_ENABLED', false)", $configContent);
                    $message = '缓存功能已禁用';
                }
                
                if (file_put_contents($configFile, $configContent)) {
                    $messageType = 'success';
                    // 记录操作日志
                    logOperation($_SESSION['user_id'], '更新配置', '更改缓存状态为: ' . ($enabled ? '启用' : '禁用'));
                } else {
                    $message = '更新配置文件失败，请检查文件权限';
                    $messageType = 'danger';
                }
                break;
        }
    }
}

// 处理来自缓存清理操作页面的消息
if (isset($_GET['success'])) {
    $message = $_GET['success'];
    $messageType = 'success';
} elseif (isset($_GET['error'])) {
    $message = $_GET['error'];
    $messageType = 'danger';
}

// 获取缓存状态
$stats = $cache->getStats();

// 加载页头
include 'templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">缓存管理</h1>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-info-circle me-2"></i>缓存状态
            </div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr>
                            <th>缓存状态:</th>
                            <td>
                                <?php if (CACHE_ENABLED): ?>
                                    <span class="badge bg-success">已启用</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">已禁用</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>缓存文件数量:</th>
                            <td><?php echo $stats['count']; ?> 个文件</td>
                        </tr>
                        <tr>
                            <th>过期文件数量:</th>
                            <td><?php echo $stats['expired']; ?> 个文件</td>
                        </tr>
                        <tr>
                            <th>总缓存大小:</th>
                            <td><?php echo $stats['size_formatted']; ?></td>
                        </tr>
                        <tr>
                            <th>缓存目录:</th>
                            <td><code><?php echo $stats['directory']; ?></code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-gear me-2"></i>缓存操作
            </div>
            <div class="card-body">
                <form method="post" action="" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="toggle_cache">
                    <input type="hidden" name="enabled" value="<?php echo CACHE_ENABLED ? '0' : '1'; ?>">
                    <button type="submit" class="btn <?php echo CACHE_ENABLED ? 'btn-warning' : 'btn-success'; ?> mb-3">
                        <i class="bi <?php echo CACHE_ENABLED ? 'bi-toggle-on' : 'bi-toggle-off'; ?> me-2"></i>
                        <?php echo CACHE_ENABLED ? '禁用缓存' : '启用缓存'; ?>
                    </button>
                </form>
                
                <hr>
                
                <form method="post" action="" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="flush_stats">
                    <button type="submit" class="btn btn-info mb-3" <?php echo !CACHE_ENABLED ? 'disabled' : ''; ?>>
                        <i class="bi bi-bar-chart me-2"></i>
                        清除统计数据缓存
                    </button>
                    <p class="small text-muted">
                        清除查寝统计、楼栋统计等数据缓存，保留其他缓存
                    </p>
                </form>
                
                <hr>
                
                <form method="post" action="clear_cache_action.php" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="clear_type" value="students">
                    <button type="submit" class="btn btn-warning mb-3" <?php echo !CACHE_ENABLED ? 'disabled' : ''; ?>>
                        <i class="bi bi-people me-2"></i>
                        强力清除学生相关缓存
                    </button>
                    <p class="small text-muted">
                        清除所有学生信息、状态、统计相关的缓存，解决更新不及时问题
                    </p>
                </form>
                
                <form method="post" action="clear_cache_action.php" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="clear_type" value="all">
                    <button type="submit" class="btn btn-danger mb-3" <?php echo !CACHE_ENABLED ? 'disabled' : ''; ?>>
                        <i class="bi bi-lightning me-2"></i>
                        强力清除所有相关缓存
                    </button>
                    <p class="small text-muted">
                        智能清除所有业务相关缓存，确保数据即时更新
                    </p>
                </form>
                
                <hr>
                
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="flush_all">
                    <button type="submit" class="btn btn-outline-danger" <?php echo !CACHE_ENABLED ? 'disabled' : ''; ?>>
                        <i class="bi bi-trash me-2"></i>
                        清除所有缓存文件
                    </button>
                    <p class="small text-muted mt-2">
                        物理删除所有缓存文件（包括过期文件）
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-question-circle me-2"></i>关于缓存
            </div>
            <div class="card-body">
                <h5>缓存系统说明</h5>
                <p>缓存系统用于减少数据库查询次数，提高系统响应速度。以下是系统缓存的主要类型：</p>
                
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>缓存类型</th>
                            <th>缓存时间</th>
                            <th>描述</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>学生状态缓存</td>
                            <td><?php echo CACHE_EXPIRE_CHECK_RECORDS; ?>秒</td>
                            <td>学生的最新查寝状态记录</td>
                        </tr>
                        <tr>
                            <td>统计数据缓存</td>
                            <td><?php echo CACHE_EXPIRE_STATISTICS; ?>秒</td>
                            <td>楼栋和楼层的查寝统计数据</td>
                        </tr>
                        <tr>
                            <td>学生信息缓存</td>
                            <td><?php echo CACHE_EXPIRE_STUDENTS; ?>秒</td>
                            <td>学生基本信息数据</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="alert alert-info mt-3">
                    <p><strong>缓存自动更新说明:</strong></p>
                    <ul>
                        <li>当学生状态更新时，相关缓存会自动清除</li>
                        <li>所有缓存都有过期时间，过期后自动失效</li>
                        <li>如果数据不一致，可以手动清除缓存</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// 加载页脚
include 'templates/footer.php';
?> 