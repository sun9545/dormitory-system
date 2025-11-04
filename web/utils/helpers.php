<?php
/**
 * 通用助手函数
 */

// 获取请求方法
function getRequestMethod() {
    return $_SERVER['REQUEST_METHOD'];
}

// 安全过滤输入
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// 验证必填字段
function validateRequired($fields, $data) {
    $missingFields = [];
    
    foreach ($fields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '') || $data[$field] === null) {
            $missingFields[] = $field;
            error_log("验证失败: 字段 '{$field}' 为空或不存在");
        }
    }
    
    if (!empty($missingFields)) {
        error_log("验证必填字段失败: " . implode(', ', $missingFields));
        return false;
    }
    
    return true;
}

// 显示错误信息
function displayError($message) {
    return '<div class="alert alert-danger">' . $message . '</div>';
}

// 显示成功信息
function displaySuccess($message) {
    return '<div class="alert alert-success">' . $message . '</div>';
}

// 获取状态类
function getStatusClass($status) {
    switch ($status) {
        case '在寝':
            return 'success';
        case '离寝':
            return 'danger';
        case '请假':
            return 'warning';
        default:
            return 'secondary';
    }
}

// 重定向
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

// 处理文件上传
function handleFileUpload($file, $allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $maxSize = 5242880) {
    // 检查文件错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '文件大小超过php.ini中的upload_max_filesize指令',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单中MAX_FILE_SIZE指令',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '无法写入磁盘',
            UPLOAD_ERR_EXTENSION => '文件上传被扩展程序停止',
        ];
        $errorMessage = isset($errorMessages[$file['error']]) ? $errorMessages[$file['error']] : '未知上传错误';
        
        error_log("文件上传失败: " . $errorMessage);
        return ['success' => false, 'message' => '文件上传失败，错误: ' . $errorMessage];
    }
    
    // 检查文件大小
    if ($file['size'] > $maxSize) {
        error_log("文件过大: " . $file['size'] . " > " . $maxSize);
        return ['success' => false, 'message' => '文件大小不能超过 ' . ($maxSize / 1048576) . 'MB'];
    }
    
    if ($file['size'] <= 0) {
        error_log("文件大小为零");
        return ['success' => false, 'message' => '无效的文件（大小为零）'];
    }
    
    // 获取文件扩展名
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension'] ?? '');
    
    // 白名单验证文件扩展名
    $allowedExtensions = ['csv', 'xls', 'xlsx'];
    if (!in_array($extension, $allowedExtensions)) {
        error_log("不允许的文件扩展名: " . $extension);
        return ['success' => false, 'message' => '请上传CSV或Excel文件（.csv, .xls, .xlsx）'];
    }
    
    // 检查MIME类型
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    // 允许的MIME类型
    $allowedMimeTypes = [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream', // 某些系统可能返回这个
        'application/csv',
        'text/comma-separated-values'
    ];
    
    if (!in_array($mimeType, $allowedMimeTypes)) {
        error_log("不允许的MIME类型: " . $mimeType);
        return ['success' => false, 'message' => '文件类型不允许，请上传CSV或Excel文件'];
    }
    
    // 文件名安全处理
    $originalName = $fileInfo['filename'];
    // 仅保留字母数字字符及下划线
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalName);
    // 确保文件名不为空
    if (empty($safeName)) {
        $safeName = 'file_' . time();
    }
    
    // 创建安全的唯一文件名
    $uniqueId = uniqid();
    $timestamp = time();
    $randomPart = bin2hex(random_bytes(4)); // 添加额外的随机性
    $filename = $safeName . '_' . $timestamp . '_' . $uniqueId . '_' . $randomPart . '.' . $extension;
    
    // 确保上传目录存在
    if (!is_dir(UPLOAD_PATH)) {
        if (!mkdir(UPLOAD_PATH, 0755, true)) {
            error_log("无法创建上传目录: " . UPLOAD_PATH);
            return ['success' => false, 'message' => '服务器错误：无法创建上传目录'];
        }
    }
    
    $uploadPath = UPLOAD_PATH . '/' . $filename;
    
    // 移动文件
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        // 设置更安全的文件权限 (644 = 所有者读写，组和其他用户只读)
        chmod($uploadPath, 0644);
        
        // 记录成功上传
        error_log("文件上传成功: " . $uploadPath);
    
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $uploadPath,
            'extension' => $extension,
            'original_name' => $file['name'],
            'mime_type' => $mimeType
        ];
    } else {
        error_log("文件移动失败: 从 " . $file['tmp_name'] . " 到 " . $uploadPath);
        return ['success' => false, 'message' => '文件上传失败：无法保存文件'];
    }
}

// 生成分页链接
function generatePagination($currentPage, $totalPages, $baseUrl) {
    // 获取当前的查询参数
    $queryParams = $_GET;
    
    // 移除page参数，我们将在链接中添加它
    if (isset($queryParams['page'])) {
        unset($queryParams['page']);
    }
    
    // 构建查询字符串
    $queryString = '';
    if (!empty($queryParams)) {
        $queryString = '?' . http_build_query($queryParams) . '&page=';
    } else {
        $queryString = '?page=';
    }
    
    $html = '<nav aria-label="分页导航"><ul class="pagination pagination-md justify-content-center">';
    
    // 上一页
    if ($currentPage > 1) {
        $html .= '<li class="page-item">
                    <a class="page-link" href="' . $baseUrl . $queryString . ($currentPage - 1) . '" aria-label="上一页">
                        <span aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
                    </a>
                  </li>';
    } else {
        $html .= '<li class="page-item disabled">
                    <span class="page-link">
                        <i class="bi bi-chevron-left"></i>
                    </span>
                  </li>';
    }
    
    // 页码
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $queryString . '1">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $queryString . $i . '">' . $i . '</a></li>';
        }
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $queryString . $totalPages . '">' . $totalPages . '</a></li>';
    }
    
    // 下一页
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item">
                    <a class="page-link" href="' . $baseUrl . $queryString . ($currentPage + 1) . '" aria-label="下一页">
                        <span aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                    </a>
                  </li>';
    } else {
        $html .= '<li class="page-item disabled">
                    <span class="page-link">
                        <i class="bi bi-chevron-right"></i>
                    </span>
                  </li>';
    }
    
    $html .= '</ul></nav>';
    
    return $html;
}

// 格式化日期时间
function formatDateTime($datetime, $format = 'Y-m-d H:i:s') {
    if (empty($datetime)) {
        return '';
    }
    
    try {
        $date = new DateTime($datetime);
        return $date->format($format);
    } catch (Exception $e) {
        error_log("日期格式化错误: " . $e->getMessage());
        return $datetime; // 返回原始值
    }
}

// 导出数据为CSV
function exportCSV($filename, $data, $headers) {
    // 安全处理文件名
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($filename, PATHINFO_FILENAME)) . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // 添加BOM以正确显示中文
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // 写入表头
    fputcsv($output, $headers);
    
    // 写入数据行
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// 创建日志目录并记录系统日志
function logSystemMessage($message, $level = 'info') {
    $logDir = ROOT_PATH . '/logs';
    
    // 确保日志目录存在
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("无法创建日志目录: " . $logDir);
            return false;
        }
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'Guest';
    
    $logEntry = "[{$timestamp}] [{$level}] [IP: {$ip}] [User: {$userId}] [URI: {$uri}] {$message}\n";
    
    $logFile = $logDir . '/' . date('Y-m-d') . '_system.log';
    
    return file_put_contents($logFile, $logEntry, FILE_APPEND) !== false;
} 