<?php
/**
 * 登录保护机制信息页面
 */
require_once 'config/config.php';
require_once 'utils/helpers.php';
require_once 'utils/auth.php';

// 启动session并仅管理员可访问
startSecureSession();
requireAdmin();

// 处理POST请求（必须在输出HTML之前）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db = getDBConnection();
        
        if ($_POST['action'] === 'reset_all') {
            $stmt = $db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL");
            $stmt->execute();
            $_SESSION['message'] = '已重置所有用户的登录状态';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($_POST['action'] === 'unlock_user' && !empty($_POST['username'])) {
            $username = sanitizeInput($_POST['username']);
            $stmt = $db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE username = :username");
            $stmt->execute(['username' => $username]);
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['message'] = '用户 ' . htmlspecialchars($username) . ' 已解锁';
            } else {
                $_SESSION['error'] = '用户不存在或解锁失败';
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error'] = '操作失败: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

$pageTitle = '登录保护设置 - ' . SITE_NAME;
include 'templates/header.php';
?>

<div class="container-fluid mt-4">
    <h1 class="h3 mb-4 text-gray-800"><i class="bi bi-shield-check me-2"></i>登录保护机制</h1>

    <?php
    // 显示操作结果消息
    if (isset($_SESSION['message'])) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
        echo '<i class="bi bi-check-circle me-2"></i>' . $_SESSION['message'];
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['message']);
    }
    
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
        echo '<i class="bi bi-exclamation-triangle me-2"></i>' . $_SESSION['error'];
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['error']);
    }
    ?>

    <div class="row">
        <!-- 当前设置 -->
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">当前保护设置</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">普通用户（辅导员）</label>
                        <div class="border-start border-4 border-warning ps-3">
                            <p class="mb-1">最大尝试次数: <span class="badge bg-warning text-dark"><?php echo MAX_LOGIN_ATTEMPTS; ?> 次</span></p>
                            <p class="mb-0 text-muted">超过限制后账户将被锁定 <?php echo LOCKOUT_TIME_MINUTES; ?> 分钟</p>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">管理员用户</label>
                        <div class="border-start border-4 border-success ps-3">
                            <p class="mb-1">最大尝试次数: <span class="badge bg-success"><?php echo MAX_LOGIN_ATTEMPTS_ADMIN; ?> 次</span></p>
                            <p class="mb-0 text-muted">超过限制后账户将被锁定 <?php echo LOCKOUT_TIME_MINUTES; ?> 分钟</p>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>最新更新:</strong> 管理员账户现在拥有更多的登录尝试机会，从原来的5次增加到10次。
                    </div>
                </div>
            </div>
        </div>

        <!-- 用户状态 -->
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">用户账户状态</h6>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        $db = getDBConnection();
                        $stmt = $db->query("SELECT username, role, login_attempts, locked_until FROM users ORDER BY role DESC, username");
                        $users = $stmt->fetchAll();
                        
                        if (!empty($users)):
                    ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>用户名</th>
                                    <th>角色</th>
                                    <th>失败次数</th>
                                    <th>状态</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td>
                                        <?php if ($user['role'] === 'admin'): ?>
                                            <span class="badge bg-success">管理员</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">辅导员</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $attempts = $user['login_attempts'];
                                        $maxAttempts = ($user['role'] === 'admin') ? MAX_LOGIN_ATTEMPTS_ADMIN : MAX_LOGIN_ATTEMPTS;
                                        $remaining = $maxAttempts - $attempts;
                                        ?>
                                        <span class="<?php echo $attempts > 0 ? 'text-warning' : 'text-muted'; ?>">
                                            <?php echo $attempts; ?>/<?php echo $maxAttempts; ?>
                                        </span>
                                        <?php if ($attempts > 0): ?>
                                            <small class="text-muted">(剩余<?php echo $remaining; ?>次)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['locked_until'] && strtotime($user['locked_until']) > time()): ?>
                                            <?php $remainingTime = ceil((strtotime($user['locked_until']) - time()) / 60); ?>
                                            <span class="badge bg-danger">锁定中 (<?php echo $remainingTime; ?>分钟)</span>
                                        <?php elseif ($attempts > ($maxAttempts * 0.8)): ?>
                                            <span class="badge bg-warning text-dark">警告</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">正常</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php 
                        else:
                            echo '<p class="text-muted">暂无用户数据</p>';
                        endif;
                    } catch (Exception $e) {
                        echo '<div class="alert alert-danger">获取用户数据失败: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 解锁工具 -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-danger">管理员工具</h6>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>注意:</strong> 以下操作仅限紧急情况使用。
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <form method="POST" action="#" onsubmit="return confirm('确定要重置所有用户的登录尝试次数吗？');">
                                <input type="hidden" name="action" value="reset_all">
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-arrow-clockwise me-2"></i>重置所有用户登录状态
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="POST" action="#" class="d-flex" onsubmit="return confirm('确定要解锁该用户吗？');">
                                <input type="hidden" name="action" value="unlock_user">
                                <input type="text" name="username" class="form-control me-2" placeholder="输入用户名" required>
                                <button type="submit" class="btn btn-info">
                                    <i class="bi bi-unlock me-2"></i>解锁用户
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<?php include 'templates/footer.php'; ?>