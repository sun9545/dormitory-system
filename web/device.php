<?php
/**
 * 设备管理页面
 */
require_once 'config/config.php';
$pageTitle = '设备管理 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/device.php';

// 获取当前动作
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// 获取分页参数
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

// 初始化消息
$message = '';
$messageType = '';

// 创建设备模型实例
$deviceModel = new Device();

// 处理设备添加
if ($action === 'add' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/device.php');
    }
    
    $deviceId = sanitizeInput($_POST['device_id']);
    $deviceName = sanitizeInput($_POST['device_name']);
    $buildingNumber = (int)$_POST['building_number'];
    $deviceSequence = (int)$_POST['device_sequence'];
    $maxFingerprints = isset($_POST['max_fingerprints']) ? (int)$_POST['max_fingerprints'] : 1000;
    
    // 验证必填字段
    if (empty($deviceId) || empty($deviceName) || $buildingNumber <= 0 || $deviceSequence <= 0) {
        $_SESSION['message'] = '请填写所有必填字段';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/device.php?action=add');
    }
    
    $result = $deviceModel->addDevice([
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'building_number' => $buildingNumber,
        'device_sequence' => $deviceSequence,
        'max_fingerprints' => $maxFingerprints
    ]);
    
    if ($result['success']) {
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = 'success';
        redirect(BASE_URL . '/device.php');
    } else {
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/device.php?action=add');
    }
}

// 处理设备更新
if ($action === 'update' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/device.php');
    }
    
    $deviceId = sanitizeInput($_POST['device_id']);
    $deviceName = sanitizeInput($_POST['device_name']);
    $buildingNumber = (int)$_POST['building_number'];
    $deviceSequence = (int)$_POST['device_sequence'];
    $maxFingerprints = (int)$_POST['max_fingerprints'];
    $status = sanitizeInput($_POST['status']);
    
    if ($deviceModel->updateDevice($deviceId, $deviceName, $buildingNumber, $deviceSequence, $maxFingerprints, $status)) {
        $_SESSION['message'] = '设备更新成功';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = '设备更新失败';
        $_SESSION['message_type'] = 'danger';
    }
    
    redirect(BASE_URL . '/device.php');
}

// 处理设备删除
if ($action === 'delete' && getRequestMethod() === 'POST') {
    // 验证CSRF令牌
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'] = '安全验证失败，请重试';
        $_SESSION['message_type'] = 'danger';
        redirect(BASE_URL . '/device.php');
    }
    
    $deviceId = sanitizeInput($_POST['device_id']);
    
    $result = $deviceModel->deleteDevice($deviceId);
    
    if ($result['success']) {
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = 'danger';
    }
    
    redirect(BASE_URL . '/device.php');
}

// 获取设备列表
$devices = $deviceModel->getAllDevices();
$totalDevices = count($devices);

// 获取单个设备信息（用于编辑）
$editDevice = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $editDevice = $deviceModel->getDeviceById($_GET['id']);
}

// 检查是否有会话消息
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

require_once 'templates/header.php';
?>

