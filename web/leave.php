<?php
/**
 * 请假管理页面
 */

// 获取当前动作 - 必须在所有include之前
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// 处理下载模板 - 必须在任何输出之前
if ($action === 'download_template') {
    // 首先加载环境配置并定义常量
    require_once __DIR__ . '/config/env.php';
    
    // 定义必要常量（在加载其他文件之前）
    if (!defined('BASE_URL')) define('BASE_URL', ENV_BASE_URL);
    if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__);
    if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', ROOT_PATH . '/uploads');
    if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', ENV_SESSION_TIMEOUT);
    
    // 现在可以安全加载其他文件
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/utils/auth.php';
    require_once __DIR__ . '/models/import_export.php';
    
    // 检查登录状态
    startSecureSession();
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    
    try {
        // 使用现有的CSV模板生成功能（已包含UTF-8 BOM）
        $importExport = new ImportExport();
        $templatePath = $importExport->createLeaveTemplate();
        
        if ($templatePath && file_exists($templatePath) && filesize($templatePath) > 0) {
            // 设置CSV下载头
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="leave_template.csv"');
            header('Cache-Control: max-age=0');
            header('Content-Length: ' . filesize($templatePath));
            
            // 直接输出文件
            readfile($templatePath);
            exit;
        } else {
            error_log("CSV模板文件创建失败: " . $templatePath);
            header('Location: ' . BASE_URL . '/leave.php?error=template_failed');
            exit;
        }
    } catch (Exception $e) {
        error_log("CSV模板下载失败: " . $e->getMessage());
        header('Location: ' . BASE_URL . '/leave.php?error=template_failed');
        exit;
    }
}

// 处理请假数据下载 - 重定向到独立的下载脚本
if ($action === 'download_leave_data') {
    // 加载基本配置以获取BASE_URL
    require_once __DIR__ . '/config/env.php';
    if (!defined('BASE_URL')) define('BASE_URL', ENV_BASE_URL);
    
    // 重定向到独立的下载脚本以避免头信息冲突
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $downloadUrl = BASE_URL . '/api/download_leave_data.php?date=' . urlencode($date);
    header('Location: ' . $downloadUrl);
    exit;
}

// 正常页面逻辑 - 加载完整配置
require_once 'config/config.php';
$pageTitle = '请假管理 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/student.php';
require_once 'models/check_record.php';
require_once 'models/import_export.php';

// ⭐ 启动会话并检查登录状态（在POST处理之前）
startSecureSession();
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// 创建数据模型实例（在处理POST之前）
$student = new Student();
$checkRecord = new CheckRecord();
$importExport = new ImportExport();

// ========== POST请求处理（必须在header之前）==========

// 处理批量请假上传（模态框提交）
if ($action === 'batch_upload' && getRequestMethod() === 'POST') {
    if (isset($_FILES['leave_batch_file']) && $_FILES['leave_batch_file']['error'] === UPLOAD_ERR_OK) {
        // 处理文件上传
        $uploadResult = handleFileUpload($_FILES['leave_batch_file']);
        
        if ($uploadResult['success']) {
            // 处理批量请假导入
            $importResult = $importExport->importLeaveFromExcel($uploadResult['path'], $_SESSION['user_id']);
            
            if ($importResult['success'] > 0) {
                // 强制清理缓存
                if (function_exists('clearAllRelatedCache')) {
                    clearAllRelatedCache();
                } else if (function_exists('clearCache')) {
                    clearCache(CACHE_KEY_ALL_STUDENTS);
                    clearCache(CACHE_KEY_ALL_STATUS_DATE);
                }
                $_SESSION['message'] = "批量请假成功！共导入 {$importResult['success']} 名学生";
                if ($importResult['failed'] > 0) {
                    $_SESSION['message'] .= "，{$importResult['failed']} 名学生导入失败";
                }
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = '批量请假导入失败';
                if (!empty($importResult['errors'])) {
                    $_SESSION['message'] .= '：' . implode(', ', $importResult['errors']);
                }
                $_SESSION['message_type'] = 'danger';
            }
        } else {
            $_SESSION['message'] = '文件上传失败：' . $uploadResult['message'];
            $_SESSION['message_type'] = 'danger';
        }
    } else {
        $_SESSION['message'] = '请选择要上传的CSV文件';
        $_SESSION['message_type'] = 'danger';
    }
    // 重定向到列表页面（防止刷新重复提交）
    redirect(BASE_URL . '/leave.php');
}

// 处理取消请假
if ($action === 'cancel' && getRequestMethod() === 'POST') {
    if (isset($_POST['student_id']) && isset($_POST['cancel_date'])) {
        $studentId = sanitizeInput($_POST['student_id']);
        $cancelDate = sanitizeInput($_POST['cancel_date']);
        
        if ($checkRecord->cancelLeaveStatus($studentId, $_SESSION['user_id'], $cancelDate)) {
            // 强制清理缓存
            if (function_exists('clearAllRelatedCache')) {
                clearAllRelatedCache();
            } else if (function_exists('clearCache')) {
                clearCache(CACHE_KEY_ALL_STUDENTS);
                clearCache(CACHE_KEY_ALL_STATUS_DATE);
            }
            $_SESSION['message'] = '请假已取消，学生状态已恢复为取消前状态';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = '取消请假失败';
            $_SESSION['message_type'] = 'danger';
        }
    }
    // 重定向到列表页面（防止刷新重复提交）
    redirect(BASE_URL . '/leave.php');
}

// 处理单独添加请假学生
if ($action === 'add_leave' && getRequestMethod() === 'POST') {
    if (isset($_POST['student_id']) && !empty($_POST['student_id'])) {
        $studentId = sanitizeInput($_POST['student_id']);
        
        // 检查学生是否存在
        $studentData = $student->getStudentById($studentId);
    
        if ($studentData) {
            // 更新学生状态为请假
            if ($checkRecord->updateStudentStatus($studentId, '请假', $_SESSION['user_id'])) {
                // 强制清理缓存
                if (function_exists('clearAllRelatedCache')) {
                    clearAllRelatedCache();
                } else if (function_exists('clearCache')) {
                    clearCache(CACHE_KEY_ALL_STUDENTS);
                    clearCache(CACHE_KEY_ALL_STATUS_DATE);
                }
                $_SESSION['message'] = "学生 {$studentData['name']} ({$studentId}) 已成功设置为请假状态";
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = '设置请假状态失败';
                $_SESSION['message_type'] = 'danger';
            }
        } else {
            $_SESSION['message'] = "学号为 {$studentId} 的学生不存在";
            $_SESSION['message_type'] = 'danger';
        }
    } else {
        $_SESSION['message'] = '请输入有效的学号';
        $_SESSION['message_type'] = 'danger';
    }
    // 重定向到列表页面（防止刷新重复提交）
    redirect(BASE_URL . '/leave.php');
}

