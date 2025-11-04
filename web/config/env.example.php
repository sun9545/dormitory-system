<?php
/**
 * 环境配置文件示例
 * 
 * 📋 安装步骤：
 * 1. 复制此文件并重命名为 env.php
 *    命令：cp config/env.example.php config/env.php
 * 
 * 2. 修改下面的配置项为您的实际值
 * 
 * 3. 生成安全Token（Linux/Mac）：
 *    openssl rand -hex 32
 * 
 * ⚠️ 重要提示：
 * - 请勿将 env.php 提交到版本控制系统
 * - 请勿在生产环境使用示例值
 * - Token 必须保持唯一性和随机性
 */

// ==================== 网站基本设置 ====================
define('ENV_SITE_NAME', '学生查寝系统');
define('ENV_BASE_URL', 'http://localhost'); // ⚠️ 改为您的域名或IP，如：http://yourdomain.com
define('ENV_TIMEZONE', 'Asia/Shanghai');

// ==================== 数据库配置 ====================
define('ENV_DB_HOST', 'localhost');
define('ENV_DB_NAME', 'your_database_name');           // ⚠️ 修改为您的数据库名
define('ENV_DB_USER', 'your_database_user');           // ⚠️ 修改为您的数据库用户名
define('ENV_DB_PASS', 'your_database_password');       // ⚠️ 修改为您的数据库密码
define('ENV_DB_CHARSET', 'utf8mb4');

// ==================== 安全设置（重要！必须修改）====================
// CSRF Token - 用于防止跨站请求伪造攻击
// ⚠️ 生成方法：openssl rand -hex 32
define('ENV_CSRF_TOKEN', 'PLEASE_GENERATE_YOUR_OWN_RANDOM_TOKEN_HERE');

define('ENV_COOKIE_SECURE', 0);  // 使用HTTPS时改为1
define('ENV_SESSION_TIMEOUT', 1800); // 会话超时时间（秒），默认30分钟

// ==================== 环境设置 ====================
define('ENV_ENVIRONMENT', 'production'); // development 或 production
define('ENV_DISPLAY_ERRORS', 0); // 生产环境建议设为0

// ==================== API安全设置（重要！必须修改）====================
define('ENV_API_TOKEN_REQUIRED', true); // 是否要求API使用令牌验证
// API Token - 用于ESP32设备与服务器通信的认证
// ⚠️ 生成方法：openssl rand -hex 32
// ⚠️ 此Token需要同步配置到ESP32设备的 esp32_config.h 文件中
define('ENV_API_TOKEN', 'PLEASE_GENERATE_YOUR_OWN_API_TOKEN_HERE');
?> 