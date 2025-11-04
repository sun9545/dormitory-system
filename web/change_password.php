<?php
/**
 * 密码修改页面
 */
require_once 'config/config.php';
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'utils/password_policy.php';

$pageTitle = '修改密码 - ' . SITE_NAME;
$message = '';
$messageType = '';
$isExpired = isset($_GET['expired']) && $_GET['expired'] == 1;

// 检查是否是密码过期会话
if ($isExpired && !isset($_SESSION['password_expired'])) {
    redirect(BASE_URL . '/login.php');
}

// 如果不是密码过期会话，则需要普通的登录状态
if (!$isExpired && !isLoggedIn()) {
    redirect(BASE_URL . '/login.php');
}

// 获取用户ID
$userId = isset($_SESSION['temp_user_id']) ? $_SESSION['temp_user_id'] : $_SESSION['user_id'];

// 处理表单提交
if (getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = '安全验证失败，请重试';
        $messageType = 'danger';
    } else {
        // 验证密码
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // 检查新密码和确认密码是否匹配
        if ($newPassword !== $confirmPassword) {
            $message = '新密码和确认密码不匹配';
            $messageType = 'danger';
        } else {
            // 验证当前密码
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT password FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($currentPassword, $user['password'])) {
                $message = '当前密码不正确';
                $messageType = 'danger';
            } else {
                // 简单的密码长度检查（最少6位）
                if (strlen($newPassword) < 6) {
                    $message = '密码长度至少需要6位字符';
                    $messageType = 'danger';
                } else {
                    // 更新密码
                    $result = updateUserPassword($userId, $newPassword);
                    
                    if ($result['success']) {
                        // 记录操作日志
                        logOperation($userId, '修改密码', '用户修改了密码');
                        logSystemMessage("用户ID {$userId} 修改了密码", 'info');
                        
                        // 密码修改成功后，清除会话并强制重新登录
                        session_destroy();
                        redirect(BASE_URL . '/login.php?password_updated=1');
                        
                    } else {
                        $message = $result['message'] ?? '密码更新失败，请稍后再试';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

// 加载页面头部
include 'templates/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <?php if ($isExpired): ?>
                            <i class="bi bi-clock-history me-2"></i>您的密码已过期
                        <?php else: ?>
                            <i class="bi bi-shield-lock me-2"></i>修改密码
                        <?php endif; ?>
                    </h4>
                </div>
                <div class="card-body">
                    <?php if ($isExpired): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            您的密码已过期，请设置新密码以继续访问系统。
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?>">
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="" id="passwordForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">当前密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                <button type="button" class="btn btn-outline-secondary toggle-password" data-target="current_password" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">新密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                <button type="button" class="btn btn-outline-secondary toggle-password" data-target="new_password" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div id="passwordStrength" class="mt-2">
                                <div class="progress">
                                    <div id="passwordStrengthBar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <small id="passwordStrengthText" class="form-text text-muted mt-1">密码强度: 请输入密码</small>
                            </div>
                            <div class="form-text mt-2 text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                密码长度至少6个字符，建议使用字母、数字和特殊字符的组合以提高安全性
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">确认新密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                <button type="button" class="btn btn-outline-secondary toggle-password" data-target="confirm_password" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text text-muted" id="passwordMatch"></div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="bi bi-check-circle me-2"></i>修改密码
                            </button>
                            <?php if (!$isExpired): ?>
                                <a href="<?php echo BASE_URL; ?>/index.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>返回
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- 密码安全提示 -->
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-info-circle me-2"></i>密码安全提示
                    </h5>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>使用不同网站的不同密码</li>
                        <li>避免使用个人信息作为密码</li>
                        <li>定期更改密码</li>
                        <li>不要将密码告诉他人</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 密码显示切换
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const passwordInput = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    });
    
    // 密码强度检查
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('passwordStrengthBar');
    const strengthText = document.getElementById('passwordStrengthText');
    const passwordMatchText = document.getElementById('passwordMatch');
    const submitBtn = document.getElementById('submitBtn');
    
    newPassword.addEventListener('input', function() {
        const password = this.value;
        let strength = 0;
        
        // 简化的强度检查
        if (password.length >= 6) strength += 1;
        if (/[A-Z]/.test(password)) strength += 1;
        if (/[a-z]/.test(password)) strength += 1;
        if (/[0-9]/.test(password)) strength += 1;
        if (/[^A-Za-z0-9]/.test(password)) strength += 1;
        
        // 更新强度条
        let percentage = 0;
        let barClass = 'bg-danger';
        let strengthLabel = '弱';
        
        if (password.length === 0) {
            percentage = 0;
            strengthLabel = '请输入密码';
        } else if (strength === 1) {
            percentage = 25;
            strengthLabel = '非常弱';
        } else if (strength === 2) {
            percentage = 50;
            barClass = 'bg-warning';
            strengthLabel = '弱';
        } else if (strength === 3) {
            percentage = 75;
            barClass = 'bg-info';
            strengthLabel = '中等';
        } else if (strength >= 4) {
            percentage = 100;
            barClass = 'bg-success';
            strengthLabel = '强';
        }
        
        strengthBar.style.width = percentage + '%';
        strengthBar.className = 'progress-bar ' + barClass;
        strengthText.textContent = '密码强度: ' + strengthLabel;
        
        // 检查确认密码是否匹配
        if (confirmPassword.value) {
            checkPasswordMatch();
        }
    });
    
    // 检查密码是否匹配
    function checkPasswordMatch() {
        if (newPassword.value === confirmPassword.value) {
            passwordMatchText.textContent = '密码匹配';
            passwordMatchText.className = 'form-text text-success';
            return true;
        } else {
            passwordMatchText.textContent = '密码不匹配';
            passwordMatchText.className = 'form-text text-danger';
            return false;
        }
    }
    
    confirmPassword.addEventListener('input', checkPasswordMatch);
    
    // 表单提交前验证
    document.getElementById('passwordForm').addEventListener('submit', function(event) {
        const password = newPassword.value;
        let isValid = true;
        
        // 只检查基本长度（6位）
        if (password.length < 6) {
            isValid = false;
            alert('密码长度至少需要6个字符');
            event.preventDefault();
            return;
        }
        
        // 检查密码是否匹配
        if (!checkPasswordMatch()) {
            isValid = false;
            alert('两次输入的密码不一致');
            event.preventDefault();
            return;
        }
        
        // 禁用按钮防止重复提交
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 处理中...';
    });
});
</script>

<?php
// 加载页脚
include 'templates/footer.php';
?> 