// ========== POST请求处理结束 ==========

// 初始化消息
if (!isset($message)) {
    $message = '';
    $messageType = '';
}

// 从会话中获取消息（用于POST/Redirect/GET模式）
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// 加载页头（必须在所有POST处理之后）
include 'templates/header.php';

// 获取筛选条件
$filters = [
    'status' => '请假' // 默认只显示请假学生
];

// 获取查询日期，默认为今天
$queryDate = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (isset($_GET['building']) && !empty($_GET['building'])) {
    $filters['building'] = (int)$_GET['building'];
}

if (isset($_GET['building_area']) && !empty($_GET['building_area'])) {
    $filters['building_area'] = $_GET['building_area'];
}

if (isset($_GET['building_floor']) && !empty($_GET['building_floor'])) {
    $filters['building_floor'] = (int)$_GET['building_floor'];
}

if (isset($_GET['class_name']) && !empty($_GET['class_name'])) {
    $filters['class_name'] = $_GET['class_name'];
}

if (isset($_GET['counselor']) && !empty($_GET['counselor'])) {
    $filters['counselor'] = $_GET['counselor'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $filters['search'] = sanitizeInput($_GET['search']);
}

// 根据不同的动作显示不同的视图
switch ($action) {
    case 'add':
        // 获取所有班级列表供选择
        $classes = $student->getAllClasses();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">添加请假学生</h1>
    <a href="<?php echo BASE_URL; ?>/leave.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> 返回请假列表
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">添加请假学生</h6>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo BASE_URL; ?>/leave.php?action=add_leave" id="addLeaveForm">
                    <div class="mb-3">
                        <label for="student_id" class="form-label">学生学号 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="student_id" name="student_id" required placeholder="请输入学生学号">
                            <button type="button" class="btn btn-outline-secondary" id="searchStudentBtn">
                                <i class="bi bi-search"></i> 查找
                            </button>
                        </div>
                        <div class="form-text">输入学号并点击查找按钮验证学生信息</div>
                    </div>
                    
                    <div id="studentInfo" class="mb-3 d-none">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title" id="studentName">学生姓名</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>班级：</strong> <span id="studentClass"></span></p>
                                        <p><strong>性别：</strong> <span id="studentGender"></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>宿舍：</strong> <span id="studentDorm"></span></p>
                                        <p><strong>辅导员：</strong> <span id="studentCounselor"></span></p>
                                    </div>
                                </div>
                                <div id="studentStatusWarning" class="alert alert-warning d-none">
                                    <i class="bi bi-exclamation-triangle"></i> 该学生当前已是请假状态
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="studentNotFound" class="alert alert-danger d-none">
                        <i class="bi bi-exclamation-circle"></i> 未找到该学号的学生
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                            <i class="bi bi-check-circle"></i> 确认添加请假
                        </button>
                        <a href="<?php echo BASE_URL; ?>/leave.php" class="btn btn-secondary">
                            取消
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchBtn = document.getElementById('searchStudentBtn');
    const studentIdInput = document.getElementById('student_id');
    const studentInfo = document.getElementById('studentInfo');
    const studentNotFound = document.getElementById('studentNotFound');
    const submitBtn = document.getElementById('submitBtn');
    const studentStatusWarning = document.getElementById('studentStatusWarning');
    
    // 查找学生按钮点击事件
    searchBtn.addEventListener('click', function() {
        const studentId = studentIdInput.value.trim();
        if (!studentId) {
            alert('请输入学号');
            return;
        }
        
        // 使用fetch API查询学生信息
        fetch(`<?php echo BASE_URL; ?>/api/student_info.php?student_id=${studentId}`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 显示学生信息
                    document.getElementById('studentName').textContent = data.student.name;
                    document.getElementById('studentClass').textContent = data.student.class_name;
                    document.getElementById('studentGender').textContent = data.student.gender;
                    document.getElementById('studentDorm').textContent = 
                        `${data.student.building}号楼 ${data.student.building_area}区${data.student.building_floor}层 ${data.student.room_number}-${data.student.bed_number}床`;
                    document.getElementById('studentCounselor').textContent = data.student.counselor || '未设置';
                    
                    // 显示学生信息卡片
                    studentInfo.classList.remove('d-none');
                    studentNotFound.classList.add('d-none');
                    
                    // 检查学生当前状态
                    if (data.status === '请假') {
                        studentStatusWarning.classList.remove('d-none');
                        submitBtn.disabled = true;
                    } else {
                        studentStatusWarning.classList.add('d-none');
                        submitBtn.disabled = false;
                    }
                } else {
                    // 显示未找到学生的提示
                    studentInfo.classList.add('d-none');
                    studentNotFound.classList.remove('d-none');
                    submitBtn.disabled = true;
                }
            })
            .catch(error => {
                console.error('查询学生信息失败:', error);
                alert('查询学生信息失败，请重试');
            });
    });
    
    // 学号输入框变化时重置状态
    studentIdInput.addEventListener('input', function() {
        studentInfo.classList.add('d-none');
        studentNotFound.classList.add('d-none');
        submitBtn.disabled = true;
    });
});
</script>

