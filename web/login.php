<?php
/**
 * 登录页面
 */
require_once 'config/config.php';
require_once 'utils/helpers.php';  // 先加载helpers
require_once 'utils/auth.php';

// 设置缓存控制头，防止浏览器缓存
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

// 如果用户已经登录，则重定向到首页
if (isLoggedIn()) {
    redirect(BASE_URL . '/index.php');
}

$error = '';
$username = '';
$role = 'teacher';  // 默认选择辅导员角色

if (getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = '安全验证失败，请刷新页面重试';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = sanitizeInput($_POST['role'] ?? 'teacher');
        
        if (empty($username) || empty($password)) {
            $error = '用户名和密码不能为空';
        } else {
            // 执行登录验证
            $loginResult = login($username, $password, $role);
            if ($loginResult['success']) {
                // 记录日志
                error_log("用户 {$username} 登录成功，角色：{$role}");
                redirect(BASE_URL . '/index.php');
            } else {
                if (isset($loginResult['password_expired']) && $loginResult['password_expired']) {
                    redirect(BASE_URL . '/change_password.php?expired=1');
                }
                $error = $loginResult['message'] ?? '登录失败，请检查用户名和密码';
            }
        }
    }
}

// 显示密码更新成功提示
$passwordUpdated = isset($_GET['password_updated']) && $_GET['password_updated'] == 1;

