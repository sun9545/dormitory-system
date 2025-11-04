<?php
/**
 * 查寝统计页面
 */
require_once 'config/config.php';
$pageTitle = '查寝统计 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/student.php';
require_once 'models/check_record.php';

// 加载页头
include 'templates/header.php';

// 获取筛选条件
$building = isset($_GET['building']) ? (int)$_GET['building'] : 0;
$buildingArea = isset($_GET['building_area']) ? $_GET['building_area'] : '';
$buildingFloor = isset($_GET['building_floor']) ? (int)$_GET['building_floor'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$counselor = isset($_GET['counselor']) ? trim($_GET['counselor']) : '';
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 获取统计数据
$student = new Student();
$checkRecord = new CheckRecord();
$filters = [
    'building' => $building,
    'building_area' => $buildingArea,
    'building_floor' => $buildingFloor,
    'status' => $status,
    'counselor' => $counselor
];
$studentStats = $checkRecord->getAllStudentsStatusByDate($date, $filters);

// 获取辅导员列表
$counselors = $student->getAllCounselors();
// 统计数据
$total = count($studentStats);
$present = 0;
$absent = 0;
$leave = 0;
$notChecked = 0;
foreach ($studentStats as $row) {
    if ($row['status'] === '在寝') $present++;
    elseif ($row['status'] === '离寝') $absent++;
    elseif ($row['status'] === '请假') $leave++;
    elseif ($row['status'] === '未签到') $notChecked++;
}



// 如果选择了楼栋，则获取该楼栋的楼层统计
$floorStats = [];
if ($building > 0) {
    $floorStats = $checkRecord->getBuildingFloorStatusStatistics($building);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">查寝统计</h1>
</div>

<!-- 筛选表单 -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">筛选条件</h6>
    </div>
    <div class="card-body">
        <form method="get" action="">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="date" class="form-label">日期</label>
                            <input type="date" class="form-control" id="date" name="date" value="<?php echo htmlspecialchars($date); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="building" class="form-label">楼栋</label>
                            <select class="form-select" id="building" name="building">
                                <option value="0">全部</option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $building == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>号楼
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="building_area" class="form-label">区域</label>
                            <select class="form-select" id="building_area" name="building_area">
                                <option value="">全部</option>
                                <option value="A" <?php echo $buildingArea === 'A' ? 'selected' : ''; ?>>A区</option>
                                <option value="B" <?php echo $buildingArea === 'B' ? 'selected' : ''; ?>>B区</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="building_floor" class="form-label">楼层</label>
                            <select class="form-select" id="building_floor" name="building_floor">
                                <option value="0">全部</option>
                                <?php for ($i = 1; $i <= 6; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $buildingFloor == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>层
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">状态</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">全部</option>
                                <option value="在寝" <?php echo $status === '在寝' ? 'selected' : ''; ?>>在寝</option>
                                <option value="离寝" <?php echo $status === '离寝' ? 'selected' : ''; ?>>离寝</option>
                                <option value="请假" <?php echo $status === '请假' ? 'selected' : ''; ?>>请假</option>
                                <option value="未签到" <?php echo $status === '未签到' ? 'selected' : ''; ?>>未签到</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="counselor" class="form-label">辅导员</label>
                            <select class="form-select" id="counselor" name="counselor">
                                <option value="">全部辅导员</option>
                                <?php foreach ($counselors as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['counselor']); ?>" 
                                            <?php echo $counselor === $c['counselor'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['counselor']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill">筛选</button>
                                <a href="<?php echo BASE_URL; ?>/statistics.php" class="btn btn-secondary flex-fill">重置</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- 统计卡片区 -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body text-center">
                <h5 class="card-title">总人数</h5>
                <p class="card-text h3"><?php echo $total; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-white bg-success mb-3">
            <div class="card-body text-center">
                <h5 class="card-title">在寝</h5>
                <p class="card-text h3"><?php echo $present; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-white bg-danger mb-3">
            <div class="card-body text-center">
                <h5 class="card-title">离寝</h5>
                <p class="card-text h3"><?php echo $absent; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-white bg-warning mb-3">
            <div class="card-body text-center">
                <h5 class="card-title">请假</h5>
                <p class="card-text h3"><?php echo $leave; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-white bg-secondary mb-3">
            <div class="card-body text-center">
                <h5 class="card-title">未签到</h5>
                <p class="card-text h3"><?php echo $notChecked; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- 学生查寝状态统计表 -->
<style>
.table th, .table td {
    vertical-align: middle !important;
    text-align: center;
    font-size: 15px;
}
.table th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    z-index: 2;
}
.table-hover tbody tr:hover {
    background-color: #e9ecef;
}
.table-warning {
    background-color: #fff8e1 !important;
}
</style>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">学生查寝状态（<?php echo htmlspecialchars($date); ?>）</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-striped align-middle" width="100%" cellspacing="0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>学号</th>
                        <th>姓名</th>
                        <th>班级</th>
                        <th>楼栋</th>
                        <th>区域</th>
                        <th>楼层</th>
                        <th>房间</th>
                        <th>床位</th>
                        <th>状态</th>
                        <th>签到时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($studentStats)): ?>
                        <?php foreach ($studentStats as $row): ?>
                            <tr
                            <?php
                                    if ($row['status'] === '未签到') echo 'class="table-warning"';
                                    elseif ($row['status'] === '在寝') echo 'class="table-success"';
                                    elseif ($row['status'] === '离寝') echo 'class="table-danger"';
                                    elseif ($row['status'] === '请假') echo 'class="table-info"';
                            ?>
                            >
                                <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['building']); ?></td>
                                <td><?php echo htmlspecialchars($row['building_area']); ?></td>
                                <td><?php echo htmlspecialchars($row['building_floor']); ?></td>
                                <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['bed_number']); ?></td>
                                <td>
                                    <span class="badge
                                        <?php
                                            if ($row['status'] === '未签到') echo ' bg-warning text-dark';
                                            elseif ($row['status'] === '在寝') echo ' bg-success';
                                            elseif ($row['status'] === '离寝') echo ' bg-danger';
                                            elseif ($row['status'] === '请假') echo ' bg-info text-dark';
                                        ?>">
                                        <?php echo htmlspecialchars($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['check_time']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<!-- 楼层区域统计 -->
<?php if ($building > 0 && !empty($floorStats)): ?>
<div class="card shadow mb-4" id="buildingDetails">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><?php echo $building; ?>号楼楼层统计</h6>
        <a href="<?php echo BASE_URL; ?>/statistics.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-x-lg"></i> 关闭详情
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>区域</th>
                        <th>楼层</th>
                        <th>总人数</th>
                        <th>在寝人数</th>
                        <th>在寝率</th>
                        <th>离寝人数</th>
                        <th>离寝率</th>
                        <th>请假人数</th>
                        <th>请假率</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($floorStats as $stat): ?>
                        <?php
                        $presentRate = $stat['total'] > 0 ? round(($stat['present'] / $stat['total']) * 100, 1) : 0;
                        $absentRate = $stat['total'] > 0 ? round(($stat['absent'] / $stat['total']) * 100, 1) : 0;
                        $leaveRate = $stat['total'] > 0 ? round(($stat['leave'] / $stat['total']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo $stat['building_area']; ?>区</td>
                            <td><?php echo $stat['building_floor']; ?>层</td>
                            <td><?php echo $stat['total']; ?></td>
                            <td><?php echo $stat['present']; ?></td>
                            <td><?php echo $presentRate; ?>%</td>
                            <td><?php echo $stat['absent']; ?></td>
                            <td><?php echo $absentRate; ?>%</td>
                            <td><?php echo $stat['leave']; ?></td>
                            <td><?php echo $leaveRate; ?>%</td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/students.php?building=<?php echo $building; ?>&building_area=<?php echo $stat['building_area']; ?>&building_floor=<?php echo $stat['building_floor']; ?>" class="btn btn-sm btn-info">
                                    查看学生
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 图表展示 -->
<div class="row">
    <div class="col-xl-6 mb-4">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary"><?php echo $building; ?>号楼在寝率</h6>
            </div>
            <div class="card-body">
                <canvas id="floorPresentChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-6 mb-4">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary"><?php echo $building; ?>号楼状态分布</h6>
            </div>
            <div class="card-body">
                <canvas id="floorStatusChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- 楼层统计图表脚本 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 楼层在寝率图表
    var floorCtx = document.getElementById('floorPresentChart').getContext('2d');
    var floorLabels = [];
    var presentRates = [];
    
    <?php
    $groupedStats = [];
    foreach ($floorStats as $stat) {
        $key = $stat['building_area'] . $stat['building_floor'];
        $groupedStats[$key] = $stat;
        echo "floorLabels.push('" . $stat['building_area'] . $stat['building_floor'] . "');";
        $presentRate = $stat['total'] > 0 ? round(($stat['present'] / $stat['total']) * 100, 1) : 0;
        echo "presentRates.push(" . $presentRate . ");";
    }
    ?>
    
    var floorChart = new Chart(floorCtx, {
        type: 'bar',
        data: {
            labels: floorLabels,
            datasets: [{
                label: '在寝率(%)',
                data: presentRates,
                backgroundColor: 'rgba(54, 162, 235, 0.8)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100
                }
            }
        }
    });
    
    // 状态分布图表
    var statusCtx = document.getElementById('floorStatusChart').getContext('2d');
    var totalPresent = 0;
    var totalAbsent = 0;
    var totalLeave = 0;
    
    <?php
    $totalPresent = 0;
    $totalAbsent = 0;
    $totalLeave = 0;
    foreach ($floorStats as $stat) {
        $totalPresent += $stat['present'];
        $totalAbsent += $stat['absent'];
        $totalLeave += $stat['leave'];
    }
    echo "totalPresent = " . $totalPresent . ";";
    echo "totalAbsent = " . $totalAbsent . ";";
    echo "totalLeave = " . $totalLeave . ";";
    ?>
    
    var statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['在寝', '离寝', '请假'],
            datasets: [{
                data: [totalPresent, totalAbsent, totalLeave],
                backgroundColor: ['#28a745', '#dc3545', '#ffc107'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
    
    // 锚点跳转已由页面底部的脚本统一处理
});
</script>
<?php endif; ?>



<?php
// 加载页脚
include 'templates/footer.php';
?> 