<?php
/**
 * 学生请假申请页面（H5，无需登录）
 */

require_once 'config/config.php';
require_once 'utils/session_manager.php';
$pageTitle = '学生请假申请 - ' . SITE_NAME;

// 启动session（使用统一管理器）
SessionManager::start();

// 调试：输出session ID
$currentSessionId = SessionManager::getId();
?>
<!-- Session ID: <?php echo $currentSessionId; ?> -->
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="<?php echo BASE_URL; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://lib.baomitu.com/bootstrap-icons/1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Flatpickr CSS (日历组件) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .leave-container {
            max-width: 500px;
            margin: 0 auto;
        }
        .leave-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .leave-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 20px;
            text-align: center;
        }
        .leave-header h3 {
            margin: 0;
            font-weight: 600;
        }
        .leave-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .leave-body {
            padding: 25px 20px;
        }
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 12px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 12px 30px;
            font-weight: 600;
            width: 100%;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
        .btn-outline-secondary {
            border-radius: 8px;
            border-width: 2px;
            padding: 10px 25px;
        }
        .captcha-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .captcha-input {
            flex: 1;
        }
        .captcha-img {
            width: 120px;
            height: 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
        }
        .selected-dates {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            min-height: 60px;
        }
        .date-tag {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            margin: 3px;
            font-size: 13px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            padding: 0 20px;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 2px;
            background: #ddd;
            z-index: -1;
        }
        .step:first-child::before {
            left: 50%;
        }
        .step:last-child::before {
            right: 50%;
        }
        .step-number {
            width: 30px;
            height: 30px;
            line-height: 30px;
            border-radius: 50%;
            background: #ddd;
            color: #999;
            margin: 0 auto 5px;
            font-weight: 600;
            font-size: 14px;
        }
        .step.active .step-number {
            background: #667eea;
            color: white;
        }
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
        .step-label {
            font-size: 12px;
            color: #999;
        }
        .step.active .step-label {
            color: #667eea;
            font-weight: 600;
        }
        .hidden {
            display: none;
        }
        .alert {
            border-radius: 8px;
        }
        .records-list {
            margin-top: 20px;
        }
        .record-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .record-item .status-badge {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 12px;
        }
        .status-pending {
            background: #ffc107;
            color: #000;
        }
        .status-approved {
            background: #28a745;
            color: #fff;
        }
        .status-rejected {
            background: #dc3545;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="leave-container">
        <!-- Logo/返回 -->
        <div class="text-center mb-3">
            <a href="<?php echo BASE_URL; ?>" class="text-white text-decoration-none">
                <i class="bi bi-house-door"></i> 返回首页
            </a>
        </div>
        
        <!-- 主卡片 -->
        <div class="leave-card">
            <div class="leave-header">
                <h3><i class="bi bi-calendar-check"></i> 学生请假申请</h3>
                <p>请如实填写请假信息，等待辅导员审批</p>
            </div>
            
            <div class="leave-body">
                <!-- 步骤指示器 -->
                <div class="step-indicator">
                    <div class="step active" id="step1">
                        <div class="step-number">1</div>
                        <div class="step-label">身份验证</div>
                    </div>
                    <div class="step" id="step2">
                        <div class="step-number">2</div>
                        <div class="step-label">填写申请</div>
                    </div>
                    <div class="step" id="step3">
                        <div class="step-number">3</div>
                        <div class="step-label">提交成功</div>
                    </div>
                </div>
                
                <!-- 消息提示 -->
                <div id="message" class="alert alert-dismissible fade show hidden" role="alert">
                    <span id="messageText"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                
                <!-- 步骤1：身份验证 -->
                <div id="verifyForm">
                    <div class="mb-3">
                        <label for="studentId" class="form-label">学号 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="studentId" placeholder="请输入学号" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="studentName" class="form-label">姓名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="studentName" placeholder="请输入姓名" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="captcha" class="form-label">验证码 <span class="text-danger">*</span></label>
                        <div class="captcha-group">
                            <div class="captcha-input">
                                <input type="text" class="form-control" id="captcha" placeholder="请输入验证码" maxlength="4" required>
                            </div>
                            <img src="<?php echo BASE_URL; ?>/api/get_captcha.php" alt="验证码" class="captcha-img" id="captchaImg" title="点击刷新">
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-primary" id="verifyBtn">
                        <i class="bi bi-shield-check"></i> 验证身份
                    </button>
                    
                    <div class="text-center mt-3">
                        <a href="#" id="viewRecordsLink" class="text-decoration-none">查看我的申请记录</a>
                    </div>
                </div>
                
                <!-- 步骤2：填写申请 -->
                <div id="applicationForm" class="hidden">
                    <div class="alert alert-info">
                        <strong><i class="bi bi-person-check"></i> 学生信息</strong><br>
                        <span id="verifiedInfo"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="leaveDates" class="form-label">请假日期 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="leaveDates" placeholder="点击选择日期（可多选）" readonly>
                        <div class="selected-dates" id="selectedDates">
                            <small class="text-muted">请点击上方日期框选择请假日期，可选择多个不连续的日期</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reason" class="form-label">请假原因 <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reason" rows="4" placeholder="请详细说明请假原因" required></textarea>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary" id="submitBtn">
                            <i class="bi bi-send"></i> 提交申请
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="backBtn">
                            <i class="bi bi-arrow-left"></i> 返回修改
                        </button>
                    </div>
                </div>
                
                <!-- 步骤3：提交成功 -->
                <div id="successView" class="hidden text-center">
                    <div class="mb-4">
                        <i class="bi bi-check-circle text-success" style="font-size: 80px;"></i>
                    </div>
                    <h4 class="mb-3">申请提交成功！</h4>
                    <p class="text-muted">您的请假申请已提交，请等待辅导员审批。</p>
                    <div class="d-grid gap-2 mt-4">
                        <button type="button" class="btn btn-primary" id="newApplicationBtn">
                            <i class="bi bi-plus-circle"></i> 再提交一个申请
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="viewMyRecordsBtn">
                            <i class="bi bi-list-ul"></i> 查看我的申请记录
                        </button>
                    </div>
                </div>
                
                <!-- 申请记录列表 -->
                <div id="recordsView" class="hidden">
                    <h5 class="mb-3">我的申请记录</h5>
                    <div id="recordsList" class="records-list"></div>
                    <div class="d-grid gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary" id="backToApplyBtn">
                            <i class="bi bi-arrow-left"></i> 返回申请页面
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-3">
            <small class="text-white">© 2025 <?php echo SITE_NAME; ?> | 学生查寝管理系统</small>
        </div>
    </div>
    
    <script src="<?php echo BASE_URL; ?>/assets/js/bootstrap.bundle.min.js"></script>
    <!-- Flatpickr JS (日历组件) -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh.js"></script>
    
    <script>
    const BASE_URL = '<?php echo BASE_URL; ?>';
    let verifiedStudentId = '';
    let verifiedStudentName = '';
    let selectedDatesArray = [];
    let flatpickrInstance = null;
    
    // 初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 刷新验证码
        document.getElementById('captchaImg').addEventListener('click', function() {
            this.src = BASE_URL + '/api/get_captcha.php?' + Date.now();
        });
        
        // 验证身份按钮
        document.getElementById('verifyBtn').addEventListener('click', verifyStudent);
        
        // 提交申请按钮
        document.getElementById('submitBtn').addEventListener('click', submitApplication);
        
        // 返回修改按钮
        document.getElementById('backBtn').addEventListener('click', function() {
            showStep(1);
        });
        
        // 再提交一个申请
        document.getElementById('newApplicationBtn').addEventListener('click', function() {
            resetForm();
            showStep(1);
        });
        
        // 查看记录链接
        document.getElementById('viewRecordsLink').addEventListener('click', function(e) {
            e.preventDefault();
            showRecordsView();
        });
        
        document.getElementById('viewMyRecordsBtn').addEventListener('click', function() {
            showRecordsView();
        });
        
        document.getElementById('backToApplyBtn').addEventListener('click', function() {
            document.getElementById('recordsView').classList.add('hidden');
            document.getElementById('verifyForm').classList.remove('hidden');
        });
        
        // 回车提交
        document.getElementById('captcha').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                verifyStudent();
            }
        });
    });
    
    // 显示消息
    function showMessage(message, type = 'success') {
        const messageDiv = document.getElementById('message');
        const messageText = document.getElementById('messageText');
        
        messageDiv.className = `alert alert-${type} alert-dismissible fade show`;
        messageText.textContent = message;
        messageDiv.classList.remove('hidden');
        
        // 3秒后自动关闭
        setTimeout(() => {
            messageDiv.classList.add('hidden');
        }, 3000);
    }
    
    // 显示步骤
    function showStep(step) {
        // 更新步骤指示器
        for (let i = 1; i <= 3; i++) {
            const stepEl = document.getElementById('step' + i);
            if (i < step) {
                stepEl.className = 'step completed';
            } else if (i === step) {
                stepEl.className = 'step active';
            } else {
                stepEl.className = 'step';
            }
        }
        
        // 显示对应内容
        document.getElementById('verifyForm').classList.add('hidden');
        document.getElementById('applicationForm').classList.add('hidden');
        document.getElementById('successView').classList.add('hidden');
        
        if (step === 1) {
            document.getElementById('verifyForm').classList.remove('hidden');
        } else if (step === 2) {
            document.getElementById('applicationForm').classList.remove('hidden');
            initDatePicker();
        } else if (step === 3) {
            document.getElementById('successView').classList.remove('hidden');
        }
    }
    
    // 验证学生身份
    function verifyStudent() {
        const studentId = document.getElementById('studentId').value.trim();
        const studentName = document.getElementById('studentName').value.trim();
        const captcha = document.getElementById('captcha').value.trim();
        
        if (!studentId || !studentName) {
            showMessage('请填写学号和姓名', 'warning');
            return;
        }
        
        if (!captcha) {
            showMessage('请输入验证码', 'warning');
            return;
        }
        
        const btn = document.getElementById('verifyBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 验证中...';
        
        fetch(BASE_URL + '/api/leave_application_api.php?action=verify_student', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin', // ⭐ 携带cookie
            body: JSON.stringify({
                student_id: studentId,
                name: studentName,
                captcha: captcha
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                verifiedStudentId = studentId;
                verifiedStudentName = studentName;
                document.getElementById('verifiedInfo').innerHTML = `
                    学号：${data.data.student_id}<br>
                    姓名：${data.data.name}<br>
                    班级：${data.data.class_name}<br>
                    辅导员：${data.data.counselor}
                `;
                showStep(2);
            } else {
                showMessage(data.message, 'danger');
                document.getElementById('captchaImg').src = BASE_URL + '/api/get_captcha.php?' + Date.now();
                document.getElementById('captcha').value = '';
            }
        })
        .catch(error => {
            showMessage('网络错误，请稍后重试', 'danger');
            console.error(error);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-shield-check"></i> 验证身份';
        });
    }
    
    // 初始化日期选择器
    function initDatePicker() {
        if (flatpickrInstance) {
            flatpickrInstance.destroy();
        }
        
        flatpickrInstance = flatpickr('#leaveDates', {
            mode: 'multiple',
            dateFormat: 'Y-m-d',
            locale: 'zh',
            minDate: 'today',
            maxDate: new Date().fp_incr(90), // 最多选择未来90天
            onChange: function(selectedDates, dateStr, instance) {
                selectedDatesArray = selectedDates.map(date => {
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    return `${year}-${month}-${day}`;
                });
                
                updateSelectedDatesDisplay();
            }
        });
    }
    
    // 更新已选日期显示
    function updateSelectedDatesDisplay() {
        const container = document.getElementById('selectedDates');
        if (selectedDatesArray.length === 0) {
            container.innerHTML = '<small class="text-muted">请点击上方日期框选择请假日期，可选择多个不连续的日期</small>';
        } else {
            container.innerHTML = selectedDatesArray.map(date => 
                `<span class="date-tag">${date}</span>`
            ).join('');
        }
    }
    
    // 提交申请
    function submitApplication() {
        if (selectedDatesArray.length === 0) {
            showMessage('请选择请假日期', 'warning');
            return;
        }
        
        const reason = document.getElementById('reason').value.trim();
        if (!reason) {
            showMessage('请填写请假原因', 'warning');
            return;
        }
        
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 提交中...';
        
        fetch(BASE_URL + '/api/leave_application_api.php?action=submit_application', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                student_id: verifiedStudentId,
                name: verifiedStudentName,
                leave_dates: selectedDatesArray,
                reason: reason
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showStep(3);
            } else {
                showMessage(data.message, 'danger');
            }
        })
        .catch(error => {
            showMessage('网络错误，请稍后重试', 'danger');
            console.error(error);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send"></i> 提交申请';
        });
    }
    
    // 显示申请记录
    function showRecordsView() {
        const studentId = document.getElementById('studentId').value.trim();
        const studentName = document.getElementById('studentName').value.trim();
        
        if (!studentId || !studentName) {
            showMessage('请先填写学号和姓名', 'warning');
            return;
        }
        
        document.getElementById('verifyForm').classList.add('hidden');
        document.getElementById('recordsView').classList.remove('hidden');
        
        const listContainer = document.getElementById('recordsList');
        listContainer.innerHTML = '<div class="text-center"><span class="spinner-border"></span> 加载中...</div>';
        
        fetch(BASE_URL + '/api/leave_application_api.php?action=get_my_applications', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                student_id: studentId,
                name: studentName
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.data.length === 0) {
                    listContainer.innerHTML = '<div class="text-center text-muted">暂无申请记录</div>';
                } else {
                    listContainer.innerHTML = data.data.map(app => {
                        let statusClass = 'status-pending';
                        let statusText = '待审批';
                        if (app.status === 'approved') {
                            statusClass = 'status-approved';
                            statusText = '已批准';
                        } else if (app.status === 'rejected') {
                            statusClass = 'status-rejected';
                            statusText = '已拒绝';
                        }
                        
                        const dates = app.leave_dates.join(', ');
                        const cancelBtn = app.status === 'pending' ? 
                            `<button class="btn btn-sm btn-outline-danger" onclick="cancelApplication(${app.id})">撤销</button>` : '';
                        
                        return `
                            <div class="record-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <strong>申请时间：${app.apply_time}</strong>
                                    <span class="status-badge ${statusClass}">${statusText}</span>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted">请假日期：</small><br>
                                    ${dates}
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted">请假原因：</small><br>
                                    ${app.reason}
                                </div>
                                ${cancelBtn}
                            </div>
                        `;
                    }).join('');
                }
            } else {
                listContainer.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
            }
        })
        .catch(error => {
            listContainer.innerHTML = '<div class="alert alert-danger">加载失败，请稍后重试</div>';
            console.error(error);
        });
    }
    
    // 撤销申请
    function cancelApplication(appId) {
        if (!confirm('确定要撤销这个申请吗？')) {
            return;
        }
        
        fetch(BASE_URL + '/api/leave_application_api.php?action=cancel_application', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                application_id: appId,
                student_id: verifiedStudentId || document.getElementById('studentId').value.trim(),
                name: verifiedStudentName || document.getElementById('studentName').value.trim()
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                showRecordsView(); // 刷新列表
            } else {
                showMessage(data.message, 'danger');
            }
        })
        .catch(error => {
            showMessage('操作失败，请稍后重试', 'danger');
            console.error(error);
        });
    }
    
    // 重置表单
    function resetForm() {
        document.getElementById('studentId').value = '';
        document.getElementById('studentName').value = '';
        document.getElementById('captcha').value = '';
        document.getElementById('reason').value = '';
        selectedDatesArray = [];
        if (flatpickrInstance) {
            flatpickrInstance.clear();
        }
        document.getElementById('captchaImg').src = BASE_URL + '/api/get_captcha.php?' + Date.now();
    }
    </script>
</body>
</html>

