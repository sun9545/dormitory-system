<?php
/**
 * 登录日志页面
 * 仅管理员可访问
 */
require_once 'config/config.php';
$pageTitle = '登录日志 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';

// 检查管理员权限
requireAdmin();

// 获取当前页码
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$limit = PAGINATION_LIMIT;
$offset = ($page - 1) * $limit;

// 获取筛选条件
$filters = [];

if (isset($_GET['username']) && !empty($_GET['username'])) {
    $filters['username'] = sanitizeInput($_GET['username']);
}

if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $filters['start_date'] = sanitizeInput($_GET['start_date']);
}

if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $filters['end_date'] = sanitizeInput($_GET['end_date']);
}

if (isset($_GET['ip']) && !empty($_GET['ip'])) {
    $filters['ip'] = sanitizeInput($_GET['ip']);
}

// 连接数据库
$db = getDBConnection();

// 构建查询
$query = "SELECT l.*, u.username, u.name, u.role 
          FROM login_logs l
          JOIN users u ON l.user_id = u.id";
$countQuery = "SELECT COUNT(*) FROM login_logs l JOIN users u ON l.user_id = u.id";

$params = [];
$whereConditions = [];

// 添加筛选条件
if (!empty($filters)) {
    if (isset($filters['username'])) {
        $whereConditions[] = "(u.username LIKE :username OR u.name LIKE :username)";
        $params['username'] = '%' . $filters['username'] . '%';
    }
    
    if (isset($filters['start_date'])) {
        $whereConditions[] = "l.login_time >= :start_date";
        $params['start_date'] = $filters['start_date'] . ' 00:00:00';
    }
    
    if (isset($filters['end_date'])) {
        $whereConditions[] = "l.login_time <= :end_date";
        $params['end_date'] = $filters['end_date'] . ' 23:59:59';
    }
    
    if (isset($filters['ip'])) {
        $whereConditions[] = "l.ip_address LIKE :ip";
        $params['ip'] = '%' . $filters['ip'] . '%';
    }
}

// 添加WHERE子句
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(" AND ", $whereConditions);
    $countQuery .= " WHERE " . implode(" AND ", $whereConditions);
}

// 添加排序和分页
$query .= " ORDER BY l.login_time DESC LIMIT :limit OFFSET :offset";

// 准备和执行查询
$stmt = $db->prepare($query);

// 绑定参数
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$logs = $stmt->fetchAll();

// 获取总记录数
$countStmt = $db->prepare($countQuery);
foreach ($params as $key => $value) {
    $countStmt->bindValue(':' . $key, $value);
}
$countStmt->execute();
$totalCount = $countStmt->fetchColumn();

// 计算总页数
$totalPages = ceil($totalCount / $limit);

// 加载页头
include 'templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">登录日志</h1>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/index.php">首页</a></li>
            <li class="breadcrumb-item active" aria-current="page">登录日志</li>
        </ol>
    </nav>
</div>

<!-- 筛选表单 -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">筛选条件</h6>
        <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
            <i class="bi bi-funnel"></i> 筛选选项
        </button>
    </div>
    <div class="collapse show" id="filterCollapse">
        <div class="card-body">
            <form method="get" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="username" class="form-label">用户名/姓名</label>
                    <input type="text" class="form-control" id="username" name="username" placeholder="搜索用户" value="<?php echo isset($filters['username']) ? $filters['username'] : ''; ?>">
                </div>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">开始日期</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo isset($filters['start_date']) ? $filters['start_date'] : ''; ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">结束日期</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo isset($filters['end_date']) ? $filters['end_date'] : ''; ?>">
                </div>
                <div class="col-md-3">
                    <label for="ip" class="form-label">IP地址</label>
                    <input type="text" class="form-control" id="ip" name="ip" placeholder="IP地址" value="<?php echo isset($filters['ip']) ? $filters['ip'] : ''; ?>">
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> 搜索
                    </button>
                    <a href="<?php echo BASE_URL; ?>/login_logs.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-repeat"></i> 重置
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 登录日志表格 -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">登录日志列表</h6>
        <span class="badge bg-primary rounded-pill">共 <?php echo $totalCount; ?> 条记录</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>姓名</th>
                        <th>角色</th>
                        <th>登录时间</th>
                        <th>IP地址</th>
                        <th>设备信息</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($logs)): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $log['id']; ?></td>
                                <td><?php echo $log['username']; ?></td>
                                <td><?php echo $log['name']; ?></td>
                                <td>
                                    <?php if ($log['role'] == 'admin'): ?>
                                        <span class="badge bg-danger">管理员</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">辅导员</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDateTime($log['login_time']); ?></td>
                                <td><?php echo $log['ip_address']; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#userAgent-<?php echo $log['id']; ?>">
                                        查看详情
                                    </button>
                                    <div class="collapse mt-2" id="userAgent-<?php echo $log['id']; ?>">
                                        <div class="card card-body">
                                            <small class="text-muted"><?php echo $log['user_agent']; ?></small>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i> 暂无登录日志记录
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-4">
                <?php echo generatePagination($page, $totalPages, BASE_URL . '/login_logs.php'); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 日期选择器联动
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    startDateInput.addEventListener('change', function() {
        if (endDateInput.value && startDateInput.value > endDateInput.value) {
            endDateInput.value = startDateInput.value;
        }
    });
    
    endDateInput.addEventListener('change', function() {
        if (startDateInput.value && endDateInput.value < startDateInput.value) {
            startDateInput.value = endDateInput.value;
        }
    });
});
</script>

<?php
// 加载页脚
include 'templates/footer.php';
?> 