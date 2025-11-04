<?php
/**
 * 文件下载处理
 */
require_once 'config/config.php';
require_once 'utils/auth.php';
require_once 'utils/helpers.php';

// 检查是否登录
if (!isLoggedIn()) {
    redirect(BASE_URL . '/login.php');
}

// 检查文件参数
if (!isset($_GET['file']) || empty($_GET['file'])) {
    die('文件参数缺失');
}

// 获取文件名并清理
$filename = basename($_GET['file']);

// 构建完整路径
$filepath = UPLOAD_PATH . '/' . $filename;

// 检查文件是否存在
if (!file_exists($filepath)) {
    die('文件不存在');
}

// 检查文件是否在允许的目录中
$realpath = realpath($filepath);
$uploadRealpath = realpath(UPLOAD_PATH);

if (strpos($realpath, $uploadRealpath) !== 0) {
    die('无权访问该文件');
}

// 获取文件扩展名
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

// 只允许下载CSV文件
if ($extension !== 'csv') {
    die('不支持的文件类型');
}

// 设置适当的头信息
header('Content-Description: File Transfer');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filepath));

// 清空输出缓冲
ob_clean();
flush();

// 输出文件内容
readfile($filepath);

// 删除临时文件
unlink($filepath);

exit; 