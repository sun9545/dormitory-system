<?php
/**
 * 密码策略管理工具
 * 负责密码强度验证、密码历史记录、密码过期管理等
 */

// 密码策略配置
define('PASSWORD_MIN_LENGTH', 8);               // 密码最小长度
define('PASSWORD_REQUIRE_UPPERCASE', true);     // 是否要求大写字母
define('PASSWORD_REQUIRE_LOWERCASE', true);     // 是否要求小写字母
define('PASSWORD_REQUIRE_NUMBER', true);        // 是否要求数字
define('PASSWORD_REQUIRE_SPECIAL', true);       // 是否要求特殊字符
define('PASSWORD_HISTORY_COUNT', 3);            // 密码历史记录数量（禁止重复使用最近N次密码）
define('PASSWORD_EXPIRY_DAYS', 90);             // 密码过期天数
define('MAX_LOGIN_ATTEMPTS', 5);                // 最大登录尝试次数（普通用户）
define('MAX_LOGIN_ATTEMPTS_ADMIN', 10);         // 最大登录尝试次数（管理员用户）
define('LOCKOUT_TIME_MINUTES', 30);             // 锁定时间（分钟）

/**
 * 检查密码强度
 * 
 * @param string $password 密码
 * @return array 检查结果 ['valid' => bool, 'message' => string]
 */
function checkPasswordStrength($password) {
    $result = ['valid' => true, 'message' => '密码符合要求'];
    
    // 检查密码长度
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return ['valid' => false, 'message' => '密码长度不能少于' . PASSWORD_MIN_LENGTH . '个字符'];
    }
    
    // 检查是否包含大写字母
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含至少一个大写字母'];
    }
    
    // 检查是否包含小写字母
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含至少一个小写字母'];
    }
    
    // 检查是否包含数字
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含至少一个数字'];
    }
    
    // 检查是否包含特殊字符
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含至少一个特殊字符'];
    }
    
    return $result;
}

/**
 * 计算密码强度等级
 * 
 * @param string $password 密码
 * @return int 强度等级 (1-4: 弱, 中, 强, 非常强)
 */
function calculatePasswordStrength($password) {
    $strength = 0;
    
    // 基础长度检查
    if (strlen($password) >= PASSWORD_MIN_LENGTH) {
        $strength += 1;
    }
    
    // 复杂度检查
    if (preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password)) {
        $strength += 1;
    }
    
    if (preg_match('/[0-9]/', $password)) {
        $strength += 1;
    }
    
    if (preg_match('/[^A-Za-z0-9]/', $password)) {
        $strength += 1;
    }
    
    return $strength;
}

/**
 * 检查密码是否在用户历史记录中
 * 
 * @param int $userId 用户ID
 * @param string $password 明文密码
 * @return bool 是否在历史记录中
 */
function isPasswordInHistory($userId, $password) {
    $db = getDBConnection();
    
    // 获取用户密码历史
    $stmt = $db->prepare("
        SELECT password_hash 
        FROM password_history 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT :history_count
    ");
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':history_count', PASSWORD_HISTORY_COUNT, PDO::PARAM_INT);
    $stmt->execute();
    
    $history = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 检查密码是否在历史中
    foreach ($history as $hashedPassword) {
        if (password_verify($password, $hashedPassword)) {
            return true;
        }
    }
    
    return false;
}

/**
 * 添加密码到历史记录
 * 
 * @param int $userId 用户ID
 * @param string $hashedPassword 哈希后的密码
 * @return bool 是否添加成功
 */
