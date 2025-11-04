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

// 获取站点所有可能的路径
$possiblePaths = [
    '/', 
    '/admin/', 
    dirname($_SERVER['REQUEST_URI']), 
    parse_url(BASE_URL, PHP_URL_PATH),
    '/www/', 
    '/wwwroot/', 
    '/www/wwwroot/',
    ''
];

$cookieNames = [session_name(), 'PHPSESSID', 'remember', 'login', 'user', 'auth', 'token', 'session'];
$domains = ['', '.'.parse_url(BASE_URL, PHP_URL_HOST), parse_url(BASE_URL, PHP_URL_HOST)];

// 对所有可能的路径和cookie名称进行交叉清除
foreach ($cookieNames as $name) {
    foreach ($possiblePaths as $path) {
        foreach ($domains as $domain) {
            // 尝试用多种组合清除cookie
            setcookie($name, '', time() - 3600, $path, $domain);
            setcookie($name, '', time() - 3600, $path, $domain, false, true);
            error_log("尝试删除cookie: {$name}, 路径: {$path}, 域: {$domain}");
        }
    }
}

// 清除全局变量
$_SESSION = [];
session_unset();
session_destroy();

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