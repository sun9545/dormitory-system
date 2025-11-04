<?php
/**
 * 验证码工具类
 */

class Captcha {
    /**
     * 生成验证码图片
     * @param int $width 图片宽度
     * @param int $height 图片高度
     * @param int $length 验证码长度
     * @return void
     */
    public static function generate($width = 120, $height = 40, $length = 4) {
        // 确保session已启动
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // 生成验证码字符串
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // 排除易混淆字符
        $captchaCode = '';
        for ($i = 0; $i < $length; $i++) {
            $captchaCode .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        // 存储到session
        $_SESSION['captcha_code'] = $captchaCode;
        $_SESSION['captcha_time'] = time();
        
        // 记录日志
        error_log("验证码生成 - Code: {$captchaCode}, Session ID: " . session_id());
        
        // 创建图片
        $image = imagecreatetruecolor($width, $height);
        
        // 设置颜色
        $bgColor = imagecolorallocate($image, 240, 240, 240);
        $textColor = imagecolorallocate($image, 50, 50, 50);
        $lineColor = imagecolorallocate($image, 200, 200, 200);
        
        // 填充背景
        imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);
        
        // 添加干扰线
        for ($i = 0; $i < 5; $i++) {
            imageline(
                $image,
                rand(0, $width),
                rand(0, $height),
                rand(0, $width),
                rand(0, $height),
                $lineColor
            );
        }
        
        // 添加干扰点
        for ($i = 0; $i < 50; $i++) {
            imagesetpixel($image, rand(0, $width), rand(0, $height), $lineColor);
        }
        
        // 写入验证码文字（使用内置字体）
        $x = 15;
        $charWidth = ($width - 30) / $length;
        
        for ($i = 0; $i < $length; $i++) {
            $y = rand($height * 0.5, $height * 0.7);
            
            imagestring(
                $image,
                5, // 字体大小（1-5）
                $x,
                $y,
                $captchaCode[$i],
                $textColor
            );
            
            $x += $charWidth;
        }
        
        // 输出图片
        header('Content-Type: image/png');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        imagepng($image);
        imagedestroy($image);
    }
    
    /**
     * 验证验证码
     * @param string $inputCode 用户输入的验证码
     * @param int $expireTime 过期时间（秒）
     * @return bool
     */
    public static function verify($inputCode, $expireTime = 300) {
        // 启动session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // 检查是否存在验证码
        if (!isset($_SESSION['captcha_code']) || !isset($_SESSION['captcha_time'])) {
            return false;
        }
        
        // 检查是否过期
        if (time() - $_SESSION['captcha_time'] > $expireTime) {
            unset($_SESSION['captcha_code']);
            unset($_SESSION['captcha_time']);
            return false;
        }
        
        // 验证码比较（不区分大小写）
        $result = (strtoupper($inputCode) === strtoupper($_SESSION['captcha_code']));
        
        // 验证后删除（一次性使用）
        if ($result) {
            unset($_SESSION['captcha_code']);
            unset($_SESSION['captcha_time']);
        }
        
        return $result;
    }
    
    /**
     * 清除验证码
     */
    public static function clear() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        unset($_SESSION['captcha_code']);
        unset($_SESSION['captcha_time']);
    }
}

// 如果直接访问此文件，生成验证码图片
if (basename($_SERVER['PHP_SELF']) === 'captcha.php' || 
    (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'captcha.php') !== false)) {
    
    // 启动session（使用已有的session ID）
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    Captcha::generate();
}
?>