// 显示登出成功提示
$logoutSuccess = isset($_GET['logout']) && $_GET['logout'] == 1;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.bootcdn.net/ajax/libs/bootstrap-icons/1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #2e59d9;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body, html {
            height: 100%;
            font-family: 'Noto Sans SC', sans-serif;
            background-color: var(--light-color);
        }
        
        .login-page {
            height: 100%;
            display: flex;
            overflow: hidden;
        }
        
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .login-left::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(45deg);
            z-index: 0;
        }
        
        .login-left-content {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 500px;
        }
        
        .login-left h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .login-left p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        
        .login-features {
            list-style: none;
            padding: 0;
            margin: 0;
            text-align: left;
        }
        
        .login-features li {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .login-features i {
            margin-right: 0.5rem;
            font-size: 1.2rem;
            color: var(--warning-color);
        }
        
        .login-right {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            background-color: white;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            background-color: white;
            transition: all 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header h2 {
            font-size: 1.8rem;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .login-header p {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .login-form .form-floating {
            margin-bottom: 1.5rem;
        }
        
        .login-form .form-control {
            border-radius: 10px;
            padding: 0.75rem 1rem;
            height: calc(3.5rem + 2px);
            border: 1px solid #e3e6f0;
            transition: all 0.2s ease;
        }
        
        .login-form .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.1);
        }
        
        .login-form label {
            padding: 0.75rem 1rem;
        }
        
        .input-group-text {
            background-color: transparent;
            border-right: none;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            color: #6c757d;
        }
        
        .input-group .form-control {
            border-left: none;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        
        .btn-login {
            width: 100%;
            padding: 0.75rem;
            font-size: 1rem;
            border-radius: 10px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(50, 50, 93, 0.11), 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(50, 50, 93, 0.1), 0 3px 6px rgba(0, 0, 0, 0.08);
            background: linear-gradient(to right, var(--secondary-color), var(--primary-color));
        }
        
        .btn-login:active {
            transform: translateY(1px);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .alert {
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: none;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0% { transform: translateX(0); }
            20% { transform: translateX(-10px); }
            40% { transform: translateX(10px); }
            60% { transform: translateX(-5px); }
            80% { transform: translateX(5px); }
            100% { transform: translateX(0); }
        }
        
        .form-floating-icon {
            position: relative;
        }
        
        .form-floating-icon i {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            left: 1rem;
            color: #6c757d;
            z-index: 10;
        }
        
        .form-floating-icon .form-control {
            padding-left: 2.5rem;
        }
        
        .form-floating-icon label {
            padding-left: 2.5rem;
        }
        
        .role-selector {
            display: flex;
            margin-bottom: 1.5rem;
            gap: 1rem;
        }
        
        .role-card {
            flex: 1;
            border: 2px solid #e3e6f0;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .role-card:hover {
            border-color: #ccd0d9;
            background-color: #f8f9fc;
        }
        
        .role-card.active {
            border-color: var(--primary-color);
            background-color: rgba(78, 115, 223, 0.1);
        }
        
        .role-card i {
            font-size: 2rem;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .role-card.active i {
            color: var(--primary-color);
        }
        
        .role-card h5 {
            margin: 0;
            font-size: 1rem;
            color: var(--dark-color);
        }
        
        .role-card.active h5 {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        /* 密码强度指示器 */
        .password-strength {
            height: 5px;
            margin-top: 5px;
            transition: all 0.3s ease;
            border-radius: 5px;
            background-color: #e9ecef;
        }
        
        .password-strength-bar {
            height: 100%;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .strength-weak { width: 30%; background-color: #dc3545; }
        .strength-medium { width: 60%; background-color: #ffc107; }
        .strength-strong { width: 100%; background-color: #28a745; }
        
        /* 响应式设计 */
        @media (max-width: 992px) {
            .login-page {
                flex-direction: column;
            }
            
            .login-left {
                padding: 3rem 1rem;
            }
            
            .login-left-content {
                max-width: 100%;
            }
            
            .login-features {
                display: none;
            }
        }
        
        @media (max-width: 576px) {
            .login-container {
                padding: 1.5rem;
            }
            
            .login-left h1 {
                font-size: 2rem;
            }
            
            .role-selector {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-left">
            <div class="login-left-content">
            <h1><?php echo SITE_NAME; ?></h1>
                <p>智能查寝管理系统，提升宿舍管理效率，为校园安全保驾护航</p>
                <ul class="login-features">
                    <li><i class="bi bi-check-circle-fill"></i> 实时掌握学生在寝情况</li>
                    <li><i class="bi bi-check-circle-fill"></i> 快速处理学生请假申请</li>
                    <li><i class="bi bi-check-circle-fill"></i> 数据统计分析，科学决策</li>
                    <li><i class="bi bi-check-circle-fill"></i> 安全可靠，保障学生隐私</li>
                </ul>
            </div>
        </div>
        <div class="login-right">
            <div class="login-container">
                <div class="login-header">
                    <h2>欢迎登录</h2>
                    <p>请选择您的角色并输入账号密码</p>
        </div>
        
        <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo $error; ?>
                    </div>
        <?php endif; ?>
        
        <?php if ($passwordUpdated): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        密码更新成功，请使用新密码登录
                    </div>
        <?php endif; ?>

        <?php if ($logoutSuccess): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        登出成功
                    </div>
        <?php endif; ?>
        
        <form class="login-form" method="post" action="" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="role-selector">
                        <div class="role-card active" data-role="counselor">
                            <i class="bi bi-person-badge"></i>
                            <h5>辅导员</h5>
                        </div>
                        <div class="role-card" data-role="admin">
                            <i class="bi bi-shield-lock"></i>
                            <h5>管理员</h5>
                        </div>
                    </div>
                    <input type="hidden" name="role" id="role" value="counselor">
                    
                    <div class="form-floating form-floating-icon mb-3">
                        <i class="bi bi-person"></i>
                <input type="text" class="form-control" id="username" name="username" placeholder="用户名" required autocomplete="username">
                <label for="username">用户名</label>
            </div>
                    <div class="form-floating form-floating-icon mb-2">
                        <i class="bi bi-lock"></i>
                <input type="password" class="form-control" id="password" name="password" placeholder="密码" required autocomplete="current-password">
                <label for="password">密码</label>
            </div>
                    
                    <div class="password-strength mb-4">
                        <div class="password-strength-bar" id="strengthBar"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right me-2"></i>登录
                    </button>
        </form>
                
                <div class="login-footer">
                    <p>© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. 保留所有权利</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const roleCards = document.querySelectorAll('.role-card');
            const roleInput = document.getElementById('role');
            const passwordInput = document.getElementById('password');
            const strengthBar = document.getElementById('strengthBar');
            const loginBtn = document.getElementById('loginBtn');
            const loginForm = document.getElementById('loginForm');
            
            // 角色选择
            roleCards.forEach(card => {
                card.addEventListener('click', function() {
                    // 移除所有卡片的激活状态
                    roleCards.forEach(c => c.classList.remove('active'));
                    
                    // 激活当前卡片
                    this.classList.add('active');
                    
                    // 设置隐藏输入字段的值
                    roleInput.value = this.dataset.role;
                });
            });
            
            // 密码强度检查
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                if (password.length >= 8) strength += 1;
                if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 1;
                if (password.match(/\d+/)) strength += 1;
                if (password.match(/.[!,@,#,$,%,^,&,*,?,_,~,-,(,)]/)) strength += 1;
                
                // 更新强度条
                strengthBar.className = 'password-strength-bar';
                if (password.length === 0) {
                    strengthBar.classList.add('');
                    strengthBar.style.width = '0';
                } else if (strength < 2) {
                    strengthBar.classList.add('strength-weak');
                } else if (strength < 3) {
                    strengthBar.classList.add('strength-medium');
                } else {
                    strengthBar.classList.add('strength-strong');
                }
            });
            
            // 表单提交前验证
            loginForm.addEventListener('submit', function(event) {
                const username = document.getElementById('username').value;
                const password = passwordInput.value;
                
                if (!username.trim() || !password.trim()) {
                    event.preventDefault();
                    alert('用户名和密码不能为空');
                    return false;
                }
                
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 登录中...';
                return true;
            });
        });
    </script>
</body>
</html> 