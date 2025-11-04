<?php
/**
 * 学生详情页模板
 */

// 获取当前状态
$status = isset($latestStatus['status']) ? $latestStatus['status'] : '未签到';
$statusClass = getStatusClass($status);
$lastCheckTime = isset($latestStatus['check_time']) ? formatDateTime($latestStatus['check_time']) : '暂无记录';

// 宿舍信息
$dormitory = $studentData['building'] . '号楼 ' . $studentData['building_area'] . $studentData['building_floor'] . '-' . $studentData['room_number'] . ' (' . $studentData['bed_number'] . '号床)';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">学生详情</h1>
    <div>
        <a href="<?php echo BASE_URL; ?>/students.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> 返回列表
        </a>
        <a href="<?php echo BASE_URL; ?>/students.php?action=edit&student_id=<?php echo $studentData['student_id']; ?>" class="btn btn-primary">
            <i class="bi bi-pencil"></i> 编辑
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="row">
    <!-- 学生基本信息卡片 -->
    <div class="col-lg-4">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">基本信息</h6>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <h4><?php echo $studentData['name']; ?></h4>
                    <p class="text-muted mb-0"><?php echo $studentData['student_id']; ?></p>
                </div>
                <hr>
                <div class="row">
                    <div class="col-6 mb-3">
                        <strong>性别:</strong>
                        <p class="mb-0"><?php echo $studentData['gender']; ?></p>
                    </div>
                    <div class="col-6 mb-3">
                        <strong>班级:</strong>
                        <p class="mb-0"><?php echo $studentData['class_name']; ?></p>
                    </div>
                    <div class="col-12 mb-3">
                        <strong>宿舍:</strong>
                        <p class="mb-0"><?php echo $dormitory; ?></p>
                    </div>
                    <div class="col-12 mb-3">
                        <strong>辅导员:</strong>
                        <p class="mb-0"><?php echo $studentData['counselor'] ?: '未设置'; ?></p>
                    </div>
                    <div class="col-12 mb-3">
                        <strong>辅导员电话:</strong>
                        <p class="mb-0"><?php echo $studentData['counselor_phone'] ?: '未设置'; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 当前状态卡片 -->
    <div class="col-lg-8">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">当前状态</h6>
                <button type="button" class="btn btn-sm btn-warning update-status" data-bs-toggle="modal" data-bs-target="#statusModal" data-id="<?php echo $studentData['student_id']; ?>" data-name="<?php echo $studentData['name']; ?>" data-status="<?php echo $status; ?>">
                    <i class="bi bi-arrow-repeat"></i> 更新状态
                </button>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center">
                            <div class="p-3 rounded-circle me-3" style="background-color: var(--bs-<?php echo $statusClass; ?>);">
                                <i class="bi bi-person-check text-white fs-4"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">当前状态</h5>
                                <p class="mb-0">
                                    <span class="badge bg-<?php echo $statusClass; ?> fs-6"><?php echo $status; ?></span>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-0">
                            <strong>最后更新:</strong> <?php echo $lastCheckTime; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 历史记录卡片 -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">查寝记录历史</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>状态</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($history) && !empty($history)): ?>
                                <?php foreach ($history as $record): ?>
                                    <tr>
                                        <td><?php echo formatDateTime($record['check_time']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo getStatusClass($record['status']); ?>">
                                                <?php echo $record['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2" class="text-center">暂无查寝记录</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 修改状态模态框 -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="statusModalLabel">修改学生状态</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>修改学生 <span id="statusStudentName"></span> 的查寝状态：</p>
                <form method="post" action="<?php echo BASE_URL; ?>/students.php?action=update_status" id="statusForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="student_id" id="statusStudentId" value="">
                    <div class="mb-3">
                        <label for="status" class="form-label">状态</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="在寝">在寝</option>
                            <option value="离寝">离寝</option>
                            <option value="请假">请假</option>
                            <option value="未签到">未签到</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="submit" form="statusForm" class="btn btn-primary">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 状态更新模态框
    document.querySelectorAll('.update-status').forEach(function(button) {
        button.addEventListener('click', function() {
            var studentId = this.dataset.id;
            var studentName = this.dataset.name;
            var currentStatus = this.dataset.status;
            document.getElementById('statusStudentId').value = studentId;
            document.getElementById('statusStudentName').textContent = studentName;
            document.getElementById('status').value = currentStatus;
        });
    });
});
</script> 