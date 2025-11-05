<?php
/**
 * 数据库配置文件
 */

// 数据库连接参数
define('DB_HOST', ENV_DB_HOST);
define('DB_NAME', ENV_DB_NAME);
define('DB_USER', ENV_DB_USER);
define('DB_PASS', ENV_DB_PASS);
define('DB_CHARSET', ENV_DB_CHARSET);

// 创建数据库连接
function getDBConnection() {
    static $connection = null;
    
    // 使用单例模式，减少数据库连接开销
    if ($connection !== null) {
        return $connection;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // 启用缓冲查询解决并发查询问题
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            // 暂时禁用持久连接以解决维护脚本问题
            PDO::ATTR_PERSISTENT => false,
        ];
        $connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $connection;
    } catch (PDOException $e) {
        // 记录错误日志但不暴露详细信息
        error_log("数据库连接失败: " . $e->getMessage());
        
        // 在生产环境中显示通用错误信息
        if (defined('ENV_ENVIRONMENT') && ENV_ENVIRONMENT === 'production') {
            die("系统暂时不可用，请稍后再试");
        } else {
            // 开发环境中可以显示详细错误
            die("数据库连接失败: " . $e->getMessage());
        }
    }
}