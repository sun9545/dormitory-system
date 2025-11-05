<?php
/**
 * 全局配置文件
 */

// 加载环境配置
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
} else {
    die('环境配置文件不存在，请根据env.example.php创建env.php文件');
}

// 基本设置
define('SITE_NAME', ENV_SITE_NAME);
define('BASE_URL', ENV_BASE_URL); 
define('TIMEZONE', ENV_TIMEZONE);

// 设置时区
date_default_timezone_set(TIMEZONE);

// 系统路径
define('ROOT_PATH', dirname(__DIR__));
define('TEMPLATE_PATH', ROOT_PATH . '/templates');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');

// 会话配置已移至 session_init.php
// 在需要启动会话的文件中包含该文件

// 错误报告设置
ini_set('display_errors', ENV_DISPLAY_ERRORS); // 生产环境设为0
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/logs/error.log');

// 安全设置
define('CSRF_TOKEN_SECRET', ENV_CSRF_TOKEN);
define('API_TOKEN', ENV_API_TOKEN); 

// 功能设置
define('PAGINATION_LIMIT', 20); // 每页显示记录数
define('SESSION_TIMEOUT', ENV_SESSION_TIMEOUT); // 会话超时时间（秒）

// 导入数据库配置
require_once 'database.php';

// 导入缓存配置
require_once 'cache_config.php';

// 导入安全头部配置
require_once 'security_headers.php';

// 导入错误处理器
require_once ROOT_PATH . '/utils/error_handler.php';
?>
