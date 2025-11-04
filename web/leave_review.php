<?php
/**
 * 辅导员/管理员请假审批页面
 * 辅导员只能看到自己负责的学生申请
 * 管理员可以看到所有申请
 */

// 加载配置和工具函数
require_once 'config/config.php';
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/leave_application.php';

// ⭐ 启动会话并检查登录状态
startSecureSession();
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// 获取当前用户信息
$currentUsername = $_SESSION['username'] ?? '';
$currentRole = $_SESSION['role'] ?? '';
$isCounselor = ($currentRole === 'counselor');
$isAdmin = ($currentRole === 'admin');

// 只有辅导员和管理员可以访问
if (!$isCounselor && !$isAdmin) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// 实例化模型
$leaveModel = new LeaveApplication();

// ==================== POST 请求处理（审批/拒绝）====================
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'approve' && getRequestMethod() === 'POST') {
    // 批准申请
    $applicationId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
    
    if ($applicationId > 0) {
        $result = $leaveModel->approveApplication($applicationId, $currentUsername);
        
        if ($result['success']) {
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = 'error';
        }
    } else {
        $_SESSION['message'] = '申请ID无效';
        $_SESSION['message_type'] = 'error';
    }
    
    redirect(BASE_URL . '/leave_review.php');
    exit;
}

if ($action === 'reject' && getRequestMethod() === 'POST') {
    // 拒绝申请
    $applicationId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
    
    if ($applicationId > 0) {
        $result = $leaveModel->rejectApplication($applicationId, $currentUsername);
        
        if ($result['success']) {
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = 'error';
        }
    } else {
        $_SESSION['message'] = '申请ID无效';
        $_SESSION['message_type'] = 'error';
    }
    
    redirect(BASE_URL . '/leave_review.php');
    exit;
}

// ==================== 读取 SESSION 消息 ====================
$message = '';
$messageType = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = isset($_SESSION['message_type']) ? $_SESSION['message_type'] : 'info';
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// ==================== 获取申请列表 ====================
// 获取筛选状态
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'all';

// 根据用户角色获取申请列表
if ($isAdmin) {
    $applicationsResult = $leaveModel->getApplicationsByAdmin($filterStatus);
} else {
    $applicationsResult = $leaveModel->getApplicationsByCounselor($currentUsername, $filterStatus);
}

$applications = $applicationsResult['success'] ? $applicationsResult['data'] : [];

// 统计各状态数量
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;

if ($isAdmin) {
    $statsResult = $leaveModel->getApplicationsByAdmin('all');
} else {
    $statsResult = $leaveModel->getApplicationsByCounselor($currentUsername, 'all');
}

if ($statsResult['success']) {
    foreach ($statsResult['data'] as $app) {
        if ($app['status'] === 'pending') $pendingCount++;
        if ($app['status'] === 'approved') $approvedCount++;
        if ($app['status'] === 'rejected') $rejectedCount++;
    }
}

$totalCount = $pendingCount + $approvedCount + $rejectedCount;

// ==================== 页面标题 ====================
$pageTitle = '请假审批';
include 'templates/header.php';
?>