<?php
        break;
    case 'upload':
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">批量请假</h1>
    <a href="<?php echo BASE_URL; ?>/leave.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> 返回请假列表
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">上传请假名单</h6>
            </div>
            <div class="card-body">
                <p class="mb-4">
                    请使用CSV表格上传学生请假信息。表格应包含学生的班级、姓名和学号。
                    <a href="<?php echo BASE_URL; ?>/leave.php?action=download_template" class="btn btn-sm btn-outline-primary ms-2">
                        <i class="bi bi-download"></i> 下载CSV模板
                    </a>
                </p>
                
                <form method="post" action="<?php echo BASE_URL; ?>/leave.php?action=upload" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label for="leave_file" class="form-label">请假名单文件</label>
                        <input type="file" class="form-control" id="leave_file" name="leave_file" accept=".csv,.xls,.xlsx" required>
                        <div class="form-text">支持CSV和Excel格式(.csv .xlsx .xls) - <strong>系统自动处理编码转换</strong></div>
                    </div>
                    
                    <!-- 文件预览区域 -->
                    <div id="leave_file_preview" class="mb-3" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">文件预览</h6>
                        </div>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>班级</th>
                                        <th>姓名</th>
                                        <th>学号</th>
                                    </tr>
                                </thead>
                                <tbody id="leave_preview_tbody">
                                </tbody>
                            </table>
                        </div>
                        <div id="leave_preview_summary" class="text-muted small"></div>
                    </div>
                    
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i> 上传文件
                        </button>
                        <a href="<?php echo BASE_URL; ?>/leave.php" class="btn btn-secondary">
                            取消
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">使用说明</h6>
            </div>
            <div class="card-body">
                <h5>请假名单格式要求</h5>
                <ol>
                    <li>请使用提供的CSV模板，保持表头不变</li>
                    <li>必须填写学生的学号</li>
                    <li>姓名和班级可选填，用于辅助确认</li>
                    <li>支持CSV和Excel格式(.csv .xlsx .xls)</li>
                    <li><strong>系统自动检测并转换文件编码，无需手动处理UTF-8</strong></li>
                    <li>上传成功后，所有名单中的学生将被标记为"请假"状态</li>
                    <li>如需取消请假，请在请假列表中操作</li>
                </ol>
                
                <div class="alert alert-info mt-3">
                    <h6><i class="bi bi-info-circle"></i> 提示</h6>
                    <p>如果上传时遇到问题，请尝试以下方法：</p>
                    <ul>
                        <li>确保文件格式正确，表头包含"学号"列</li>
                        <li>请使用标准的Excel格式(.xlsx .xls)</li>
                        <li>建议使用提供的模板文件进行编辑</li>
                        <li>确保学号列不为空</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
        break;
        
    default:
        // 获取请假学生列表
        $checkRecord = new CheckRecord();
        
        // 获取当前请假的学生（最新状态=请假）
        $leaveStudents = $checkRecord->getAllStudentsStatusByDate($queryDate, $filters);
        
        // 获取请假历史（包括已回寝的学生）
        $leaveHistory = $checkRecord->getLeaveHistoryByDate($queryDate, $filters);
        
        // 统计请假历史中的状态
        $stillOnLeave = 0;
        $returned = 0;
        foreach ($leaveHistory as $student_item) {
            if ($student_item['current_status'] === '请假') {
                $stillOnLeave++;
            } else {
                $returned++;
            }
        }
        
        // 获取班级列表(用于筛选)
        $classes = $student->getAllClasses();
        
        // 获取辅导员列表(用于筛选)
        $counselors = $student->getAllCounselors();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">请假管理 - <?php echo $queryDate; ?></h1>
    <div>
        <a href="<?php echo BASE_URL; ?>/apply_leave.php" class="btn btn-warning me-2" target="_blank">
            <i class="bi bi-phone"></i> 学生申请入口
        </a>
        <a href="<?php echo BASE_URL; ?>/leave.php?action=add" class="btn btn-success me-2">
            <i class="bi bi-plus-circle"></i> 添加请假
        </a>
        <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#batchLeaveModal">
            <i class="bi bi-upload"></i> 批量请假
        </button>
        <!-- 下载按钮改为下拉菜单 -->
        <div class="btn-group">
            <button type="button" class="btn btn-info dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download"></i> 下载请假信息
            </button>
            <ul class="dropdown-menu">
                <li>
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#downloadLeaveDataModal">
                        <i class="bi bi-calendar-day me-2"></i>每日请假信息
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#downloadWeekendModal">
                        <i class="bi bi-calendar-week me-2"></i>周末离校请假单
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<!-- 学生申请流程提示 -->
<div class="alert alert-info alert-dismissible fade show" role="alert">
    <h6 class="alert-heading"><i class="bi bi-info-circle"></i> 学生请假申请流程</h6>
    <p class="mb-0">
        <strong>新功能：</strong>学生可以通过 <a href="<?php echo BASE_URL; ?>/apply_leave.php" target="_blank" class="alert-link">学生申请入口</a> 自主提交请假申请。
        申请提交后，请前往 <a href="<?php echo BASE_URL; ?>/leave_review.php" class="alert-link">请假审批</a> 页面进行审批。
        审批通过后，请假记录将自动显示在本页面中。
    </p>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<!-- 筛选表单 -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">筛选条件</h6>
    </div>
    <div class="card-body">
        <form method="get" action="" class="d-flex align-items-end gap-3">
            <div class="flex-fill">
                <label for="date" class="form-label mb-1">日期</label>
                <input type="date" class="form-control" id="date" name="date" value="<?php echo $queryDate; ?>">
            </div>
            <div class="flex-fill">
                <label for="search" class="form-label mb-1">搜索</label>
                <input type="text" class="form-control" id="search" name="search" placeholder="学号/姓名" value="<?php echo isset($filters['search']) ? $filters['search'] : ''; ?>">
            </div>
            <div class="flex-fill">
                <label for="building" class="form-label mb-1">楼栋</label>
                <select class="form-select" id="building" name="building">
                    <option value="">全部</option>
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo isset($filters['building']) && $filters['building'] == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>号楼
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex-fill">
                <label for="building_area" class="form-label mb-1">区域</label>
                <select class="form-select" id="building_area" name="building_area">
                    <option value="">全部</option>
                    <option value="A" <?php echo isset($filters['building_area']) && $filters['building_area'] === 'A' ? 'selected' : ''; ?>>A区</option>
                    <option value="B" <?php echo isset($filters['building_area']) && $filters['building_area'] === 'B' ? 'selected' : ''; ?>>B区</option>
                </select>
            </div>
            <div class="flex-fill">
                <label for="building_floor" class="form-label mb-1">楼层</label>
                <select class="form-select" id="building_floor" name="building_floor">
                    <option value="">全部</option>
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo isset($filters['building_floor']) && $filters['building_floor'] == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>层
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex-fill">
                <label for="class_name" class="form-label mb-1">班级</label>
                <select class="form-select" id="class_name" name="class_name">
                    <option value="">全部班级</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class; ?>" <?php echo isset($filters['class_name']) && $filters['class_name'] === $class ? 'selected' : ''; ?>>
                            <?php echo $class; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-fill">
                <label for="counselor" class="form-label mb-1">辅导员</label>
                <select class="form-select" id="counselor" name="counselor">
                    <option value="">全部辅导员</option>
                    <?php foreach ($counselors as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['counselor']); ?>" 
                                <?php echo isset($filters['counselor']) && $filters['counselor'] === $c['counselor'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['counselor']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-shrink-0">
                <button type="submit" class="btn btn-primary">筛选</button>
                <a href="<?php echo BASE_URL; ?>/leave.php" class="btn btn-secondary ms-2">重置</a>
            </div>
        </form>
    </div>
</div>

<!-- 请假学生列表 - 双标签页 -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <ul class="nav nav-tabs card-header-tabs" id="leaveTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="current-tab" data-bs-toggle="tab" 
                        data-bs-target="#current-leave" type="button" role="tab">
                    <i class="bi bi-calendar-check"></i> 当前请假 
                    <span class="badge bg-warning rounded-pill"><?php echo count($leaveStudents); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="history-tab" data-bs-toggle="tab" 
                        data-bs-target="#leave-history" type="button" role="tab">
                    <i class="bi bi-clock-history"></i> 请假历史
                    <span class="badge bg-secondary rounded-pill"><?php echo count($leaveHistory); ?></span>
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content" id="leaveTabContent">
            <!-- 当前请假标签页 -->
            <div class="tab-pane fade show active" id="current-leave" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">学号</th>
                                <th class="text-center">姓名</th>
                                <th class="text-center">性别</th>
                                <th class="text-center">班级</th>
                                <th class="text-center">宿舍</th>
                                <th class="text-center">辅导员</th>
                                <th class="text-center">请假时间</th>
                                <th class="text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($leaveStudents)): ?>
                                <?php foreach ($leaveStudents as $student): ?>
                                    <tr>
                                        <td class="text-center"><?php echo $student['student_id']; ?></td>
                                        <td class="text-center"><?php echo $student['name']; ?></td>
                                        <td class="text-center"><?php echo $student['gender']; ?></td>
                                        <td class="text-center"><?php echo $student['class_name']; ?></td>
                                        <td class="text-center"><?php echo $student['building']; ?>号楼<?php echo $student['building_area']; ?>区<?php echo $student['building_floor']; ?>层<?php echo $student['room_number']; ?>-<?php echo $student['bed_number']; ?>床</td>
                                        <td class="text-center"><?php echo $student['counselor']; ?></td>
                                        <td class="text-center"><?php echo formatDateTime($student['check_time']); ?></td>
                                        <td class="text-center">
                                            <a href="<?php echo BASE_URL; ?>/students.php?action=view&student_id=<?php echo $student['student_id']; ?>" class="btn btn-sm btn-info me-1">查看</a>
                                            <button type="button" class="btn btn-sm btn-danger cancel-leave" data-bs-toggle="modal" data-bs-target="#cancelLeaveModal" data-id="<?php echo $student['student_id']; ?>" data-name="<?php echo $student['name']; ?>">取消请假</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <div class="alert alert-info mb-0">
                                            <i class="bi bi-info-circle me-2"></i> 暂无正在请假的学生
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- 请假历史标签页 -->
            <div class="tab-pane fade" id="leave-history" role="tabpanel">
                <?php if (!empty($leaveHistory)): ?>
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        共 <strong><?php echo count($leaveHistory); ?></strong> 人，
                        其中 <span class="badge bg-warning"><?php echo $stillOnLeave; ?> 人仍在请假</span>，
                        <span class="badge bg-success"><?php echo $returned; ?> 人已回寝</span>
                    </div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">学号</th>
                                <th class="text-center">姓名</th>
                                <th class="text-center">性别</th>
                                <th class="text-center">班级</th>
                                <th class="text-center">宿舍</th>
                                <th class="text-center">辅导员</th>
                                <th class="text-center">请假时间</th>
                                <th class="text-center">当前状态</th>
                                <th class="text-center">最后签到</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($leaveHistory)): ?>
                                <?php foreach ($leaveHistory as $student): ?>
                                    <tr>
                                        <td class="text-center"><?php echo $student['student_id']; ?></td>
                                        <td class="text-center"><?php echo $student['name']; ?></td>
                                        <td class="text-center"><?php echo $student['gender']; ?></td>
                                        <td class="text-center"><?php echo $student['class_name']; ?></td>
                                        <td class="text-center"><?php echo $student['building']; ?>号楼<?php echo $student['building_area']; ?>区<?php echo $student['building_floor']; ?>层<?php echo $student['room_number']; ?>-<?php echo $student['bed_number']; ?>床</td>
                                        <td class="text-center"><?php echo $student['counselor']; ?></td>
                                        <td class="text-center"><?php echo formatDateTime($student['leave_time']); ?></td>
                                        <td class="text-center">
                                            <?php if ($student['current_status'] === '请假'): ?>
                                                <span class="badge bg-warning">🔴 请假中</span>
                                            <?php elseif ($student['current_status'] === '在寝'): ?>
                                                <span class="badge bg-success">🟢 已回寝</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo $student['current_status']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($student['latest_check_time']): ?>
                                                <small><?php echo date('H:i', strtotime($student['latest_check_time'])); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <div class="alert alert-info mb-0">
                                            <i class="bi bi-info-circle me-2"></i> 今天暂无请假记录
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 取消请假确认模态框 -->
<div class="modal fade" id="cancelLeaveModal" tabindex="-1" aria-labelledby="cancelLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cancelLeaveModalLabel">确认取消请假</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>确定要取消学生 <span id="cancelLeaveStudentName"></span> 的请假吗？</p>
                <p>取消后学生状态将更新为"在寝"。</p>
            </div>
            <div class="modal-footer">
                <form method="post" action="<?php echo BASE_URL; ?>/leave.php?action=cancel">
                    <input type="hidden" name="student_id" id="cancelLeaveStudentId" value="">
                    <input type="hidden" name="cancel_date" value="<?php echo $queryDate; ?>">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger">确认取消请假</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 取消请假确认模态框的JavaScript将在新的script中处理 -->

