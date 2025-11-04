<?php
/**
 * 获取验证码图片（API方式）
 */

require_once __DIR__ . '/../utils/session_manager.php';

// 启动session（使用统一管理器）
SessionManager::start();

// 生成验证码
$characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$captchaCode = '';
for ($i = 0; $i < 4; $i++) {
    $captchaCode .= $characters[rand(0, strlen($characters) - 1)];
}

// 保存到session
SessionManager::set('captcha_code', $captchaCode);
SessionManager::set('captcha_time', time());

// 记录日志（直接写文件）
$logMsg = date('[Y-m-d H:i:s] ') . "验证码生成 - Code: {$captchaCode}, Session ID: " . SessionManager::getId() . "\n";
file_put_contents(__DIR__ . '/../logs/captcha_debug.log', $logMsg, FILE_APPEND);

// 创建图片
$image = imagecreatetruecolor(120, 40);

// 颜色
$bgColor = imagecolorallocate($image, 240, 240, 240);
$textColor = imagecolorallocate($image, 50, 50, 50);
$lineColor = imagecolorallocate($image, 200, 200, 200);

// 填充背景
imagefilledrectangle($image, 0, 0, 120, 40, $bgColor);

// 干扰线
for ($i = 0; $i < 5; $i++) {
    imageline($image, rand(0, 120), rand(0, 40), rand(0, 120), rand(0, 40), $lineColor);
}

// 干扰点
for ($i = 0; $i < 50; $i++) {
    imagesetpixel($image, rand(0, 120), rand(0, 40), $lineColor);
}

// 写入验证码
$x = 15;
$charWidth = 90 / 4;
for ($i = 0; $i < 4; $i++) {
    $y = rand(20, 28);
    imagestring($image, 5, $x, $y, $captchaCode[$i], $textColor);
    $x += $charWidth;
}

// 输出图片
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

imagepng($image);
imagedestroy($image);
?>

