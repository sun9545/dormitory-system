<?php
/**
 * 学生表单模板
 */

// 判断是添加还是编辑
$isEdit = $action === 'edit' && isset($studentData);
$title = $isEdit ? '编辑学生' : '添加学生';
$formAction = $isEdit ? BASE_URL . '/students.php?action=edit' : BASE_URL . '/students.php?action=add';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3"><?php echo $title; ?></h1>
    <a href="<?php echo BASE_URL; ?>/students.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> 返回列表
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><?php echo $title; ?>信息</h6>
    </div>
    <div class="card-body">
        <form method="post" action="<?php echo $formAction; ?>" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <!-- 学号 -->
            <div class="col-md-4">
                <label for="student_id" class="form-label">学号 <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="student_id" name="student_id" value="<?php echo $isEdit ? $studentData['student_id'] : ''; ?>" <?php echo $isEdit ? 'readonly' : ''; ?> required>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="original_student_id" value="<?php echo $studentData['student_id']; ?>">
                <?php endif; ?>
            </div>
            
            <!-- 姓名 -->
            <div class="col-md-4">
                <label for="name" class="form-label">姓名 <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" value="<?php echo $isEdit ? $studentData['name'] : ''; ?>" required>
            </div>
            
            <!-- 性别 -->
            <div class="col-md-4">
                <label for="gender" class="form-label">性别 <span class="text-danger">*</span></label>
                <select class="form-select" id="gender" name="gender" required>
                    <option value="">请选择</option>
                    <option value="男" <?php echo $isEdit && $studentData['gender'] === '男' ? 'selected' : ''; ?>>男</option>
                    <option value="女" <?php echo $isEdit && $studentData['gender'] === '女' ? 'selected' : ''; ?>>女</option>
                </select>
            </div>
            
            <!-- 班级 -->
            <div class="col-md-4">
                <label for="class_name" class="form-label">班级 <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="class_name" name="class_name" value="<?php echo $isEdit ? $studentData['class_name'] : ''; ?>" required>
            </div>
            
            <!-- 楼栋 -->
            <div class="col-md-4">
                <label for="building" class="form-label">楼栋 <span class="text-danger">*</span></label>
                <select class="form-select" id="building" name="building" required>
                    <option value="">请选择</option>
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $isEdit && $studentData['building'] == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>号楼
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <!-- 区域 -->
            <div class="col-md-4">
                <label for="building_area" class="form-label">区域 <span class="text-danger">*</span></label>
                <select class="form-select" id="building_area" name="building_area" required>
                    <option value="">请选择</option>
                    <option value="A" <?php echo $isEdit && $studentData['building_area'] === 'A' ? 'selected' : ''; ?>>A区</option>
                    <option value="B" <?php echo $isEdit && $studentData['building_area'] === 'B' ? 'selected' : ''; ?>>B区</option>
                </select>
            </div>
            
            <!-- 楼层 -->
            <div class="col-md-4">
                <label for="building_floor" class="form-label">楼层 <span class="text-danger">*</span></label>
                <select class="form-select" id="building_floor" name="building_floor" required>
                    <option value="">请选择</option>
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $isEdit && $studentData['building_floor'] == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>层
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <!-- 宿舍号 -->
            <div class="col-md-4">
                <label for="room_number" class="form-label">宿舍号 <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="room_number" name="room_number" value="<?php echo $isEdit ? $studentData['room_number'] : ''; ?>" required>
            </div>
            
            <!-- 床号 -->
            <div class="col-md-4">
                <label for="bed_number" class="form-label">床号 <span class="text-danger">*</span></label>
                <select class="form-select" id="bed_number" name="bed_number" required>
                    <option value="">请选择</option>
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $isEdit && $studentData['bed_number'] == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>号床
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <!-- 辅导员 -->
            <div class="col-md-4">
                <label for="counselor" class="form-label">辅导员</label>
                <input type="text" class="form-control" id="counselor" name="counselor" value="<?php echo $isEdit ? $studentData['counselor'] : ''; ?>">
            </div>
            
            <!-- 辅导员电话 -->
            <div class="col-md-4">
                <label for="counselor_phone" class="form-label">辅导员电话</label>
                <input type="text" class="form-control" id="counselor_phone" name="counselor_phone" value="<?php echo $isEdit ? $studentData['counselor_phone'] : ''; ?>">
            </div>
            
            <!-- 提交按钮 -->
            <div class="col-12 mt-4">
                <button type="submit" class="btn btn-primary">保存</button>
                <a href="<?php echo BASE_URL; ?>/students.php" class="btn btn-secondary">取消</a>
            </div>
        </form>
    </div>
</div> 