<?php
        break;
}

// 批量请假模态框
?>
<!-- 批量请假模态框 -->
<div class="modal fade" id="batchLeaveModal" tabindex="-1" aria-labelledby="batchLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchLeaveModalLabel">批量请假</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/leave.php?action=batch_upload" enctype="multipart/form-data" id="leaveBatchUploadForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- 使用说明 -->
                    <div class="alert alert-info mb-4">
                        <h6><i class="fas fa-info-circle me-2"></i>使用说明</h6>
                        <ul class="mb-2">
                            <li>请先下载CSV模板文件，按照模板格式填写数据</li>
                            <li>CSV文件必须包含：学号（必填），班级、姓名（可选）</li>
                            <li>系统自动检测并转换文件编码，无需手动处理UTF-8</li>
                            <li>如果学号不存在，系统将跳过该学生</li>
                        </ul>
                        <div class="text-center">
                            <a href="<?php echo BASE_URL; ?>/leave.php?action=download_template" class="btn btn-sm btn-outline-primary" target="_blank">
                                <i class="fas fa-download me-1"></i>下载CSV模板
                            </a>
                        </div>
                    </div>
                    
                    <!-- 必须满足的条件 -->
                    <div class="alert alert-warning mb-4">
                        <h6 class="mb-2">必须满足的条件</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-danger">必填字段：</h6>
                                <ul>
                                    <li>学号 - 不能为空，必须唯一</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-info">其他要求：</h6>
                                <ul>
                                    <li>学号必须在系统中存在</li>
                                    <li>CSV文件大小不超过10MB</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 文件选择 -->
                    <div class="mb-4">
                        <label for="leave_batch_file" class="form-label">选择CSV文件 <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="leave_batch_file" name="leave_batch_file" accept=".csv,.xls,.xlsx" required>
                        <div class="form-text">支持的文件格式：CSV (.csv)</div>
                    </div>
                    
                    <!-- 预览行数选择器 -->
                    <div class="mb-4">
                        <label for="leave_preview_limit" class="form-label">预览行数</label>
                        <select class="form-select" id="leave_preview_limit" name="leave_preview_limit">
                            <option value="10">10行</option>
                            <option value="50" selected>50行（推荐）</option>
                            <option value="100">100行</option>
                            <option value="200">200行</option>
                            <option value="0">全部数据</option>
                        </select>
                        <div class="form-text">选择上传前要预览的数据行数，全部数据适用于小文件</div>
                    </div>
                    
                    <!-- 文件预览区域 -->
                    <div id="leave_batch_preview" class="mb-3" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">文件预览</h6>
                            <button type="button" class="btn btn-sm btn-outline-info" id="validateStudentsBtn" style="display: none;">
                                <i class="fas fa-check-circle me-1"></i>在线检测
                            </button>
                        </div>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>班级</th>
                                        <th>姓名</th>
                                        <th>学号</th>
                                        <th>验证状态</th>
                                    </tr>
                                </thead>
                                <tbody id="leave_batch_preview_tbody">
                                </tbody>
                            </table>
                        </div>
                        <div id="leave_batch_preview_summary" class="text-muted small"></div>
                        
                        <!-- 验证结果摘要 -->
                        <div id="validation_summary" class="mt-3" style="display: none;">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle me-2"></i>验证结果</h6>
                                <div id="validation_summary_content"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary" id="leaveBatchUploadBtn">
                        <i class="fas fa-upload me-1"></i>开始上传
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 取消请假确认模态框
    document.querySelectorAll('.cancel-leave').forEach(function(button) {
        button.addEventListener('click', function() {
            var studentId = this.dataset.id;
            var studentName = this.dataset.name;
            document.getElementById('cancelLeaveStudentId').value = studentId;
            document.getElementById('cancelLeaveStudentName').textContent = studentName;
        });
    });
    
    // 编码处理现在由服务器端完成
    
    // 批量请假文件预览功能
    const leaveBatchFileInput = document.getElementById('leave_batch_file');
    const leaveBatchPreviewDiv = document.getElementById('leave_batch_preview');
    const leaveBatchPreviewTbody = document.getElementById('leave_batch_preview_tbody');
    const leaveBatchPreviewSummary = document.getElementById('leave_batch_preview_summary');
    
    if (leaveBatchFileInput) {
        leaveBatchFileInput.addEventListener('change', function(e) {
            console.log('批量请假文件选择事件触发');
            const file = e.target.files[0];
            if (!file) {
                console.log('没有选择文件');
                if (leaveBatchPreviewDiv) leaveBatchPreviewDiv.style.display = 'none';
                return;
            }
            
            console.log('选择的文件:', file.name, file.size, file.type);
            
            // 显示加载状态
            if (leaveBatchPreviewDiv) {
                leaveBatchPreviewDiv.style.display = 'block';
                if (leaveBatchPreviewTbody) leaveBatchPreviewTbody.innerHTML = '<tr><td colspan="3" class="text-center"><i class="fas fa-spinner fa-spin"></i> 正在处理文件编码...</td></tr>';
                if (leaveBatchPreviewSummary) leaveBatchPreviewSummary.textContent = '正在检测文件编码...';
            }
            
            // 使用Ajax发送到后端处理编码
            const formData = new FormData();
            formData.append('preview_file', file);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            // 获取预览行数限制
            const previewLimit = document.getElementById('leave_preview_limit').value;
            formData.append('preview_limit', previewLimit);
            
            fetch('<?php echo BASE_URL; ?>/api/preview_csv.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('服务器预览响应:', data);
                
                // 清空加载状态
                if (leaveBatchPreviewTbody) leaveBatchPreviewTbody.innerHTML = '';
                
                if (data.success) {
                    // 显示预览数据
                    data.data.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${row.class || ''}</td>
                            <td>${row.name || ''}</td>
                            <td>${row.student_id || ''}</td>
                            <td><span class="text-muted">未检测</span></td>
                        `;
                        if (leaveBatchPreviewTbody) leaveBatchPreviewTbody.appendChild(tr);
                    });
                    
                    // 更新统计信息
                    if (leaveBatchPreviewSummary) {
                        let summaryText = `共 ${data.total} 行数据`;
                        if (data.is_full_preview) {
                            summaryText += `，已显示全部`;
                        } else if (data.total > data.preview_count) {
                            summaryText += `，已预览 ${data.preview_count} 行`;
                        }
                        if (data.encoding_detected && data.encoding_detected !== 'UTF-8') {
                            summaryText += ` (已从${data.encoding_detected}转换为UTF-8)`;
                        }
                        leaveBatchPreviewSummary.textContent = summaryText;
                    }
                    
                    // 显示在线检测按钮
                    const validateBtn = document.getElementById('validateStudentsBtn');
                    if (validateBtn) {
                        validateBtn.style.display = 'inline-block';
                    }
                    
                    // 隐藏验证结果摘要
                    const validationSummary = document.getElementById('validation_summary');
                    if (validationSummary) {
                        validationSummary.style.display = 'none';
                    }
                } else {
                    // 显示错误信息
                    if (leaveBatchPreviewTbody) {
                        leaveBatchPreviewTbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> ${data.message}</td></tr>`;
                    }
                    if (leaveBatchPreviewSummary) {
                        leaveBatchPreviewSummary.textContent = '预览失败';
                    }
                }
            })
            .catch(error => {
                console.error('预览请求失败:', error);
                if (leaveBatchPreviewTbody) {
                    leaveBatchPreviewTbody.innerHTML = '<tr><td colspan="4" class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> 网络请求失败</td></tr>';
                }
                if (leaveBatchPreviewSummary) {
                    leaveBatchPreviewSummary.textContent = '预览失败';
                }
            });
        });
    }
    
    // 预览行数选择器变化时自动重新预览
    const leavePreviewLimitSelect = document.getElementById('leave_preview_limit');
    if (leavePreviewLimitSelect && leaveBatchFileInput) {
        leavePreviewLimitSelect.addEventListener('change', function() {
            // 如果已经选择了文件，自动重新预览
            if (leaveBatchFileInput.files[0]) {
                // 触发文件输入的change事件来重新预览
                const event = new Event('change');
                leaveBatchFileInput.dispatchEvent(event);
            }
        });
    }
    
    // 在线检测按钮事件（修复版：检测文件中的全部数据，不只是预览的行）
    const validateStudentsBtn = document.getElementById('validateStudentsBtn');
    if (validateStudentsBtn) {
        validateStudentsBtn.addEventListener('click', function() {
            console.log('开始在线检测学号（读取完整文件）');
            
            // 获取当前上传的文件
            const file = leaveBatchFileInput.files[0];
            if (!file) {
                alert('请先选择文件');
                return;
            }
            
            // 更新按钮状态
            const originalText = validateStudentsBtn.innerHTML;
            validateStudentsBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>读取完整文件中...';
            validateStudentsBtn.disabled = true;
            
            // ⭐ 重新读取完整文件（preview_limit=0 表示读取全部）
            const formData = new FormData();
            formData.append('preview_file', file);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            formData.append('preview_limit', '0');  // ⭐ 0 = 读取全部数据
            
            fetch('<?php echo BASE_URL; ?>/api/preview_csv.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(previewData => {
                console.log('完整文件数据:', previewData);
                
                if (!previewData.success) {
                    validateStudentsBtn.innerHTML = originalText;
                    validateStudentsBtn.disabled = false;
                    alert('读取文件失败：' + previewData.message);
                    return;
                }
                
                // ⭐ 从完整数据中提取所有学号
                const studentIds = [];
                previewData.data.forEach(row => {
                    if (row.student_id && row.student_id.trim()) {
                        studentIds.push(row.student_id.trim());
                    }
                });
                
                if (studentIds.length === 0) {
                    validateStudentsBtn.innerHTML = originalText;
                    validateStudentsBtn.disabled = false;
                    alert('文件中没有找到有效的学号');
                    return;
                }
                
                console.log(`从文件中提取了 ${studentIds.length} 个学号（不受预览限制）`);
                
                // 更新按钮状态
                validateStudentsBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>验证 ' + studentIds.length + ' 个学号...';
                
                // 发送验证请求
                const validateFormData = new FormData();
                studentIds.forEach(id => validateFormData.append('student_ids[]', id));
                validateFormData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
                
                return fetch('<?php echo BASE_URL; ?>/api/validate_students.php', {
                    method: 'POST',
                    body: validateFormData
                });
            })
            .then(response => response.json())
            .then(data => {
                console.log('验证响应:', data);
                
                // 恢复按钮状态
                validateStudentsBtn.innerHTML = originalText;
                validateStudentsBtn.disabled = false;
                
                if (data.success) {
                    // ⭐ 更新DOM中可见行的验证状态
                    const rows = leaveBatchPreviewTbody.querySelectorAll('tr');
                    rows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        if (cells.length >= 4) {
                            const studentId = cells[2].textContent.trim();
                            const statusCell = cells[3];
                            
                            if (data.results[studentId]) {
                                const result = data.results[studentId];
                                if (result.exists) {
                                    statusCell.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> 存在</span>';
                                    // 如果系统中有信息，更新班级和姓名
                                    if (result.class && !cells[0].textContent.trim()) {
                                        cells[0].textContent = result.class;
                                    }
                                    if (result.name && !cells[1].textContent.trim()) {
                                        cells[1].textContent = result.name;
                                    }
                                } else {
                                    statusCell.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> 不存在</span>';
                                }
                            }
                        }
                    });
                    
                    // ⭐ 显示完整的验证摘要（包含所有数据，不只是预览的）
                    const validationSummary = document.getElementById('validation_summary');
                    const summaryContent = document.getElementById('validation_summary_content');
                    
                    if (validationSummary && summaryContent) {
                        let summaryHtml = `
                            <div class="row">
                                <div class="col-md-4">
                                    <span class="text-success"><i class="fas fa-check-circle"></i> 有效学号：${data.summary.valid}</span>
                                </div>
                                <div class="col-md-4">
                                    <span class="text-danger"><i class="fas fa-times-circle"></i> 无效学号：${data.summary.invalid}</span>
                                </div>
                                <div class="col-md-4">
                                    <span class="text-info"><i class="fas fa-info-circle"></i> 总计：${data.summary.total}</span>
                                </div>
                            </div>
                        `;
                        
                        if (data.summary.invalid > 0) {
                            summaryHtml += '<div class="mt-2 text-warning"><i class="fas fa-exclamation-triangle"></i> 提示：无效学号将在上传时被跳过</div>';
                        }
                        
                        // ⭐ 如果验证的数据比预览的多，显示提示
                        const previewedRows = leaveBatchPreviewTbody.querySelectorAll('tr').length;
                        if (data.summary.total > previewedRows) {
                            summaryHtml += `<div class="mt-2 text-info"><i class="fas fa-info-circle"></i> 已验证完整文件中的 ${data.summary.total} 条数据（预览仅显示 ${previewedRows} 条）</div>`;
                        }
                        
                        summaryContent.innerHTML = summaryHtml;
                        validationSummary.style.display = 'block';
                    }
                } else {
                    alert('验证失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('验证请求失败:', error);
                validateStudentsBtn.innerHTML = originalText;
                validateStudentsBtn.disabled = false;
                alert('网络请求失败，请重试');
            });
        });
    }
    
    // 模态框重置功能
    const batchLeaveModal = document.getElementById('batchLeaveModal');
    if (batchLeaveModal) {
        batchLeaveModal.addEventListener('hidden.bs.modal', function() {
            // 重置表单
            const form = document.getElementById('leaveBatchUploadForm');
            if (form) form.reset();
            
            // 隐藏预览和检测按钮
            if (leaveBatchPreviewDiv) leaveBatchPreviewDiv.style.display = 'none';
            if (leaveBatchPreviewTbody) leaveBatchPreviewTbody.innerHTML = '';
            if (leaveBatchPreviewSummary) leaveBatchPreviewSummary.textContent = '';
            
            const validateBtn = document.getElementById('validateStudentsBtn');
            if (validateBtn) validateBtn.style.display = 'none';
            
            const validationSummary = document.getElementById('validation_summary');
            if (validationSummary) validationSummary.style.display = 'none';
        });
    }
});
</script>

