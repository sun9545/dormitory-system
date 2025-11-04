<?php
/**
 * 页面头部模板
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../utils/auth.php';

// 检查用户登录状态
requireLogin();

// 获取当前用户信息
$currentUser = getCurrentUser();

// 获取当前页面
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? SITE_NAME; ?></title>
    <link href="<?php echo BASE_URL; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://lib.baomitu.com/bootstrap-icons/1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="<?php echo BASE_URL; ?>/assets/js/chart.min.js"></script>
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
            --sidebar-width: 250px;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #f8f9fc;
            font-family: "Nunito", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        /* 滚动条样式 */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            min-height: calc(100vh - 56px);
            background: linear-gradient(180deg, #4e73df 10%, #224abe 100%);
            padding-top: 20px;
            position: fixed;
            left: 0;
            top: 56px;
            bottom: 0;
            z-index: 100;
            overflow-y: auto;
            transition: all 0.3s;
        }
        
        .sidebar-item {
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            border-radius: 5px;
            margin: 5px 10px;
            transition: all 0.2s;
        }
        
        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            transform: translateX(5px);
        }
        
        .sidebar-item.active {
            background-color: rgba(255, 255, 255, 0.2);
            color: #fff;
            font-weight: bold;
        }
        
        .sidebar-item i {
            margin-right: 10px;
            font-size: 1.1rem;
        }
        
        .content {
            flex: 1;
            padding: 20px;
            margin-left: var(--sidebar-width);
            transition: all 0.3s;
        }
        
        .navbar-brand {
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .card {
            border: none;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            border-radius: 0.5rem;
        }
        
        .card-header {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
        }
        
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .btn {
            border-radius: 0.35rem;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-success {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-info {
            background-color: var(--info-color);
            border-color: var(--info-color);
            color: #fff;
        }
        
        .btn-warning {
            background-color: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .pagination .page-link {
            color: var(--primary-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: #fff;
        }
        
        /* 锚点跳转优化 */
        html {
            scroll-padding-top: 80px; /* 为固定导航栏留出空间 */
        }
        
        /* 锚点目标元素样式 */
        [id] {
            scroll-margin-top: 80px; /* 确保锚点目标不被导航栏遮挡 */
        }
        
        /* 加载状态样式 */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-spinner {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .content {
                margin-left: 0;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .content.sidebar-open {
                margin-left: var(--sidebar-width);
            }
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>/index.php">
                <i class="bi bi-house-door me-2"></i><?php echo SITE_NAME; ?>
            </a>
            <button class="navbar-toggler" type="button" id="sidebar-toggle">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle me-1"></i> <?php echo $currentUser['name']; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/change_password.php"><i class="bi bi-shield-lock me-2"></i>修改密码</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/logout.php?t=<?php echo time(); ?>" rel="nofollow"><i class="bi bi-box-arrow-right me-2"></i>退出登录</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- 内容区域 -->
    <div class="d-flex flex-grow-1" style="margin-top: 56px;">
        <!-- 侧边栏 -->
        <div class="sidebar" id="sidebar">
            <a href="<?php echo BASE_URL; ?>/index.php" class="sidebar-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i> 控制面板
            </a>
            <a href="<?php echo BASE_URL; ?>/statistics.php" class="sidebar-item <?php echo ($currentPage === 'statistics.php' || $currentPage === 'history.php') ? 'active' : ''; ?>">
                <i class="bi bi-bar-chart"></i> 查寝统计/历史
            </a>
            <a href="<?php echo BASE_URL; ?>/students.php" class="sidebar-item <?php echo $currentPage === 'students.php' ? 'active' : ''; ?>">
                <i class="bi bi-people"></i> 学生管理
            </a>
            <a href="<?php echo BASE_URL; ?>/leave.php" class="sidebar-item <?php echo $currentPage === 'leave.php' ? 'active' : ''; ?>">
                <i class="bi bi-calendar-check"></i> 请假管理
            </a>
            <?php if (isCounselor() || isAdmin()): ?>
            <a href="<?php echo BASE_URL; ?>/leave_review.php" class="sidebar-item <?php echo $currentPage === 'leave_review.php' ? 'active' : ''; ?>">
                <i class="bi bi-clipboard-check"></i> 请假审批
            </a>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/device.php" class="sidebar-item <?php echo $currentPage === 'device.php' ? 'active' : ''; ?>">
                <i class="bi bi-cpu"></i> 设备管理
            </a>
            <a href="<?php echo BASE_URL; ?>/fingerprint.php" class="sidebar-item <?php echo $currentPage === 'fingerprint.php' ? 'active' : ''; ?>">
                <i class="bi bi-fingerprint"></i> 指纹管理
            </a>
            <?php if (isAdmin()): ?>
            <a href="<?php echo BASE_URL; ?>/login_logs.php" class="sidebar-item <?php echo $currentPage === 'login_logs.php' ? 'active' : ''; ?>">
                <i class="bi bi-shield-lock"></i> 登录日志
            </a>
            <a href="<?php echo BASE_URL; ?>/device_logs.php" class="sidebar-item <?php echo $currentPage === 'device_logs.php' ? 'active' : ''; ?>">
                <i class="bi bi-activity"></i> 设备日志
            </a>
            <a href="<?php echo BASE_URL; ?>/login_protection_info.php" class="sidebar-item <?php echo $currentPage === 'login_protection_info.php' ? 'active' : ''; ?>">
                <i class="bi bi-shield-check"></i> 登录保护
            </a>
            <a href="<?php echo BASE_URL; ?>/backup_management.php" class="sidebar-item <?php echo $currentPage === 'backup_management.php' ? 'active' : ''; ?>">
                <i class="bi bi-hdd"></i> 数据库备份
            </a>
            <a href="<?php echo BASE_URL; ?>/settings.php" class="sidebar-item <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                <i class="bi bi-gear"></i> 系统设置
            </a>
            <?php endif; ?>
        </div>

        <!-- 主内容 -->
        <div class="content container-fluid" id="main-content">
            
            <!-- 加载状态覆盖层 -->
            <div class="loading-overlay" id="loadingOverlay">
                <div class="loading-spinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">加载中...</span>
                    </div>
                    <p class="mt-3 mb-0">处理中，请稍候...</p>
                </div>
            </div> 