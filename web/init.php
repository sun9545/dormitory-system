<?php
/**
 * 系统初始化脚本
 * 用于创建必要的目录和初始化数据
 */

// 显示错误信息
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "开始初始化学生查寝系统...\n";

// 创建必要的目录
$directories = [
    'logs',
    'uploads'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        echo "创建目录: {$dir}\n";
        if (!mkdir($dir, 0755, true)) {
            echo "错误: 无法创建 {$dir} 目录\n";
        }
    } else {
        echo "目录已存在: {$dir}\n";
    }
}

// 检查数据库配置
require_once 'config/config.php';

try {
    // 测试数据库连接
    $db = getDBConnection();
    echo "数据库连接成功\n";
    
    // 检查表是否存在
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $requiredTables = [
        'students',
        'check_records',
        'users',
        'leave_batch',
        'operation_logs'
    ];
    
    $allTablesExist = true;
    foreach ($requiredTables as $table) {
        if (!in_array($table, $tables)) {
            $allTablesExist = false;
            echo "表不存在: {$table}\n";
        }
    }
    
    if ($allTablesExist) {
        echo "所有必要表已存在\n";
    } else {
        echo "需要导入数据库结构\n";
        echo "请运行: mysql -u 用户名 -p < database.sql\n";
    }
    
    // 检查管理员账号
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $adminCount = $stmt->fetchColumn();
    
    if ($adminCount == 0) {
        echo "创建默认管理员账号...\n";
        
        // 创建默认管理员账户
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role, name, phone) VALUES ('admin', :password, 'admin', '系统管理员', '13800138000')");
        $stmt->execute(['password' => $password]);
        
        echo "默认管理员账号已创建\n";
        echo "用户名: admin\n";
        echo "密码: admin123\n";
        echo "请登录后立即修改密码\n";
    } else {
        echo "管理员账号已存在\n";
    }
    
    echo "系统初始化完成\n";
    echo "请访问 " . BASE_URL . " 开始使用系统\n";
    
} catch (PDOException $e) {
    echo "数据库错误: " . $e->getMessage() . "\n";
    echo "请检查数据库配置 (config/database.php)\n";
}
?> 