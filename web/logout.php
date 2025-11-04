<?php 
/**
 * 注销页面
 */
require_once 'config/config.php';
require_once 'utils/helpers.php'; // 先加载helpers，提供logSystemMessage函数
require_once 'utils/auth.php';    // 然后加载auth，它可能会调用logSystemMessage

// 获取当前会话ID用于日志记录
$oldSessionId = session_id();
error_log("注销页面开始处理 - 初始会话ID: " . $oldSessionId);

// 设置防止缓存的头
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

// 执行注销操作
logout();

// ========== 精准清除 Cookie ==========
// 获取 session cookie 的实际参数
$sessionParams = session_get_cookie_params();

// 1. 清除 session cookie（使用实际参数）
setcookie(
    session_name(),
    '',
    time() - 3600,
    $sessionParams['path'],
    $sessionParams['domain'],
    $sessionParams['secure'],
    $sessionParams['httponly']
);
error_log("清除 session cookie: " . session_name());

// 2. 清除常用的应用 cookie（使用根路径）
$appCookies = ['PHPSESSID', 'remember', 'login', 'user', 'auth', 'token'];
foreach ($appCookies as $cookieName) {
    setcookie($cookieName, '', time() - 3600, '/', '', false, true);
    error_log("清除应用 cookie: {$cookieName}");
}

// ========== 清除 Session ==========
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}


// 确保浏览器强制刷新而不是加载缓存
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

// 记录日志
logSystemMessage("注销完成 - 原会话ID: {$oldSessionId}", 'info');

// 重定向到登录页面，添加随机数防止缓存
$randomParam = md5(uniqid(rand(), true));
error_log("重定向到登录页面，随机参数: {$randomParam}");
header('Location: ' . BASE_URL . '/login.php?logout=1&nocache=' . $randomParam);
exit;
?> 