<?php
// 项目下载页面
$backup_file = 'project_backup_20250831_185812.tar.gz';

if (file_exists($backup_file)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_file . '"');
    header('Content-Length: ' . filesize($backup_file));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    readfile($backup_file);
    exit;
} else {
    echo "文件不存在: " . $backup_file;
}
?>