<!-- 下载请假数据模态框 -->
<div class="modal fade" id="downloadLeaveDataModal" tabindex="-1" aria-labelledby="downloadLeaveDataModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="downloadLeaveDataModalLabel">
                    <i class="bi bi-download me-2"></i>下载请假信息
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <h6><i class="bi bi-info-circle me-2"></i>说明</h6>
                    <p class="mb-2"><strong>将下载指定日期的请假学生信息Excel文件（.xlsx格式）</strong></p>
                    <p class="mb-2">✨ <strong>新功能：按楼号自动分sheet</strong></p>
                    <ul class="mb-2">
                        <li>每个楼号独立一个工作表（Sheet）</li>
                        <li>例如：6号楼、7号楼、9号楼等各自一个sheet</li>
                        <li>方便楼管理员快速查看本楼请假情况</li>
                    </ul>
                    <p class="mb-2"><strong>包含以下字段：</strong></p>
                    <ul class="mb-0">
                        <li>序号（递增编号）</li>
                        <li>房间号（格式：楼号#区域房间号，如：10#A104）</li>
                        <li>床号</li>
                        <li>姓名</li>
                        <li>辅导员</li>
                        <li>辅导员联系方式</li>
                        <li>每个sheet底部显示该楼请假人数统计</li>
                    </ul>
                </div>
                
                <form id="downloadLeaveDataForm">
                    <div class="mb-3">
                        <label for="download_data_date" class="form-label">选择日期</label>
                        <input type="date" class="form-control" id="download_data_date" name="date" value="<?php echo $queryDate; ?>" required tabindex="-1">
                        <div class="form-text">选择要下载请假信息的日期</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" tabindex="-1">取消</button>
                <button type="button" class="btn btn-primary" id="confirmDownloadLeaveDataBtn" tabindex="-1">
                    <i class="bi bi-download me-1"></i>下载
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// 下载请假数据功能
document.addEventListener('DOMContentLoaded', function() {
    const confirmBtn = document.getElementById('confirmDownloadLeaveDataBtn');
    const modalElement = document.getElementById('downloadLeaveDataModal');
    
    // 修复可访问性问题 - 模态框显示/隐藏时正确设置tabindex
    if (modalElement) {
        modalElement.addEventListener('shown.bs.modal', function() {
            // 模态框显示时，移除aria-hidden，允许内部元素获得焦点
            modalElement.removeAttribute('aria-hidden');
            const buttons = modalElement.querySelectorAll('button');
            buttons.forEach(btn => btn.removeAttribute('tabindex'));
        });
        
        modalElement.addEventListener('hidden.bs.modal', function() {
            // 模态框隐藏时，设置aria-hidden，阻止内部元素获得焦点
            modalElement.setAttribute('aria-hidden', 'true');
            const buttons = modalElement.querySelectorAll('button');
            buttons.forEach(btn => btn.setAttribute('tabindex', '-1'));
        });
    }
    
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            try {
                const form = document.getElementById('downloadLeaveDataForm');
                if (!form) {
                    console.error('下载表单未找到');
                    return;
                }
                
                const formData = new FormData(form);
                
                // 添加action参数
                const params = new URLSearchParams();
                params.append('action', 'download_leave_data');
                
                // 添加日期参数
                const date = formData.get('date');
                if (date) {
                    params.append('date', date);
                } else {
                    alert('请选择日期');
                    return;
                }
                
                // 触发下载 - 使用临时链接确保用户留在当前页面
                const downloadUrl = '<?php echo BASE_URL; ?>/api/download_leave_data.php?' + params.toString().replace('action=download_leave_data&', '');
                console.log('下载URL:', downloadUrl); // 添加调试信息
                
                // 创建临时下载链接
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = ''; // 启用下载属性
                link.style.display = 'none';
                document.body.appendChild(link);
                
                // 触发点击下载
                link.click();
                
                // 清理临时元素
                setTimeout(function() {
                    document.body.removeChild(link);
                }, 100);
                
                // 关闭模态框
                const modalElement = document.getElementById('downloadLeaveDataModal');
                if (modalElement) {
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    if (modal) {
                        modal.hide();
                    } else {
                        // 如果没有实例，尝试创建并隐藏
                        const newModal = new bootstrap.Modal(modalElement);
                        newModal.hide();
                    }
                }
            } catch (error) {
                console.error('下载请假数据时出错:', error);
                alert('下载失败，请重试');
            }
        });
    } else {
        console.error('下载按钮未找到');
    }
});
</script>

