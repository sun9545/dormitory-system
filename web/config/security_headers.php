<?php
/**
 * 安全头部设置
 * 在每个页面加载时应用安全策略
 */

// 防止直接访问
if (!defined('ROOT_PATH')) {
    http_response_code(403);
    exit('Direct access denied');
}

/**
 * 设置安全HTTP头部
 */
function setSecurityHeaders() {
    // 防止MIME类型嗅探
    header('X-Content-Type-Options: nosniff');
    
    // 防止点击劫持
    header('X-Frame-Options: SAMEORIGIN');
    
    // XSS保护
    header('X-XSS-Protection: 1; mode=block');
    
    // 引用策略
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // 移除服务器信息泄露
    header_remove('X-Powered-By');
    
    // 内容安全策略（根据需要调整）
    $csp = "default-src 'self' https:; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; " .
           "style-src 'self' 'unsafe-inline' https:; " .
           "font-src 'self' https: data:; " .
           "img-src 'self' data: https:; " .
           "connect-src 'self' https:; " .
           "worker-src 'self' blob:; " .
           "child-src 'self'";
    
    header('Content-Security-Policy: ' . $csp);
    
    // 如果使用HTTPS，启用HSTS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * 安全检查用户输入
 */
function secureInput($input) {
    if (is_array($input)) {
        return array_map('secureInput', $input);
    }
    
    // 移除null字节
    $input = str_replace("\0", '', $input);
    
    // 基本清理
    $input = trim($input);
    $input = stripslashes($input);
    
    return $input;
}

/**
 * 验证文件上传安全性
 */
function validateFileUpload($file) {
    $errors = [];
    
    // 检查文件是否存在错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = '文件上传失败';
        return $errors;
    }
    
    // 获取文件信息
    $filename = $file['name'];
    $filesize = $file['size'];
    $filetype = $file['type'];
    $tmpname = $file['tmp_name'];
    
    // 检查文件扩展名
    $allowedExtensions = ['csv', 'xls', 'xlsx'];
    $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        $errors[] = '不允许的文件类型';
    }
    
    // 检查MIME类型
    $allowedMimeTypes = [
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMimeType = finfo_file($finfo, $tmpname);
    finfo_close($finfo);
    
    if (!in_array($detectedMimeType, $allowedMimeTypes)) {
        $errors[] = '文件类型不匹配';
    }
    
    // 检查文件大小（10MB限制）
    if ($filesize > 10 * 1024 * 1024) {
        $errors[] = '文件大小超过限制(10MB)';
    }
    
    // 检查文件内容（基本检查）
    if ($filesize === 0) {
        $errors[] = '文件为空';
    }
    
    return $errors;
}

/**
 * 生成安全的随机字符串
 */
function generateSecureToken($length = 32) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length / 2));
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($length / 2));
    } else {
        // 回退方案（不够安全，仅用于旧版本PHP）
        return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $length)), 0, $length);
    }
}

/**
 * 记录安全事件
 */
function logSecurityEvent($event, $details = '') {
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $logEntry = "[{$timestamp}] SECURITY: {$event} | IP: {$ip} | UA: {$userAgent}";
    if ($details) {
        $logEntry .= " | Details: {$details}";
    }
    $logEntry .= "\n";
    
    $logFile = ROOT_PATH . '/logs/security.log';
    
    // 确保日志目录存在
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// 自动应用安全头部
setSecurityHeaders();
?>