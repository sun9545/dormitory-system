<?php
/**
 * 认证相关工具函数
 */

// 导入密码策略
require_once __DIR__ . '/password_policy.php';
// 导入辅助函数
require_once __DIR__ . '/helpers.php';
// 导入Session管理器
require_once __DIR__ . '/session_manager.php';

// 启动会话（保持向后兼容）
function startSecureSession() {
    SessionManager::start();
}

// 登录认证
function login($username, $password, $role = '') {
    // 检查登录尝试次数
    startSecureSession();
    
    // 获取当前IP地址
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    // 检查账户锁定状态
    $lockCheck = checkAccountLocked($username);
    if ($lockCheck['locked']) {
        error_log("登录尝试被拒绝: 用户 {$username} 从IP {$ip_address} 尝试登录，但账号已锁定");
        return ['success' => false, 'message' => $lockCheck['message']];
    }
    
    $db = getDBConnection();
    
    $query = "SELECT * FROM users WHERE username = :username";
    $params = ['username' => $username];
    
    // 如果指定了角色，添加角色条件
    if (!empty($role)) {
        $query .= " AND role = :role";
        $params['role'] = $role;
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        // 检查账号状态
        if ($user['status'] === 'inactive') {
            recordLoginAttempt($username, false);
            error_log("登录失败: 用户 {$username} 账号已停用");
            return ['success' => false, 'message' => '账号已停用，请联系管理员'];
        }
        
        // 检查密码是否过期
        if (isPasswordExpired($user['id'])) {
            // 创建临时会话，允许用户修改密码
            startSecureSession();
            $_SESSION['temp_user_id'] = $user['id'];
            $_SESSION['password_expired'] = true;
            
            error_log("密码过期: 用户 {$username} 密码已过期，需要修改");
            return ['success' => false, 'message' => '您的密码已过期，请修改密码', 'password_expired' => true];
        }
        
        // 记录成功的登录尝试
        recordLoginAttempt($username, true);
        
        // 登录成功
        startSecureSession();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['last_activity'] = time();
        
        // 记录IP地址和浏览器信息
        $_SESSION['ip_address'] = $ip_address;
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // 生成新的会话ID以防止会话固定攻击
        session_regenerate_id(true);
        
        // 获取客户端信息
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // 记录登录日志
        logLogin($user['id'], $ip_address, $user_agent);
        logOperation($user['id'], '用户登录', '用户 ' . $user['username'] . ' 以 ' . $user['role'] . ' 角色登录系统');
        
        // 添加系统日志
        logSystemMessage("用户 {$user['username']} 登录成功", 'info');
        
        return ['success' => true];
    }
    
    // 登录失败，记录尝试
    recordLoginAttempt($username, false);
    
    // 获取当前尝试次数和用户角色
    $stmt = $db->prepare("SELECT login_attempts, locked_until, role FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $userData = $stmt->fetch();
    
    $attemptCount = $userData['login_attempts'] ?? 0;
    $userRole = $userData['role'] ?? 'counselor';
    
    // 根据用户角色确定最大尝试次数
    $maxAttempts = ($userRole === 'admin') ? MAX_LOGIN_ATTEMPTS_ADMIN : MAX_LOGIN_ATTEMPTS;
    $remainingAttempts = $maxAttempts - $attemptCount;
    
    // 如果用户被锁定
    if ($userData && isset($userData['locked_until']) && $userData['locked_until'] && strtotime($userData['locked_until']) > time()) {
        $remainingTime = ceil((strtotime($userData['locked_until']) - time()) / 60);
        error_log("登录失败: 用户 {$username} 从IP {$ip_address} 登录已被锁定，剩余时间 {$remainingTime} 分钟");
        return ['success' => false, 'message' => "由于多次登录失败，账号已被锁定，请 {$remainingTime} 分钟后再试"];
    }
    
    if ($remainingAttempts <= 0) {
        error_log("账号锁定: 用户 {$username} 从IP {$ip_address} 登录失败次数过多，已锁定 " . LOCKOUT_TIME_MINUTES . " 分钟");
        return ['success' => false, 'message' => '登录失败次数过多，账号已被锁定，请30分钟后再试'];
    }
    
    $roleText = ($userRole === 'admin') ? '管理员' : '普通用户';
    error_log("登录失败: 用户 {$username} ({$roleText}) 从IP {$ip_address} 登录失败，剩余尝试次数 {$remainingAttempts}");
    
    return ['success' => false, 'message' => "用户名、密码或角色错误，{$roleText}还有 {$remainingAttempts} 次尝试机会"];
}

// 检查用户是否已登录
function isLoggedIn() {
    return SessionManager::isLoggedIn();
}

// 检查当前用户是否为管理员
function isAdmin() {
    return SessionManager::isAdmin();
}

/**
 * 检查用户是否为辅导员
 */
function isCounselor() {
    return SessionManager::isCounselor();
}

// 注销用户
function logout() {
    SessionManager::start();
    
    // 记录注销日志
    if (isset($_SESSION['user_id'])) {
        error_log("记录注销操作 - 用户ID: {$_SESSION['user_id']}, 用户名: {$_SESSION['username']}");
        logOperation($_SESSION['user_id'], '用户注销', '用户 ' . $_SESSION['username'] . ' 注销登录');
        logSystemMessage("用户 {$_SESSION['username']} 注销登录", 'info');
    }
    
    // 使用SessionManager销毁会话
    SessionManager::destroy();
}

// 获取当前登录用户信息
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    return $stmt->fetch();
}

