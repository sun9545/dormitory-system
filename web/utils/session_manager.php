<?php
/**
 * 统一Session管理类
 * 
 * 用于规范所有Session操作，解决session_start()和startSecureSession()混用的问题
 * 
 * @author AI Assistant
 * @date 2025-10-12
 */

class SessionManager {
    /**
     * Session是否已启动
     */
    private static $started = false;
    
    /**
     * 启动Session（统一入口）
     * 
     * 这是整个系统唯一的Session启动方法
     */
    public static function start() {
        // 如果已经启动，直接返回
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return true;
        }
        
        // 基础安全配置
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Lax');
        
        // 启动Session
        if (session_start()) {
            self::$started = true;
            return true;
        }
        
        return false;
    }
    
    /**
     * 检查是否登录
     * 
     * @return bool
     */
    public static function isLoggedIn() {
        self::start();
        
        // 检查必要的Session变量
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['last_activity'])) {
            return false;
        }
        
        // 检查Session超时（30分钟）
        $timeout = 1800; // 30分钟
        if (time() - $_SESSION['last_activity'] > $timeout) {
            self::destroy();
            return false;
        }
        
        // 更新最后活动时间
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * 检查是否管理员
     * 
     * @return bool
     */
    public static function isAdmin() {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    
    /**
     * 检查是否辅导员
     * 
     * @return bool
     */
    public static function isCounselor() {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        return isset($_SESSION['role']) && $_SESSION['role'] === 'counselor';
    }
    
    /**
     * 设置Session变量
     * 
     * @param string $key 键名
     * @param mixed $value 值
     */
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    /**
     * 获取Session变量
     * 
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get($key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * 删除Session变量
     * 
     * @param string $key 键名
     */
    public static function delete($key) {
        self::start();
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * 检查Session变量是否存在
     * 
     * @param string $key 键名
     * @return bool
     */
    public static function has($key) {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    /**
     * 销毁Session
     */
    public static function destroy() {
        self::start();
        
        // 清空Session数组
        $_SESSION = [];
        
        // 删除Session Cookie
        if (isset($_COOKIE[session_name()])) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        // 销毁Session
        session_destroy();
        self::$started = false;
    }
    
    /**
     * 重新生成Session ID（防止Session固定攻击）
     * 
     * @param bool $deleteOldSession 是否删除旧Session
     */
    public static function regenerateId($deleteOldSession = true) {
        self::start();
        session_regenerate_id($deleteOldSession);
    }
    
    /**
     * 获取Session ID
     * 
     * @return string
     */
    public static function getId() {
        self::start();
        return session_id();
    }
    
    /**
     * 获取所有Session数据
     * 
     * @return array
     */
    public static function all() {
        self::start();
        return $_SESSION;
    }
    
    /**
     * 清空所有Session数据（但不销毁Session）
     */
    public static function clear() {
        self::start();
        $_SESSION = [];
    }
    
    /**
     * Flash消息 - 设置一次性消息
     * 
     * @param string $key 键名
     * @param mixed $value 值
     */
    public static function flash($key, $value) {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }
    
    /**
     * 获取Flash消息（获取后自动删除）
     * 
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getFlash($key, $default = null) {
        self::start();
        
        if (isset($_SESSION['_flash'][$key])) {
            $value = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $value;
        }
        
        return $default;
    }
    
    /**
     * 检查是否有Flash消息
     * 
     * @param string $key 键名
     * @return bool
     */
    public static function hasFlash($key) {
        self::start();
        return isset($_SESSION['_flash'][$key]);
    }
}

