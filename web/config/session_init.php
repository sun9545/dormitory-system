<?php
/**
 * 会话初始化配置
 * 必须在任何会话操作之前调用
 */

// 检查会话是否已启动
if (session_status() === PHP_SESSION_NONE) {
    // 设置安全的会话选项
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_trans_sid', 0);
    
    // 根据HTTPS设置安全cookie
    if (defined('ENV_COOKIE_SECURE')) {
        ini_set('session.cookie_secure', ENV_COOKIE_SECURE);
    }
    
    // 设置会话名称（可选）
    session_name('STUDENT_DORM_SESSION');
    
    // 设置会话生命周期
    if (defined('SESSION_TIMEOUT')) {
        ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
        session_set_cookie_params(SESSION_TIMEOUT);
    }
}