// 生成CSRF令牌
function generateCSRFToken() {
    startSecureSession();
    
    // 使用更强的随机性
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

// 验证CSRF令牌
function verifyCSRFToken($token) {
    startSecureSession();
    
    if (!isset($_SESSION['csrf_token']) || empty($token) || $token !== $_SESSION['csrf_token']) {
        // 记录可能的CSRF攻击
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        $user = isset($_SESSION['username']) ? $_SESSION['username'] : 'Not logged in';
        
        error_log("可能的CSRF攻击: User: {$user}, IP: {$ip}, URI: {$uri}, Expected token: {$_SESSION['csrf_token']}, Received token: {$token}");
        return false;
    }
    
    // 验证成功 - 不立即重新生成令牌，以支持同一页面的多次AJAX请求
    // 令牌将在下次页面加载时自动更新
    return true;
}

// 检查权限并重定向
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    
    // 检查密码是否过期
    $user = getCurrentUser();
    if ($user && isPasswordExpired($user['id'])) {
        // 设置会话标记并重定向到密码修改页面
        $_SESSION['password_expired'] = true;
        header('Location: ' . BASE_URL . '/change_password.php?expired=1');
        exit;
    }
}

// 检查管理员权限
function requireAdmin() {
    requireLogin();
    
    if (!isAdmin()) {
        // 记录未授权访问尝试
        $user_id = $_SESSION['user_id'] ?? 'Unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        
        error_log("未授权的管理员访问尝试: User ID: {$user_id}, IP: {$ip}, URI: {$uri}");
        
        header('Location: ' . BASE_URL . '/index.php?error=权限不足');
        exit;
    }
}

// 记录操作日志
function logOperation($user_id, $operation_type, $operation_desc) {
    $db = getDBConnection();
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    $stmt = $db->prepare("INSERT INTO operation_logs (user_id, operation_type, operation_desc, operation_time, ip_address) 
                          VALUES (:user_id, :operation_type, :operation_desc, NOW(), :ip_address)");
    
    $stmt->execute([
        'user_id' => $user_id,
        'operation_type' => $operation_type,
        'operation_desc' => $operation_desc,
        'ip_address' => $ip
    ]);
}

// 记录登录日志
function logLogin($user_id, $ip_address, $user_agent) {
    $db = getDBConnection();
    
    $stmt = $db->prepare("INSERT INTO login_logs (user_id, login_time, ip_address, user_agent) 
                          VALUES (:user_id, NOW(), :ip_address, :user_agent)");
    
    $stmt->execute([
        'user_id' => $user_id,
        'ip_address' => $ip_address,
        'user_agent' => $user_agent
    ]);
} 