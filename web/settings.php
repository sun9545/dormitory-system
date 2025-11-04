<?php
/**
 * 系统设置页面
 */
require_once 'config/config.php';
$pageTitle = '系统设置 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/student.php';

// 加载页头
include 'templates/header.php';

// 检查管理员权限
requireAdmin();

// 初始化消息
$message = '';
$messageType = '';

// 处理修改密码
if (getRequestMethod() === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $oldPassword = isset($_POST['old_password']) ? $_POST['old_password'] : '';
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = '请填写所有密码字段';
        $messageType = 'danger';
    } elseif ($newPassword !== $confirmPassword) {
        $message = '新密码和确认密码不匹配';
        $messageType = 'danger';
    } else {
        $db = getDBConnection();
        $user = getCurrentUser();
        
        $stmt = $db->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->execute(['id' => $user['id']]);
        $userData = $stmt->fetch();
        
        if (password_verify($oldPassword, $userData['password'])) {
            // 密码正确，更新密码
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
            
            if ($stmt->execute(['password' => $hashedPassword, 'id' => $user['id']])) {
                $message = '密码修改成功';
                $messageType = 'success';
            } else {
                $message = '密码修改失败，请重试';
                $messageType = 'danger';
            }
        } else {
            $message = '当前密码错误';
            $messageType = 'danger';
        }
    }
}

// 处理添加用户
if (getRequestMethod() === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $username = isset($_POST['username']) ? sanitizeInput($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $name = isset($_POST['name']) ? sanitizeInput($_POST['name']) : '';
    $phone = isset($_POST['phone']) ? sanitizeInput($_POST['phone']) : '';
    $role = isset($_POST['role']) ? sanitizeInput($_POST['role']) : '';
    
    if (empty($username) || empty($password) || empty($name) || empty($role)) {
        $message = '请填写所有必填字段';
        $messageType = 'danger';
    } else {
        $db = getDBConnection();
        
        // 检查用户名是否已存在
        $stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        
        if ($stmt->fetch()) {
            $message = '用户名已存在';
            $messageType = 'danger';
        } else {
            // 添加新用户
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password, name, phone, role) VALUES (:username, :password, :name, :phone, :role)");
            
            if ($stmt->execute([
                'username' => $username,
                'password' => $hashedPassword,
                'name' => $name,
                'phone' => $phone,
                'role' => $role
            ])) {
                $message = '用户添加成功';
                $messageType = 'success';
            } else {
                $message = '用户添加失败，请重试';
                $messageType = 'danger';
            }
        }
    }
}

// 获取所有用户
$db = getDBConnection();
$stmt = $db->query("SELECT id, username, name, phone, role FROM users ORDER BY id");
$users = $stmt->fetchAll();

// 获取系统统计数据
$student = new Student();
$studentCount = count($student->getAllStudents(1, 1000000)['students']);

$stmt = $db->query("SELECT COUNT(*) FROM check_records");
$recordCount = $stmt->fetchColumn();

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">系统设置</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <!-- 系统信息卡片 -->
    <div class="col-lg-4">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">系统信息</h6>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tbody>
                        <tr>
                            <th scope="row">系统名称</th>
                            <td><?php echo SITE_NAME; ?></td>
                        </tr>
                        <tr>
                            <th scope="row">PHP版本</th>
                            <td><?php echo phpversion(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">学生数量</th>
                            <td><?php echo $studentCount; ?>人</td>
                        </tr>
                        <tr>
                            <th scope="row">查寝记录</th>
                            <td><?php echo $recordCount; ?>条</td>
                        </tr>
                        <tr>
                            <th scope="row">当前时间</th>
                            <td><?php echo date('Y-m-d H:i:s'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- 修改密码卡片 -->
    <div class="col-lg-8">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">修改密码</h6>
            </div>
            <div class="card-body">
                <form method="post" action="" class="row g-3">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="col-md-4">
                        <label for="old_password" class="form-label">当前密码</label>
                        <input type="password" class="form-control" id="old_password" name="old_password" required>
                    </div>
                    
                    <div class="col-md-4">
                        <label for="new_password" class="form-label">新密码</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                    </div>
                    
                    <div class="col-md-4">
                        <label for="confirm_password" class="form-label">确认新密码</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <div class="col-12 mt-3">
                        <button type="submit" class="btn btn-primary">修改密码</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 用户管理 -->
<div class="row">
    <div class="col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">用户管理</h6>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus"></i> 添加用户
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>用户名</th>
                                <th>姓名</th>
                                <th>电话</th>
                                <th>角色</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['id']; ?></td>
                                        <td><?php echo $user['username']; ?></td>
                                        <td><?php echo $user['name']; ?></td>
                                        <td><?php echo $user['phone']; ?></td>
                                        <td>
                                            <?php if ($user['role'] === 'admin'): ?>
                                                <span class="badge bg-danger">管理员</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">辅导员</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">暂无用户</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加用户模态框 -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">添加用户</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="" id="addUserForm">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">用户名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">密码 <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">姓名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="phone" class="form-label">电话</label>
                        <input type="text" class="form-control" id="phone" name="phone">
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">角色 <span class="text-danger">*</span></label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="">请选择</option>
                            <option value="admin">管理员</option>
                            <option value="counselor">辅导员</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="submit" form="addUserForm" class="btn btn-primary">保存</button>
            </div>
        </div>
    </div>
</div>

<!-- 系统工具卡片 -->
<div class="row">
    <div class="col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">系统工具</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-speedometer2 fs-1 text-primary mb-3"></i>
                                <h5 class="card-title">缓存管理</h5>
                                <p class="card-text small">管理系统缓存，提高性能</p>
                                <a href="<?php echo BASE_URL; ?>/cache_management.php" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-arrow-right"></i> 前往管理
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-shield-check fs-1 text-success mb-3"></i>
                                <h5 class="card-title">登录日志</h5>
                                <p class="card-text small">查看系统登录记录</p>
                                <a href="<?php echo BASE_URL; ?>/login_logs.php" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-arrow-right"></i> 查看日志
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-database-gear fs-1 text-info mb-3"></i>
                                <h5 class="card-title">数据备份</h5>
                                <p class="card-text small">备份系统重要数据</p>
                                <button class="btn btn-outline-info btn-sm" disabled>
                                    <i class="bi bi-arrow-right"></i> 即将上线
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-gear-wide-connected fs-1 text-warning mb-3"></i>
                                <h5 class="card-title">系统优化</h5>
                                <p class="card-text small">优化系统性能</p>
                                <button class="btn btn-outline-warning btn-sm" disabled>
                                    <i class="bi bi-arrow-right"></i> 即将上线
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// 加载页脚
include 'templates/footer.php';
?> 