<div class="container-fluid">
    <!-- 页面标题 -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">设备管理</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDeviceModal">
            <i class="fas fa-plus fa-sm text-white-50"></i> 添加设备
        </button>
    </div>

    <!-- 消息提示 -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- 设备列表 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">设备列表</h6>
        </div>
        <div class="card-body">
            <?php if (empty($devices)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-microchip fa-3x text-gray-300 mb-3"></i>
                    <h5 class="text-gray-600">暂无设备</h5>
                    <p class="text-gray-500">点击右上角的"添加设备"按钮来添加第一个设备</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr class="text-center">
                                <th>设备ID</th>
                                <th>设备名称</th>
                                <th>楼号</th>
                                <th>设备序号</th>
                                <th>最大指纹数</th>
                                <th>状态</th>
                                <th>最后在线时间</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $device): ?>
                                <tr class="text-center" data-device-id="<?php echo htmlspecialchars($device['device_id']); ?>">
                                    <td><?php echo htmlspecialchars($device['device_id']); ?></td>
                                    <td><?php echo htmlspecialchars($device['device_name']); ?></td>
                                    <td><?php echo $device['building_number']; ?></td>
                                    <td><?php echo $device['device_sequence']; ?></td>
                                    <td><?php echo $device['max_fingerprints']; ?></td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        $statusText = '';
                                        $isRecentlyOnline = false;
                                        
                                        // 智能在线检测：结合签到和心跳
                                        if ($device['last_seen']) {
                                            $lastSeenTime = strtotime($device['last_seen']);
                                            $currentTime = time();
                                            $timeDiff = $currentTime - $lastSeenTime;
                                            
                                            // 智能阈值策略：
                                            // 1. 最近30秒内有活动（签到）-> 立即在线
                                            // 2. 最近120秒内有活动（心跳）-> 在线  
                                            $isRecentlyOnline = ($timeDiff <= 120); // 120秒 = 心跳阈值
                                        }
                                        
                                        switch ($device['status']) {
                                            case 'active':
                                                if ($isRecentlyOnline) {
                                                    $statusClass = 'success';
                                                    $statusText = '在线';
                                                } else {
                                                    $statusClass = 'danger';
                                                    $statusText = '离线';
                                                }
                                                break;
                                            case 'inactive':
                                                $statusClass = 'secondary';
                                                $statusText = '已停用';
                                                break;
                                            case 'maintenance':
                                                $statusClass = 'warning';
                                                $statusText = '维护中';
                                                break;
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?> device-status" 
                                              title="管理状态: <?php echo $device['status']; ?><?php if ($device['last_seen']): ?>, 最后活动: <?php echo date('Y-m-d H:i:s', strtotime($device['last_seen'])); ?><?php endif; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="last-seen-time">
                                        <?php 
                                        if ($device['last_seen']) {
                                            echo date('Y-m-d H:i:s', strtotime($device['last_seen']));
                                        } else {
                                            echo '<span class="text-muted">从未在线</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($device['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="editDevice('<?php echo htmlspecialchars($device['device_id']); ?>')">
                                            编辑
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="showDeleteModal('<?php echo htmlspecialchars($device['device_id']); ?>', '<?php echo htmlspecialchars($device['device_name']); ?>')">
                                            删除
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
</div>

<!-- 添加设备模态框 -->
<div class="modal fade" id="addDeviceModal" tabindex="-1" aria-labelledby="addDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addDeviceModalLabel">添加设备</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/device.php?action=add">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="device_id" class="form-label">设备ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="device_id" name="device_id" required
                               placeholder="例如：FP001-10-1">
                        <div class="form-text">建议格式：前缀-楼号-序号，如 FP001-10-1</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="device_name" class="form-label">设备名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="device_name" name="device_name" required
                               placeholder="例如：10号楼1层指纹设备">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="building_number" class="form-label">楼号 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="building_number" name="building_number" 
                                       min="1" max="99" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="device_sequence" class="form-label">设备序号 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="device_sequence" name="device_sequence" 
                                       min="1" max="99" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="max_fingerprints" class="form-label">最大指纹数</label>
                        <input type="number" class="form-control" id="max_fingerprints" name="max_fingerprints" 
                               value="1000" min="1" max="10000">
                        <div class="form-text">默认1000个指纹容量</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">添加设备</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑设备模态框 -->
<div class="modal fade" id="editDeviceModal" tabindex="-1" aria-labelledby="editDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editDeviceModalLabel">编辑设备</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/device.php?action=update" id="editDeviceForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="device_id" id="edit_device_id">
                    
                    <div class="mb-3">
                        <label for="edit_device_name" class="form-label">设备名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_device_name" name="device_name" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_building_number" class="form-label">楼号 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_building_number" name="building_number" 
                                       min="1" max="99" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_device_sequence" class="form-label">设备序号 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_device_sequence" name="device_sequence" 
                                       min="1" max="99" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_max_fingerprints" class="form-label">最大指纹数</label>
                        <input type="number" class="form-control" id="edit_max_fingerprints" name="max_fingerprints" 
                               min="1" max="10000">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">设备状态</label>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="active">正常</option>
                            <option value="inactive">离线</option>
                            <option value="maintenance">维护</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存更改</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 删除确认模态框 -->
<div class="modal fade" id="deleteDeviceModal" tabindex="-1" aria-labelledby="deleteDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteDeviceModalLabel">确认删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>确定要删除设备 <strong id="deleteDeviceName"></strong> 吗？</p>
                <p class="text-danger">此操作将同时删除该设备的所有指纹映射记录，且无法撤销！</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">确认删除</button>
            </div>
        </div>
    </div>
</div>

<script>
// 防止浏览器缓存导致的问题
console.log('设备管理页面脚本加载 - 版本 2.0');
// 编辑设备
function editDevice(deviceId) {
    // 通过AJAX获取设备详情
    fetch('<?php echo BASE_URL; ?>/api/device_info.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            action: 'get_device',
            device_id: deviceId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const device = data.device;
            document.getElementById('edit_device_id').value = device.device_id;
            document.getElementById('edit_device_name').value = device.device_name;
            document.getElementById('edit_building_number').value = device.building_number;
            document.getElementById('edit_device_sequence').value = device.device_sequence;
            document.getElementById('edit_max_fingerprints').value = device.max_fingerprints;
            document.getElementById('edit_status').value = device.status;
            
            const editModal = new bootstrap.Modal(document.getElementById('editDeviceModal'));
            editModal.show();
        } else {
            alert('获取设备信息失败：' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('获取设备信息时发生错误');
    });
}

// 删除设备
let currentDeleteDeviceId = '';

// 显示删除模态框
function showDeleteModal(deviceId, deviceName) {
    console.log('显示删除模态框:', deviceId, deviceName);
    currentDeleteDeviceId = deviceId;
    document.getElementById('deleteDeviceName').textContent = deviceName;
    
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteDeviceModal'));
    deleteModal.show();
}

// 页面加载完成后绑定事件
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM加载完成，绑定删除事件');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    if (confirmBtn) {
        console.log('找到确认删除按钮，绑定点击事件');
        
        // 清除所有现有的事件监听器
        confirmBtn.replaceWith(confirmBtn.cloneNode(true));
        const newConfirmBtn = document.getElementById('confirmDeleteBtn');
        
        newConfirmBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('确认删除按钮被点击');
            
            if (!currentDeleteDeviceId) {
                alert('未选择要删除的设备');
                return;
            }
            
            console.log('开始删除设备:', currentDeleteDeviceId);
            
            // 显示加载状态
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 删除中...';
            
            // 创建并提交表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo BASE_URL; ?>/device.php?action=delete';
            
            // 添加CSRF token
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo generateCSRFToken(); ?>';
            form.appendChild(csrfInput);
            
            // 添加设备ID
            const deviceInput = document.createElement('input');
            deviceInput.type = 'hidden';
            deviceInput.name = 'device_id';
            deviceInput.value = currentDeleteDeviceId;
            form.appendChild(deviceInput);
            
            console.log('提交删除表单');
            
            // 提交表单
            document.body.appendChild(form);
            form.submit();
        });
    } else {
        console.log('未找到确认删除按钮');
    }
});

