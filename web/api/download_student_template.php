<?php
/**
 * 独立的学生模板下载脚本
 * 避免头信息冲突问题
 */

// 设置安全头
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// 禁用错误显示和输出缓冲
ini_set('display_errors', 0);
error_reporting(0);

// 清理所有输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

// 手动生成学生模板CSV内容
try {
    // 生成CSV内容
    $csvContent = "\xEF\xBB\xBF"; // UTF-8 BOM
    $csvContent .= "学号,姓名,性别,班级,楼栋,区域,楼层,宿舍号,床号,辅导员,辅导员电话\n";
    
    // 添加示例数据
    $csvContent .= '"202001001","张三","男","计算机科学与技术1班","1","A","1","101","1","李老师","13800138000"' . "\n";
    
    // 直接输出CSV文件
    $filename = 'student_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csvContent));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // 输出CSV内容
    echo $csvContent;
    exit;
    
} catch (Exception $e) {
    error_log("学生模板下载错误: " . $e->getMessage());
    http_response_code(500);
    exit('下载失败: ' . $e->getMessage());
}