<div class="container-fluid">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-clipboard-check"></i> 
                    <?php echo $isAdmin ? '请假审批（管理员）' : '请假审批（辅导员）'; ?>
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <span class="badge bg-primary fs-6 me-2">
                        <i class="bi bi-person-badge"></i> 
                        <?php echo htmlspecialchars($currentUsername); ?>
                    </span>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- 统计卡片 -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-warning shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-0">待审批</h6>
                                    <h2 class="mb-0 mt-2"><?php echo $pendingCount; ?></h2>
                                </div>
                                <div class="fs-1 opacity-50">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-success shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-0">已批准</h6>
                                    <h2 class="mb-0 mt-2"><?php echo $approvedCount; ?></h2>
                                </div>
                                <div class="fs-1 opacity-50">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-danger shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-0">已拒绝</h6>
                                    <h2 class="mb-0 mt-2"><?php echo $rejectedCount; ?></h2>
                                </div>
                                <div class="fs-1 opacity-50">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-primary shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-0">总申请</h6>
                                    <h2 class="mb-0 mt-2"><?php echo $totalCount; ?></h2>
                                </div>
                                <div class="fs-1 opacity-50">
                                    <i class="bi bi-list-check"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 筛选栏 -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
                        </div>
                        <div class="col-md-6">
                            <div class="btn-group float-end" role="group">
                                <a href="?status=all" class="btn btn-sm <?php echo $filterStatus === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                    全部 (<?php echo $totalCount; ?>)
                                </a>
                                <a href="?status=pending" class="btn btn-sm <?php echo $filterStatus === 'pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                    待审批 (<?php echo $pendingCount; ?>)
                                </a>
                                <a href="?status=approved" class="btn btn-sm <?php echo $filterStatus === 'approved' ? 'btn-success' : 'btn-outline-success'; ?>">
                                    已批准 (<?php echo $approvedCount; ?>)
                                </a>
                                <a href="?status=rejected" class="btn btn-sm <?php echo $filterStatus === 'rejected' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                    已拒绝 (<?php echo $rejectedCount; ?>)
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 申请列表 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> 申请列表</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($applications)): ?>
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle"></i> 暂无申请记录
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <th>申请时间</th>
                                    <th>学号</th>
                                    <th>姓名</th>
                                    <th>班级</th>
                                    <th>请假日期</th>
                                    <th>天数</th>
                                    <th>请假原因</th>
                                    <th>状态</th>
                                    <th>审批人</th>
                                    <th>审批时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $app): 
                                    // 后端已经解析过JSON，直接使用
                                    $leaveDates = is_array($app['leave_dates']) ? $app['leave_dates'] : [];
                                    $dateCount = count($leaveDates);
                                    $dateDisplay = '';
                                    if ($dateCount > 0) {
                                        sort($leaveDates);
                                        if ($dateCount <= 3) {
                                            $dateDisplay = implode('、', $leaveDates);
                                        } else {
                                            $dateDisplay = $leaveDates[0] . ' 至 ' . end($leaveDates);
                                        }
                                    }
                                    
                                    // 状态样式
                                    $statusBadge = [
                                        'pending' => '<span class="badge bg-warning">待审批</span>',
                                        'approved' => '<span class="badge bg-success">已批准</span>',
                                        'rejected' => '<span class="badge bg-danger">已拒绝</span>',
                                        'cancelled' => '<span class="badge bg-secondary">已撤销</span>'
                                    ];
                                ?>
                                <tr class="text-center align-middle">
                                    <td><?php echo date('Y-m-d H:i', strtotime($app['apply_time'])); ?></td>
                                    <td><?php echo htmlspecialchars($app['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($app['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($app['class_name']); ?></td>
                                    <td>
                                        <span class="text-primary" title="<?php echo htmlspecialchars(implode(', ', $leaveDates)); ?>">
                                            <?php echo htmlspecialchars($dateDisplay); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $dateCount; ?>天</td>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($app['reason']); ?>">
                                            <?php echo mb_strlen($app['reason']) > 20 ? mb_substr($app['reason'], 0, 20) . '...' : htmlspecialchars($app['reason']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $statusBadge[$app['status']]; ?></td>
                                    <td><?php echo $app['reviewer'] ? htmlspecialchars($app['reviewer']) : '-'; ?></td>
                                    <td><?php echo $app['review_time'] ? date('Y-m-d H:i', strtotime($app['review_time'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($app['status'] === 'pending'): ?>
                                        <button class="btn btn-sm btn-success me-1" onclick="approveApplication(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['student_name']); ?>')">
                                            <i class="bi bi-check-circle"></i> 批准
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="rejectApplication(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['student_name']); ?>')">
                                            <i class="bi bi-x-circle"></i> 拒绝
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-info" onclick="viewDetails(<?php echo htmlspecialchars(json_encode($app)); ?>)">
                                            <i class="bi bi-eye"></i> 详情
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
</div>

<!-- 批准确认模态框 -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> 批准申请</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>确认批准 <strong id="approveStudentName"></strong> 的请假申请吗？</p>
                <p class="text-muted small">批准后将自动生成请假记录。</p>
            </div>
            <div class="modal-footer">
                <form id="approveForm" method="POST" action="?action=approve">
                    <input type="hidden" name="application_id" id="approveApplicationId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> 确认批准
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 拒绝确认模态框 -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle"></i> 拒绝申请</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>确认拒绝 <strong id="rejectStudentName"></strong> 的请假申请吗？</p>
                <p class="text-danger small">拒绝后学生可以重新申请。</p>
            </div>
            <div class="modal-footer">
                <form id="rejectForm" method="POST" action="?action=reject">
                    <input type="hidden" name="application_id" id="rejectApplicationId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle"></i> 确认拒绝
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 详情模态框 -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-eye"></i> 申请详情</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- 动态填充 -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<script>
// 批准申请
function approveApplication(applicationId, studentName) {
    document.getElementById('approveApplicationId').value = applicationId;
    document.getElementById('approveStudentName').textContent = studentName;
    const modal = new bootstrap.Modal(document.getElementById('approveModal'));
    modal.show();
}

// 拒绝申请
function rejectApplication(applicationId, studentName) {
    document.getElementById('rejectApplicationId').value = applicationId;
    document.getElementById('rejectStudentName').textContent = studentName;
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
}

// 查看详情
function viewDetails(application) {
    // leave_dates可能已经是数组（通过json_encode传递）
    const leaveDates = Array.isArray(application.leave_dates) ? application.leave_dates : JSON.parse(application.leave_dates);
    const statusText = {
        'pending': '待审批',
        'approved': '已批准',
        'rejected': '已拒绝',
        'cancelled': '已撤销'
    };
    
    const html = `
        <table class="table table-bordered">
            <tr>
                <th width="30%">学号</th>
                <td>${application.student_id}</td>
            </tr>
            <tr>
                <th>姓名</th>
                <td>${application.student_name}</td>
            </tr>
            <tr>
                <th>班级</th>
                <td>${application.class_name}</td>
            </tr>
            <tr>
                <th>宿舍</th>
                <td>${application.dormitory || '-'}</td>
            </tr>
            <tr>
                <th>请假日期</th>
                <td>${leaveDates.join('、')}</td>
            </tr>
            <tr>
                <th>请假天数</th>
                <td>${leaveDates.length}天</td>
            </tr>
            <tr>
                <th>请假原因</th>
                <td>${application.reason}</td>
            </tr>
            <tr>
                <th>申请时间</th>
                <td>${application.apply_time}</td>
            </tr>
            <tr>
                <th>状态</th>
                <td>${statusText[application.status]}</td>
            </tr>
            <tr>
                <th>审批人</th>
                <td>${application.reviewer || '-'}</td>
            </tr>
            <tr>
                <th>审批时间</th>
                <td>${application.review_time || '-'}</td>
            </tr>
        </table>
    `;
    
    document.getElementById('detailsContent').innerHTML = html;
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
}

// 自动隐藏提示消息
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        if (alert.classList.contains('alert-success') || alert.classList.contains('alert-danger')) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }
    });
}, 3000);
</script>

<?php include 'templates/footer.php'; ?>

