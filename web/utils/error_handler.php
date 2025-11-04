<?php
/**
 * 全局错误处理器
 * 提供统一的错误处理和日志记录
 */

// 防止直接访问
if (!defined('ROOT_PATH')) {
    http_response_code(403);
    exit('Direct access denied');
}

/**
 * 自定义错误处理函数
 */
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    // 检查错误报告级别
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $errorTypes = [
        E_ERROR => 'FATAL ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE ERROR',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE ERROR',
        E_CORE_WARNING => 'CORE WARNING',
        E_COMPILE_ERROR => 'COMPILE ERROR',
        E_COMPILE_WARNING => 'COMPILE WARNING',
        E_USER_ERROR => 'USER ERROR',
        E_USER_WARNING => 'USER WARNING',
        E_USER_NOTICE => 'USER NOTICE',
        E_STRICT => 'STRICT NOTICE',
        E_RECOVERABLE_ERROR => 'RECOVERABLE ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER DEPRECATED'
    ];
    
    $errorType = $errorTypes[$errno] ?? 'UNKNOWN ERROR';
    
    // 格式化错误信息
    $timestamp = date('Y-m-d H:i:s');
    $errorMessage = "[{$timestamp}] {$errorType}: {$errstr} in {$errfile} on line {$errline}";
    
    // 记录到错误日志
    error_log($errorMessage);
    
    // 在开发环境显示详细错误
    if (defined('ENV_ENVIRONMENT') && ENV_ENVIRONMENT === 'development') {
        echo "<div style='background:#ffe6e6;color:#d8000c;padding:10px;margin:10px;border:1px solid #d8000c;border-radius:5px;'>";
        echo "<strong>{$errorType}:</strong> {$errstr}<br>";
        echo "<strong>File:</strong> {$errfile}<br>";
        echo "<strong>Line:</strong> {$errline}<br>";
        echo "</div>";
    }
    
    // 对于致命错误，重定向到错误页面
    if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            if (file_exists(ROOT_PATH . '/500.html')) {
                readfile(ROOT_PATH . '/500.html');
            } else {
                echo 'Internal Server Error';
            }
        }
        exit();
    }
    
    return true;
}

/**
 * 自定义异常处理函数
 */
function customExceptionHandler($exception) {
    $timestamp = date('Y-m-d H:i:s');
    $errorMessage = "[{$timestamp}] UNCAUGHT EXCEPTION: " . $exception->getMessage() . 
                   " in " . $exception->getFile() . " on line " . $exception->getLine();
    
    // 记录异常堆栈
    $errorMessage .= "\nStack trace:\n" . $exception->getTraceAsString();
    
    error_log($errorMessage);
    
    // 在开发环境显示异常详情
    if (defined('ENV_ENVIRONMENT') && ENV_ENVIRONMENT === 'development') {
        echo "<div style='background:#ffe6e6;color:#d8000c;padding:15px;margin:10px;border:1px solid #d8000c;border-radius:5px;font-family:monospace;'>";
        echo "<h3>Uncaught Exception</h3>";
        echo "<strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "<br>";
        echo "<strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "<br>";
        echo "<strong>Line:</strong> " . $exception->getLine() . "<br>";
        echo "<strong>Stack Trace:</strong><br>";
        echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        echo "</div>";
    } else {
        // 生产环境显示友好错误页面
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            if (file_exists(ROOT_PATH . '/500.html')) {
                readfile(ROOT_PATH . '/500.html');
            } else {
                echo 'Internal Server Error';
            }
        }
    }
    
    exit();
}

/**
 * 关闭时的错误处理
 */
function shutdownErrorHandler() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $timestamp = date('Y-m-d H:i:s');
        $errorMessage = "[{$timestamp}] SHUTDOWN ERROR: {$error['message']} in {$error['file']} on line {$error['line']}";
        error_log($errorMessage);
        
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            if (file_exists(ROOT_PATH . '/500.html')) {
                readfile(ROOT_PATH . '/500.html');
            } else {
                echo 'Internal Server Error';
            }
        }
    }
}

/**
 * 安全的变量获取函数
 */
function safeGetVar($array, $key, $default = null, $filter = FILTER_DEFAULT) {
    if (!isset($array[$key])) {
        return $default;
    }
    
    $value = $array[$key];
    
    // 应用过滤器
    if ($filter !== FILTER_DEFAULT) {
        $value = filter_var($value, $filter);
        if ($value === false) {
            return $default;
        }
    }
    
    return $value;
}

/**
 * 安全的数据库查询执行
 */
function safeDbQuery($db, $query, $params = []) {
    try {
        $stmt = $db->prepare($query);
        
        if (!$stmt) {
            throw new Exception('数据库查询准备失败: ' . implode(', ', $db->errorInfo()));
        }
        
        $result = $stmt->execute($params);
        
        if (!$result) {
            throw new Exception('数据库查询执行失败: ' . implode(', ', $stmt->errorInfo()));
        }
        
        return $stmt;
    } catch (PDOException $e) {
        error_log('Database Error: ' . $e->getMessage());
        throw new Exception('数据库操作失败，请稍后重试');
    }
}

/**
 * 记录调试信息
 */
function debugLog($message, $data = null) {
    if (defined('ENV_ENVIRONMENT') && ENV_ENVIRONMENT === 'development') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] DEBUG: {$message}";
        
        if ($data !== null) {
            $logEntry .= "\nData: " . print_r($data, true);
        }
        
        $logEntry .= "\n";
        
        $debugLogFile = ROOT_PATH . '/logs/debug.log';
        file_put_contents($debugLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * 验证和清理输入数组
 */
function validateInputArray($input, $rules) {
    $cleaned = [];
    $errors = [];
    
    foreach ($rules as $field => $rule) {
        $value = $input[$field] ?? null;
        
        // 检查必填字段
        if (isset($rule['required']) && $rule['required'] && empty($value)) {
            $errors[$field] = $rule['error_message'] ?? "字段 {$field} 是必填的";
            continue;
        }
        
        // 应用过滤器
        if (isset($rule['filter']) && $value !== null) {
            $filtered = filter_var($value, $rule['filter']);
            if ($filtered === false) {
                $errors[$field] = $rule['error_message'] ?? "字段 {$field} 格式不正确";
                continue;
            }
            $value = $filtered;
        }
        
        // 应用自定义验证
        if (isset($rule['validator']) && is_callable($rule['validator'])) {
            $validationResult = $rule['validator']($value);
            if ($validationResult !== true) {
                $errors[$field] = $validationResult;
                continue;
            }
        }
        
        $cleaned[$field] = $value;
    }
    
    return ['data' => $cleaned, 'errors' => $errors];
}

// 设置错误处理器
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');
register_shutdown_function('shutdownErrorHandler');

// 设置错误报告级别
if (defined('ENV_ENVIRONMENT')) {
    if (ENV_ENVIRONMENT === 'development') {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    } else {
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
        ini_set('display_errors', 0);
    }
}
?>