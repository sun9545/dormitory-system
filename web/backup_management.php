    <?php
/**
 * 数据库备份管理页面
 * 功能：查看备份列表、下载备份、删除备份
 */

require_once 'config/config.php';
require_once 'utils/auth.php';

// 检查登录和权限（仅管理员可访问）
startSecureSession();
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// 备份目录
$backupDir = '/www/backup/database/student_dorm';
$logFile = $backupDir . '/backup.log';

// 处理操作
$action = $_GET['action'] ?? '';

if ($action === 'download' && isset($_GET['file'])) {
    // 下载备份文件
    $filename = basename($_GET['file']);
    $filepath = $backupDir . '/' . $filename;
    
    if (file_exists($filepath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

if ($action === 'delete' && isset($_GET['file']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 删除备份文件
    $filename = basename($_GET['file']);
    $filepath = $backupDir . '/' . $filename;
    
    if (file_exists($filepath)) {
        unlink($filepath);
        $_SESSION['message'] = '备份文件已删除';
        $_SESSION['message_type'] = 'success';
    }
    header('Location: ' . BASE_URL . '/backup_management.php');
    exit;
}

// 获取备份文件列表
$backupFiles = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/*.sql.gz');
    rsort($files); // 最新的在前面
    
    foreach ($files as $file) {
        $backupFiles[] = [
            'filename' => basename($file),
            'size' => filesize($file),
            'time' => filemtime($file),
            'path' => $file
        ];
    }
}

// 获取备份日志（最后20行）
$logContent = '';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $logContent = implode('', array_slice($lines, -20));
}

$currentPage = 'backup_management.php';
include 'templates/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h2><i class="bi bi-database"></i> 数据库备份管理</h2>
            
            <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show">
                <?php 
                echo htmlspecialchars($_SESSION['message']); 
                unset($_SESSION['message'], $_SESSION['message_type']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- 备份信息卡片 -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="card-title">备份文件数量</h5>
                            <h2 class="text-primary"><?php echo count($backupFiles); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="card-title">总占用空间</h5>
                            <h2 class="text-success">
                                <?php 
                                $totalSize = array_sum(array_column($backupFiles, 'size'));
                                echo number_format($totalSize / 1024 / 1024, 2) . ' MB';
                                ?>
                            </h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="card-title">下次备份时间</h5>
                            <h2 class="text-info">明天 03:00</h2>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 说明信息 -->
            <div class="alert alert-info">
                <h6 class="alert-heading"><i class="bi bi-info-circle"></i> 自动备份说明</h6>
                <ul class="mb-0">
                    <li>系统每天<strong>凌晨3点</strong>自动备份数据库</li>
                    <li>备份文件自动压缩，节省空间</li>
                    <li>自动保留<strong>最近7天</strong>的备份，7天前的自动删除</li>
                    <li>备份文件存储在：<code>/www/backup/database/student_dorm/</code></li>
                    <li>你<strong>无需</strong>手动操作，系统全自动运行</li>
                </ul>
            </div>
            
            <!-- 备份文件列表 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-zip"></i> 备份文件列表</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($backupFiles)): ?>
                        <div class="alert alert-warning">
                            暂无备份文件。首次备份将在明天凌晨3点自动执行。
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr class="text-center">
                                        <th>文件名</th>
                                        <th>备份时间</th>
                                        <th>文件大小</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backupFiles as $file): ?>
                                    <tr class="text-center align-middle">
                                        <td class="text-start">
                                            <i class="bi bi-file-zip text-primary"></i>
                                            <code><?php echo htmlspecialchars($file['filename']); ?></code>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i:s', $file['time']); ?></td>
                                        <td><?php echo number_format($file['size'] / 1024, 2) . ' KB'; ?></td>
                                        <td>
                                            <a href="?action=download&file=<?php echo urlencode($file['filename']); ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-download"></i> 下载
                                            </a>
                                            <button class="btn btn-sm btn-danger" 
                                                    onclick="confirmDelete('<?php echo htmlspecialchars($file['filename']); ?>')">
                                                <i class="bi bi-trash"></i> 删除
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 备份日志 -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-journal-text"></i> 备份日志（最近20条）</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($logContent)): ?>
                        <div class="alert alert-secondary">暂无日志记录</div>
                    <?php else: ?>
                        <pre class="bg-light p-3" style="max-height: 400px; overflow-y: auto;"><?php echo htmlspecialchars($logContent); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- 删除确认模态框 -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">确认删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>确定要删除备份文件吗？</p>
                <p class="text-danger"><strong>警告：</strong>删除后无法恢复！</p>
                <p><code id="deleteFilename"></code></p>
            </div>
            <div class="modal-footer">
                <form method="post" id="deleteForm">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger">确认删除</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(filename) {
    document.getElementById('deleteFilename').textContent = filename;
    document.getElementById('deleteForm').action = '?action=delete&file=' + encodeURIComponent(filename);
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include 'templates/footer.php'; ?>