// 设备状态实时检测
let statusUpdateInterval;
let isUpdating = false;

function updateDeviceStatuses() {
    if (isUpdating) return; // 防止重复请求
    
    isUpdating = true;
    
    fetch('<?php echo BASE_URL; ?>/api/device_status.php', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 更新每个设备的状态
            data.devices.forEach(device => {
                const statusElement = document.querySelector(`[data-device-id="${device.device_id}"] .device-status`);
                const timeElement = document.querySelector(`[data-device-id="${device.device_id}"] .last-seen-time`);
                
                if (statusElement) {
                    // 更新状态徽章
                    statusElement.className = `badge bg-${device.status_class} device-status`;
                    statusElement.textContent = device.status_text;
                    
                    // 更新工具提示
                    const tooltip = `管理状态: ${device.management_status}`;
                    const lastSeenTooltip = device.last_seen_formatted ? `, 最后活动: ${device.last_seen_formatted}` : '';
                    statusElement.title = tooltip + lastSeenTooltip;
                }
                
                if (timeElement) {
                    // 更新最后在线时间
                    if (device.last_seen_formatted) {
                        timeElement.textContent = device.last_seen_formatted;
                        timeElement.className = 'last-seen-time';
                    } else {
                        timeElement.innerHTML = '<span class="text-muted">从未在线</span>';
                        timeElement.className = 'last-seen-time text-muted';
                    }
                }
            });
            
            // 更新页面标题显示刷新时间
            const refreshTime = new Date().toLocaleTimeString();
            console.log(`设备状态已更新 - ${refreshTime}`);
            
            // 在页面顶部显示最后更新时间（可选）
            let updateTimeElement = document.getElementById('status-update-time');
            if (!updateTimeElement) {
                updateTimeElement = document.createElement('small');
                updateTimeElement.id = 'status-update-time';
                updateTimeElement.className = 'text-muted ms-2';
                const titleElement = document.querySelector('h1');
                if (titleElement) {
                    titleElement.appendChild(updateTimeElement);
                }
            }
            if (updateTimeElement) {
                updateTimeElement.textContent = `(最后更新: ${refreshTime})`;
            }
        }
    })
    .catch(error => {
        console.error('更新设备状态失败:', error);
    })
    .finally(() => {
        isUpdating = false;
    });
}

// 启动实时检测
function startStatusUpdates() {
    // 立即更新一次
    updateDeviceStatuses();
    
    // 每15秒更新一次
    statusUpdateInterval = setInterval(updateDeviceStatuses, 15000);
    
    console.log('设备状态实时检测已启动 (每15秒更新)');
}

// 停止实时检测
function stopStatusUpdates() {
    if (statusUpdateInterval) {
        clearInterval(statusUpdateInterval);
        statusUpdateInterval = null;
        console.log('设备状态实时检测已停止');
    }
}

// 页面加载完成后启动实时检测
document.addEventListener('DOMContentLoaded', function() {
    startStatusUpdates();
});

// 页面隐藏时停止检测，显示时重新启动（节省资源）
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopStatusUpdates();
    } else {
        startStatusUpdates();
    }
});

// 页面卸载时停止检测
window.addEventListener('beforeunload', function() {
    stopStatusUpdates();
});
</script>

<?php require_once 'templates/footer.php'; ?>