<!-- 周末离校请假单下载模态框 -->
<div class="modal fade" id="downloadWeekendModal" tabindex="-1" aria-labelledby="downloadWeekendModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="downloadWeekendModalLabel">
                    <i class="bi bi-calendar-week me-2"></i>下载周末离校请假单
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <h6><i class="bi bi-info-circle me-2"></i>说明</h6>
                    <ul class="mb-0">
                        <li>适用于周末、节假日等多天请假情况</li>
                        <li>按公寓号自动分sheet（每个楼一个工作表）</li>
                        <li>包含完整的表头、签字栏等信息</li>
                        <li>支持2-7天的日期范围选择</li>
                    </ul>
                </div>
                
                <form id="downloadWeekendForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="weekend_start_date" class="form-label">请假开始日期 <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="weekend_start_date" name="start_date" required>
                        </div>
                        <div class="col-md-6">
                            <label for="weekend_end_date" class="form-label">请假结束日期 <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="weekend_end_date" name="end_date" required>
                        </div>
                    </div>
                    
                    <!-- 快速选择按钮 -->
                    <div class="mb-3">
                        <label class="form-label">快速选择：</label>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="quick_this_weekend">
                                <i class="bi bi-calendar2-week me-1"></i>本周末(2天)
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="quick_small_holiday">
                                <i class="bi bi-calendar2-range me-1"></i>小长假(3天)
                            </button>
                        </div>
                    </div>
                    
                    <!-- 日期预览 -->
                    <div id="weekend_date_preview" class="alert alert-light border" style="display: none;">
                        <strong>📅 将生成请假单：</strong>
                        <div id="weekend_date_list" class="mt-2"></div>
                        <div id="weekend_week_number" class="mt-2 text-muted small"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="confirmDownloadWeekendBtn">
                    <i class="bi bi-download me-1"></i>生成Excel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// 周末离校请假单下载功能
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('weekend_start_date');
    const endDateInput = document.getElementById('weekend_end_date');
    const previewDiv = document.getElementById('weekend_date_preview');
    const dateListDiv = document.getElementById('weekend_date_list');
    const weekNumberDiv = document.getElementById('weekend_week_number');
    const confirmBtn = document.getElementById('confirmDownloadWeekendBtn');
    
    // 日期变化时更新预览
    function updateWeekendPreview() {
        const startDate = startDateInput.value;
        const endDate = endDateInput.value;
        
        if (!startDate || !endDate) {
            previewDiv.style.display = 'none';
            return;
        }
        
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        if (start > end) {
            alert('开始日期不能晚于结束日期');
            endDateInput.value = startDate;
            return;
        }
        
        // 计算日期范围
        const dates = [];
        const current = new Date(start);
        while (current <= end) {
            dates.push(new Date(current));
            current.setDate(current.getDate() + 1);
        }
        
        // 限制最多7天
        if (dates.length > 7) {
            alert('日期范围不能超过7天');
            endDateInput.value = '';
            return;
        }
        
        // 显示日期列表
        const weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
        let html = `<strong>共 ${dates.length} 天：</strong><br>`;
        dates.forEach(date => {
            const dateStr = date.toLocaleDateString('zh-CN', {year: 'numeric', month: '2-digit', day: '2-digit'});
            const weekday = weekdays[date.getDay()];
            html += `<span class="badge bg-primary me-2 mb-1">${dateStr} (${weekday})</span>`;
        });
        dateListDiv.innerHTML = html;
        
        // 计算周次
        const weekNumber = calculateWeekNumber(start);
        weekNumberDiv.textContent = `第 ${weekNumber} 周`;
        
        previewDiv.style.display = 'block';
    }
    
    // 计算周次
    function calculateWeekNumber(date) {
        const month = date.getMonth() + 1;
        const year = date.getFullYear();
        
        let semesterStart;
        if (month >= 9) {
            // 秋季学期：9月第一个周一
            semesterStart = new Date(year, 8, 1); // 9月1日
        } else {
            // 春季学期：3月第一个周一
            semesterStart = new Date(year, 2, 1); // 3月1日
        }
        
        // 找到第一个周一
        while (semesterStart.getDay() !== 1) {
            semesterStart.setDate(semesterStart.getDate() + 1);
        }
        
        // 计算周数差
        const diffTime = date - semesterStart;
        const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
        const weekNumber = Math.floor(diffDays / 7) + 1;
        
        return Math.max(1, weekNumber);
    }
    
    // 监听日期变化
    startDateInput.addEventListener('change', updateWeekendPreview);
    endDateInput.addEventListener('change', updateWeekendPreview);
    
    // 快速选择：本周末
    document.getElementById('quick_this_weekend').addEventListener('click', function() {
        const today = new Date();
        const dayOfWeek = today.getDay();
        
        // 计算本周五
        const friday = new Date(today);
        friday.setDate(today.getDate() + (5 - dayOfWeek + (dayOfWeek === 0 ? -2 : 0)));
        
        // 计算本周六
        const saturday = new Date(friday);
        saturday.setDate(friday.getDate() + 1);
        
        startDateInput.value = friday.toISOString().split('T')[0];
        endDateInput.value = saturday.toISOString().split('T')[0];
        updateWeekendPreview();
    });
    
    // 快速选择：小长假（3天）
    document.getElementById('quick_small_holiday').addEventListener('click', function() {
        const today = new Date();
        const dayOfWeek = today.getDay();
        
        // 计算本周五
        const friday = new Date(today);
        friday.setDate(today.getDate() + (5 - dayOfWeek + (dayOfWeek === 0 ? -2 : 0)));
        
        // 计算本周日
        const sunday = new Date(friday);
        sunday.setDate(friday.getDate() + 2);
        
        startDateInput.value = friday.toISOString().split('T')[0];
        endDateInput.value = sunday.toISOString().split('T')[0];
        updateWeekendPreview();
    });
    
    // 下载按钮点击
    confirmBtn.addEventListener('click', function() {
        const startDate = startDateInput.value;
        const endDate = endDateInput.value;
        
        if (!startDate || !endDate) {
            alert('请选择日期范围');
            return;
        }
        
        // 触发下载
        const downloadUrl = '<?php echo BASE_URL; ?>/api/download_weekend_leave.php?start=' + 
                           encodeURIComponent(startDate) + '&end=' + encodeURIComponent(endDate);
        
        // 创建临时链接下载
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = '';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        
        setTimeout(function() {
            document.body.removeChild(link);
        }, 100);
        
        // 关闭模态框
        const modal = bootstrap.Modal.getInstance(document.getElementById('downloadWeekendModal'));
        if (modal) {
            modal.hide();
        }
    });
    
    // 模态框关闭时重置
    document.getElementById('downloadWeekendModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('downloadWeekendForm').reset();
        previewDiv.style.display = 'none';
    });
});
</script>

<?php
// 加载页脚
include 'templates/footer.php';
?> 