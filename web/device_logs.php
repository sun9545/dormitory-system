<?php
/**
 * 设备日志查看页面
 * 专门用于查看ESP32设备的WiFi日志
 */
require_once 'config/config.php';
$pageTitle = '设备日志 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/device.php';

// 检查管理员权限
requireAdmin();

// 获取所有设备（用于下拉框）
$deviceModel = new Device();
$allDevices = $deviceModel->getAllDevices();

// 获取筛选条件
$deviceId = isset($_GET['device_id']) ? sanitizeInput($_GET['device_id']) : '';
$logLevel = isset($_GET['log_level']) ? sanitizeInput($_GET['log_level']) : '';
$component = isset($_GET['component']) ? sanitizeInput($_GET['component']) : '';
$date = isset($_GET['date']) ? sanitizeInput($_GET['date']) : date('Y-m-d');
$autoRefresh = isset($_GET['auto_refresh']) ? (bool)$_GET['auto_refresh'] : false;

// 加载页头
include 'templates/header.php';
?>

<style>
.log-container {
    background: #1a1a1a;
    color: #00ff00;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    padding: 15px;
    border-radius: 5px;
    height: 500px;
    overflow-y: auto;
    margin-bottom: 20px;
}

.log-entry {
    margin-bottom: 2px;
    padding: 2px 0;
    border-bottom: 1px solid #333;
}