function addPasswordToHistory($userId, $hashedPassword) {
    $db = getDBConnection();
    
    try {
        $stmt = $db->prepare("
            INSERT INTO password_history (user_id, password_hash)
            VALUES (:user_id, :password_hash)
        ");
        
        return $stmt->execute([
            'user_id' => $userId,
            'password_hash' => $hashedPassword
        ]);
    } catch (Exception $e) {
        error_log("添加密码历史失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 更新用户密码
 * 
 * @param int $userId 用户ID
 * @param string $newPassword 新明文密码
 * @return array 更新结果 ['success' => bool, 'message' => string]
 */
function updateUserPassword($userId, $newPassword) {
    $db = getDBConnection();
    
    try {
        // 简化的密码长度检查（6位最少）
        if (strlen($newPassword) < 6) {
            return ['valid' => false, 'message' => '密码长度至少需要6位字符'];
        }
        
        // 生成密码哈希
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // 计算密码过期时间
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . PASSWORD_EXPIRY_DAYS . ' days'));
        
        // 更新用户密码
        $stmt = $db->prepare("
            UPDATE users 
            SET password = :password, 
                password_changed_at = NOW(),
                password_expires_at = :expires_at
            WHERE id = :user_id
        ");
        
        $updateResult = $stmt->execute([
            'password' => $hashedPassword,
            'expires_at' => $expiresAt,
            'user_id' => $userId
        ]);
        
        if (!$updateResult) {
            return ['success' => false, 'message' => '密码更新失败'];
        }
        
        // 添加到密码历史
        addPasswordToHistory($userId, $hashedPassword);
        
        return ['success' => true, 'message' => '密码更新成功'];
        
    } catch (Exception $e) {
        error_log("更新密码失败: " . $e->getMessage());
        return ['success' => false, 'message' => '系统错误，请稍后再试'];
    }
}

/**
 * 检查用户密码是否过期
 * 
 * @param int $userId 用户ID
 * @return bool 是否已过期
 */
function isPasswordExpired($userId) {
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT password_expires_at
        FROM users
        WHERE id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    
    $expiryDate = $stmt->fetchColumn();
    
    // 如果没有设置过期时间，认为没有过期
    if (!$expiryDate) {
        return false;
    }
    
    // 比较当前时间和过期时间
    return strtotime($expiryDate) < time();
}

/**
 * 检查账户是否被锁定
 * 
 * @param string $username 用户名
 * @return array 锁定状态 ['locked' => bool, 'message' => string]
 */
function checkAccountLocked($username) {
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT login_attempts, locked_until, status
        FROM users
        WHERE username = :username
    ");
    $stmt->execute(['username' => $username]);
    
    $user = $stmt->fetch();
    
    if (!$user) {
        return ['locked' => false, 'message' => ''];
    }
    
    // 检查账号状态
    if ($user['status'] === 'locked' || $user['status'] === 'inactive') {
        return ['locked' => true, 'message' => '账号已被锁定，请联系管理员'];
    }
    
    // 检查临时锁定
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $remainingTime = ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['locked' => true, 'message' => "账号暂时被锁定，请在 {$remainingTime} 分钟后再试"];
    }
    
    return ['locked' => false, 'message' => ''];
}

/**
 * 记录登录尝试
 * 
 * @param string $username 用户名
 * @param bool $success 是否成功
 * @return void
 */
function recordLoginAttempt($username, $success) {
    $db = getDBConnection();
    
    if ($success) {
        // 登录成功，重置计数器
        $stmt = $db->prepare("
            UPDATE users
            SET login_attempts = 0,
                locked_until = NULL
            WHERE username = :username
        ");
    } else {
        // 登录失败，增加计数
        $stmt = $db->prepare("
            UPDATE users
            SET login_attempts = login_attempts + 1
            WHERE username = :username
        ");
        $stmt->execute(['username' => $username]);
        
        // 检查是否需要锁定账户
        $stmt = $db->prepare("
            SELECT login_attempts, role
            FROM users
            WHERE username = :username
        ");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        $attempts = $user['login_attempts'];
        $role = $user['role'];
        
        // 根据用户角色确定最大尝试次数
        $maxAttempts = ($role === 'admin') ? MAX_LOGIN_ATTEMPTS_ADMIN : MAX_LOGIN_ATTEMPTS;
        
        if ($attempts >= $maxAttempts) {
            // 锁定账户
            $lockedUntil = date('Y-m-d H:i:s', strtotime('+' . LOCKOUT_TIME_MINUTES . ' minutes'));
            
            $stmt = $db->prepare("
                UPDATE users
                SET locked_until = :locked_until
                WHERE username = :username
            ");
            $stmt->execute([
                'locked_until' => $lockedUntil,
                'username' => $username
            ]);
        }
    }
}