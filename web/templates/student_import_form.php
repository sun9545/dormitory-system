<?php
/**
 * 学生批量导入表单模板
 */
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">批量导入学生</h1>
    <div>
        <a href="<?php echo BASE_URL; ?>/students.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> 返回学生列表
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">导入说明</h6>
    </div>
    <div class="card-body">
        <p>请按照以下步骤进行学生批量导入：</p>
        <ol>
            <li>下载<a href="<?php echo BASE_URL; ?>/students.php?action=export_template">导入模板</a></li>
            <li>按照模板格式填写学生信息（注意：学号和姓名为必填项）</li>
            <li>保存文件为CSV格式，<strong>确保使用UTF-8编码</strong></li>
            <li>上传填写好的CSV文件</li>
        </ol>
        <p class="text-danger">注意事项：</p>
        <ul>
            <li>CSV文件的第一行必须是表头，包含列名</li>
            <li>必须包含"学号"和"姓名"列（列名必须完全匹配）</li>
            <li>其他可选列包括：性别、班级、楼栋、区域、楼层、宿舍号、床号、辅导员、辅导员电话</li>
            <li>如果导入的学生学号已存在，将会更新该学生的信息</li>
            <li><strong>文件必须使用UTF-8编码</strong>，否则可能导致中文乱码</li>
            <li>如果使用Excel编辑，请在"另存为"时选择"CSV UTF-8 (逗号分隔)"格式</li>
        </ul>
        <p><strong>示例数据：</strong></p>
        <pre>
学号,姓名,性别,班级,楼栋,区域,楼层,宿舍号,床号,辅导员,辅导员电话
202001001,张三,男,计算机科学1班,1,A,1,101,1,李老师,13800138000
202001002,李四,女,计算机科学1班,2,B,2,202,2,王老师,13900139000
        </pre>
    </div>
</div>

<div class="card shadow">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">上传文件</h6>
    </div>
    <div class="card-body">
        <form method="post" action="<?php echo BASE_URL; ?>/students.php?action=import" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="import_file" class="form-label">选择CSV文件</label>
                <input type="file" class="form-control" id="import_file" name="import_file" accept=".csv" required>
                <div class="form-text">请选择UTF-8编码的CSV格式文件</div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-upload"></i> 上传并导入
            </button>
        </form>
        <div class="text-center mt-4">
            <a href="<?php echo BASE_URL; ?>/students.php?action=export_template" class="btn btn-primary">
                <i class="bi bi-download"></i> 下载CSV模板
            </a>
            <a href="<?php echo BASE_URL; ?>/convert_excel_to_csv.php" class="btn btn-info ms-2">
                <i class="bi bi-file-earmark-excel"></i> Excel转CSV帮助
            </a>
        </div>
    </div>
</div> 