.log-level-ERROR { color: #ff4444; }
.log-level-WARN { color: #ffaa00; }
.log-level-INFO { color: #00ff00; }
.log-level-DEBUG { color: #00aaff; }

.component-fingerprint { background: rgba(255, 0, 0, 0.1); }
.component-wifi { background: rgba(0, 255, 0, 0.1); }
.component-system { background: rgba(0, 0, 255, 0.1); }
.component-ui { background: rgba(255, 255, 0, 0.1); }

.status-indicator {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 5px;
}

.status-online { background: #00ff00; }
.status-offline { background: #ff4444; }

.refresh-controls {
    text-align: right;
    margin-bottom: 10px;
}
</style>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-microchip"></i> 设备WiFi日志
                        <span id="deviceStatus" class="status-indicator status-offline" title="设备状态"></span>
                    </h5>
                    <div class="refresh-controls">
                        <button id="refreshBtn" class="btn btn-sm btn-primary">
                            <i class="fas fa-sync-alt"></i> 刷新
                        </button>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="autoRefreshCheck" <?= $autoRefresh ? 'checked' : '' ?>>
                            <label class="form-check-label" for="autoRefreshCheck">自动刷新</label>
                        </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- 筛选表单 -->
                    <form method="GET" class="mb-3">
                        <div class="row">
                            <div class="col-md-2">
                                <label for="device_id" class="form-label">设备ID</label>
                                <select name="device_id" id="device_id" class="form-select form-select-sm">
                                    <option value="">所有设备</option>
                                    <?php if (!empty($allDevices)): ?>
                                        <?php foreach ($allDevices as $device): ?>
                                            <option value="<?= htmlspecialchars($device['device_id']) ?>" 
                                                    <?= $deviceId === $device['device_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($device['device_id']) ?> - <?= htmlspecialchars($device['device_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>暂无设备</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="log_level" class="form-label">日志级别</label>
                                <select name="log_level" id="log_level" class="form-select form-select-sm">
                                    <option value="">所有级别</option>
                                    <option value="ERROR" <?= $logLevel === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                                    <option value="WARN" <?= $logLevel === 'WARN' ? 'selected' : '' ?>>WARN</option>
                                    <option value="INFO" <?= $logLevel === 'INFO' ? 'selected' : '' ?>>INFO</option>
                                    <option value="DEBUG" <?= $logLevel === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="component" class="form-label">组件</label>
                                <select name="component" id="component" class="form-select form-select-sm">
                                    <option value="">所有组件</option>
                                    <option value="fingerprint" <?= $component === 'fingerprint' ? 'selected' : '' ?>>指纹传感器</option>
                                    <option value="wifi" <?= $component === 'wifi' ? 'selected' : '' ?>>WiFi</option>
                                    <option value="system" <?= $component === 'system' ? 'selected' : '' ?>>系统</option>
                                    <option value="ui" <?= $component === 'ui' ? 'selected' : '' ?>>界面</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="date" class="form-label">日期</label>
                                <input type="date" name="date" id="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                                    <a href="device_logs.php" class="btn btn-secondary btn-sm">重置</a>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <!-- 实时统计 -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-danger">ERROR</h6>
                                    <h4 id="errorCount">0</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-warning">WARN</h6>
                                    <h4 id="warnCount">0</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-success">INFO</h6>
                                    <h4 id="infoCount">0</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-info">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-info">DEBUG</h6>
                                    <h4 id="debugCount">0</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 日志显示区域 -->
                    <div class="log-container" id="logContainer">
                        <div class="text-center text-muted">加载中...</div>
                    </div>
                    
                    <!-- 分页 -->
                    <div id="pagination" class="d-flex justify-content-center">
                        <!-- 动态生成分页 -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let autoRefreshInterval;
let currentPage = 1;

// 加载日志数据
function loadLogs(page = 1) {
    const params = new URLSearchParams({
        device_id: document.getElementById('device_id').value,
        log_level: document.getElementById('log_level').value,
        component: document.getElementById('component').value,
        date: document.getElementById('date').value,
        page: page,
        limit: 50
    });
    
    fetch(`/api/device_log.php?${params}`, {
        headers: {
            'X-Api-Token': 'GENERATE_YOUR_OWN_API_TOKEN'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayLogs(data.data);
                updateStatistics(data.data);
                updatePagination(data.pagination);
                updateDeviceStatus(data.data);
            } else {
                document.getElementById('logContainer').innerHTML = 
                    '<div class="text-center text-danger">加载失败: ' + data.error + '</div>';
            }
        })
        .catch(error => {
            console.error('加载日志失败:', error);
            document.getElementById('logContainer').innerHTML = 
                '<div class="text-center text-danger">网络错误</div>';
        });
}

// 显示日志
function displayLogs(logs) {
    const container = document.getElementById('logContainer');
    
    if (logs.length === 0) {
        container.innerHTML = '<div class="text-center text-muted">暂无日志数据</div>';
        return;
    }
    
    container.innerHTML = logs.map(log => {
        const timestamp = new Date(log.timestamp).toLocaleString();
        const memoryMB = (log.memory_free / 1024).toFixed(1);
        const uptimeMin = (log.uptime / 60000).toFixed(1);
        
        return `
            <div class="log-entry log-level-${log.log_level} component-${log.component}">
                <span class="text-muted">[${timestamp}]</span>
                <span class="fw-bold">[${log.log_level}]</span>
                <span class="text-info">[${log.device_id}]</span>
                <span class="text-warning">[${log.component}]</span>
                ${log.message}
                <span class="text-muted small">| IP: ${log.ip_address} | MEM: ${memoryMB}KB | UP: ${uptimeMin}min</span>
            </div>
        `;
    }).join('');
}

// 更新统计数据
function updateStatistics(logs) {
    const counts = { ERROR: 0, WARN: 0, INFO: 0, DEBUG: 0 };
    logs.forEach(log => {
        if (counts.hasOwnProperty(log.log_level)) {
            counts[log.log_level]++;
        }
    });
    
    document.getElementById('errorCount').textContent = counts.ERROR;
    document.getElementById('warnCount').textContent = counts.WARN;
    document.getElementById('infoCount').textContent = counts.INFO;
    document.getElementById('debugCount').textContent = counts.DEBUG;
}

// 更新设备状态
function updateDeviceStatus(logs) {
    const statusIndicator = document.getElementById('deviceStatus');
    
    // 如果最近5分钟有日志，认为设备在线
    const now = new Date();
    const fiveMinutesAgo = new Date(now.getTime() - 5 * 60 * 1000);
    
    const isOnline = logs.some(log => {
        const logTime = new Date(log.timestamp);
        return logTime > fiveMinutesAgo;
    });
    
    statusIndicator.className = `status-indicator ${isOnline ? 'status-online' : 'status-offline'}`;
    statusIndicator.title = isOnline ? '设备在线' : '设备离线';
}

// 更新分页
function updatePagination(pagination) {
    const container = document.getElementById('pagination');
    
    if (pagination.pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<nav><ul class="pagination pagination-sm">';
    
    // 上一页
    if (pagination.page > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadLogs(${pagination.page - 1})">上一页</a></li>`;
    }
    
    // 页码
    for (let i = Math.max(1, pagination.page - 2); i <= Math.min(pagination.pages, pagination.page + 2); i++) {
        const active = i === pagination.page ? 'active' : '';
        html += `<li class="page-item ${active}"><a class="page-link" href="#" onclick="loadLogs(${i})">${i}</a></li>`;
    }
    
    // 下一页
    if (pagination.page < pagination.pages) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadLogs(${pagination.page + 1})">下一页</a></li>`;
    }
    
    html += '</ul></nav>';
    container.innerHTML = html;
}

// 自动刷新
function toggleAutoRefresh() {
    const checkbox = document.getElementById('autoRefreshCheck');
    
    if (checkbox.checked) {
        autoRefreshInterval = setInterval(() => {
            loadLogs(currentPage);
        }, 5000); // 每5秒刷新
    } else {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
    }
}

// 事件绑定
document.getElementById('refreshBtn').addEventListener('click', () => loadLogs(currentPage));
document.getElementById('autoRefreshCheck').addEventListener('change', toggleAutoRefresh);

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    loadLogs(1);
    
    // 如果URL中有auto_refresh参数，启动自动刷新
    if (<?= $autoRefresh ? 'true' : 'false' ?>) {
        toggleAutoRefresh();
    }
});
</script>

<?php include 'templates/footer.php'; ?>