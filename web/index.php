<?php
/**
 * 首页仪表盘
 */
require_once 'config/config.php';
$pageTitle = '控制面板 - ' . SITE_NAME;
require_once 'utils/auth.php';
require_once 'utils/helpers.php';
require_once 'models/student.php';
require_once 'models/check_record.php';

// ⭐ 启动会话并检查登录状态（在POST处理之前）
startSecureSession();
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ========== POST请求处理（必须在header之前）==========

// 处理批量签到
if (isset($_POST['action']) && $_POST['action'] === 'batch_checkin') {
    header('Content-Type: application/json');
    
    try {
        // 获取学号列表
        $studentIdsInput = isset($_POST['student_ids']) ? trim($_POST['student_ids']) : '';
        
        if (empty($studentIdsInput)) {
            echo json_encode(['success' => false, 'message' => '请输入学号']);
            exit;
        }
        
        // 解析学号（支持逗号、空格、换行分隔）
        $studentIdsInput = str_replace([',', ' ', "\r\n", "\n", "\r", "\t"], ',', $studentIdsInput);
        $studentIdsArray = array_filter(array_map('trim', explode(',', $studentIdsInput)));
        
        // 去重
        $studentIdsArray = array_unique($studentIdsArray);
        
        // 限制数量
        if (count($studentIdsArray) > 100) {
            echo json_encode(['success' => false, 'message' => '一次最多只能签到100名学生']);
            exit;
        }
        
        // 查询学生信息
        $student = new Student();
        $foundStudents = [];
        $notFoundIds = [];
        
        foreach ($studentIdsArray as $studentId) {
            $studentInfo = $student->getStudentById($studentId);
            if ($studentInfo) {
                $foundStudents[] = $studentInfo;
            } else {
                $notFoundIds[] = $studentId;
            }
        }
        
        echo json_encode([
            'success' => true,
            'found' => $foundStudents,
            'not_found' => $notFoundIds
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '查询失败：' . $e->getMessage()]);
        exit;
    }
}

// 处理批量签到确认
if (isset($_POST['action']) && $_POST['action'] === 'confirm_batch_checkin') {
    header('Content-Type: application/json');
    
    try {
        // 获取学号列表
        $studentIds = isset($_POST['student_ids']) ? json_decode($_POST['student_ids'], true) : [];
        
        if (empty($studentIds)) {
            echo json_encode(['success' => false, 'message' => '没有可签到的学生']);
            exit;
        }
        
        // 批量签到
        $checkRecord = new CheckRecord();
        $successCount = 0;
        $failedCount = 0;
        $failedStudents = [];
        
        foreach ($studentIds as $studentId) {
            if ($checkRecord->updateStudentStatus($studentId, '在寝', $_SESSION['user_id'], 'MANUAL_BATCH')) {
                $successCount++;
            } else {
                $failedCount++;
                $failedStudents[] = $studentId;
            }
        }
        
        // 清除缓存
        if (function_exists('clearAllRelatedCache')) {
            clearAllRelatedCache();
        } else if (function_exists('clearCache')) {
            clearCache(CACHE_KEY_ALL_STUDENTS);
            clearCache(CACHE_KEY_ALL_STATUS_DATE);
        }
        
        $message = "批量签到完成！成功 {$successCount} 人";
        $messageType = 'success';
        
        if ($failedCount > 0) {
            $message .= "，失败 {$failedCount} 人";
            if (!empty($failedStudents)) {
                $message .= "（失败学号：" . implode('、', $failedStudents) . "）";
            }
            if ($successCount === 0) {
                $messageType = 'danger';
            } else {
                $messageType = 'warning';
            }
        }
        
        // 设置SESSION消息（用于页面刷新后显示）
        $_SESSION['batch_checkin_message'] = $message;
        $_SESSION['batch_checkin_message_type'] = $messageType;
        
        echo json_encode([
            'success' => $successCount > 0,
            'message' => $message,
            'message_type' => $messageType,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'failed_students' => $failedStudents
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '签到失败：' . $e->getMessage()]);
        exit;
    }
}

// ========== POST请求处理结束 ==========

// 加载页头
include 'templates/header.php';

// 获取批量签到的消息（如果有）
$batchCheckinMessage = '';
$batchCheckinMessageType = '';
if (isset($_SESSION['batch_checkin_message'])) {
    $batchCheckinMessage = $_SESSION['batch_checkin_message'];
    $batchCheckinMessageType = $_SESSION['batch_checkin_message_type'];
    unset($_SESSION['batch_checkin_message']);
    unset($_SESSION['batch_checkin_message_type']);
}

// 获取统计数据
$student = new Student();
$checkRecord = new CheckRecord();

// 获取显示选项（是否显示空数据）
$showEmptyData = isset($_GET['show_empty']) ? (bool)$_GET['show_empty'] : false;

// 获取筛选条件
$counselor = isset($_GET['counselor']) ? trim($_GET['counselor']) : '';

// 获取所有学生当天查寝状态
$date = date('Y-m-d');
$filters = [];
if (!empty($counselor)) {
    $filters['counselor'] = $counselor;
}
$studentStats = $checkRecord->getAllStudentsStatusByDate($date, $filters);

// 获取实际存在的楼栋结构
$existingStructure = $student->getExistingBuildingStructure();

// 获取辅导员列表
$counselors = $student->getAllCounselors();
// 统计总数
$totalStudents = count($studentStats);
$totalPresent = 0;
$totalAbsent = 0;
$totalLeave = 0;
$totalNotChecked = 0;
foreach ($studentStats as $row) {
    if ($row['status'] === '在寝') $totalPresent++;
    elseif ($row['status'] === '离寝') $totalAbsent++;
    elseif ($row['status'] === '请假') $totalLeave++;
    elseif ($row['status'] === '未签到') $totalNotChecked++;
}
// 分楼、分区、分层统计
$buildingStats = [];
foreach ($studentStats as $row) {
    $b = $row['building'];
    $area = $row['building_area'];
    $f = $row['building_floor'];
    if (!isset($buildingStats[$b])) $buildingStats[$b] = [
        'total'=>0,'present'=>0,'absent'=>0,'leave'=>0,'notChecked'=>0,'areas'=>[]
    ];
    $buildingStats[$b]['total']++;
    if ($row['status'] === '在寝') $buildingStats[$b]['present']++;
    elseif ($row['status'] === '离寝') $buildingStats[$b]['absent']++;
    elseif ($row['status'] === '请假') $buildingStats[$b]['leave']++;
    elseif ($row['status'] === '未签到') $buildingStats[$b]['notChecked']++;
    // 分区分层
    if (!isset($buildingStats[$b]['areas'][$area])) $buildingStats[$b]['areas'][$area] = [];
    if (!isset($buildingStats[$b]['areas'][$area][$f])) $buildingStats[$b]['areas'][$area][$f] = ['total'=>0,'present'=>0,'absent'=>0,'leave'=>0,'notChecked'=>0];
    $buildingStats[$b]['areas'][$area][$f]['total']++;
    if ($row['status'] === '在寝') $buildingStats[$b]['areas'][$area][$f]['present']++;
    elseif ($row['status'] === '离寝') $buildingStats[$b]['areas'][$area][$f]['absent']++;
    elseif ($row['status'] === '请假') $buildingStats[$b]['areas'][$area][$f]['leave']++;
    elseif ($row['status'] === '未签到') $buildingStats[$b]['areas'][$area][$f]['notChecked']++;
}

// 获取最近的查寝记录
$recentUpdates = $checkRecord->getAllStudentsLatestStatus(['limit' => 5]);
?>

<?php if (!empty($batchCheckinMessage)): ?>
<div class="alert alert-<?php echo $batchCheckinMessageType; ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?php echo $batchCheckinMessageType === 'success' ? 'check-circle' : ($batchCheckinMessageType === 'warning' ? 'exclamation-triangle' : 'x-circle'); ?>"></i>
    <?php echo $batchCheckinMessage; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">控制面板</h1>
    <!-- 辅导员筛选 - 移到右侧 -->
    <form method="get" action="" class="d-flex align-items-center">
        <label for="counselor" class="form-label me-2 mb-0">辅导员筛选:</label>
        <select class="form-select me-2" id="counselor" name="counselor" style="width: auto;">
            <option value="">全部辅导员</option>
            <?php foreach ($counselors as $c): ?>
                <option value="<?php echo htmlspecialchars($c['counselor']); ?>" 
                        <?php echo $counselor === $c['counselor'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['counselor']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm me-2">筛选</button>
        <a href="<?php echo BASE_URL; ?>/" class="btn btn-secondary btn-sm">重置</a>
        <?php if (!empty($showEmptyData)): ?>
            <input type="hidden" name="show_empty" value="1">
        <?php endif; ?>
    </form>
</div>

<div class="row">
    <!-- 总览卡片 -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">学生总数</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalStudents; ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-people fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">在寝人数</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalPresent; ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-house-door fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-danger shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">未签到人数</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalNotChecked; ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-person-x fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">请假人数</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalLeave; ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-calendar-check fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 分楼统计表（可展开） -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">各楼栋在寝统计（<?php echo date('Y-m-d'); ?>）</h6>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="showEmptyDataToggle" 
                           <?php echo $showEmptyData ? 'checked' : ''; ?> 
                           onchange="toggleEmptyDataDisplay(this.checked)">
                    <label class="form-check-label text-sm" for="showEmptyDataToggle">
                        显示空楼栋/楼层
                    </label>
                </div>
            </div>
    <div class="card-body">
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <strong>提示：</strong>点击红色的"未签到"数字可以跳转到查寝统计页面查看详细信息
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                <thead class="table-light">
                    <tr>
                        <th>楼栋</th>
                        <th>总人数</th>
                        <th>在寝</th>
                        <th>离寝</th>
                        <th>请假</th>
                        <th>未签到</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // 决定要显示的楼栋范围
                    if ($showEmptyData) {
                        // 显示所有楼栋（1-10）
                        $buildingRange = range(1, 10);
                    } else {
                        // 只显示有学生的楼栋
                        $buildingRange = array_keys($buildingStats);
                        sort($buildingRange);
                    }
                    
                    foreach ($buildingRange as $b): 
                        $stat = isset($buildingStats[$b]) ? $buildingStats[$b] : ['total'=>0,'present'=>0,'absent'=>0,'leave'=>0,'notChecked'=>0,'areas'=>[]]; 
                        
                        // 如果不显示空数据且该楼栋没有学生，跳过
                        if (!$showEmptyData && $stat['total'] == 0) continue;
                    ?>
                        <tr data-bs-toggle="collapse" data-bs-target="#collapseBuilding<?php echo $b; ?>" aria-expanded="false" aria-controls="collapseBuilding<?php echo $b; ?>" style="cursor:pointer;">
                            <td><?php echo $b; ?>号楼</td>
                            <td><?php echo $stat['total']; ?></td>
                            <td><?php echo $stat['present']; ?></td>
                            <td><?php echo $stat['absent']; ?></td>
                            <td><?php echo $stat['leave']; ?></td>
                            <td>
                                <?php if ($stat['notChecked'] > 0): ?>
                                    <a href="statistics.php?status=未签到&building=<?php echo $b; ?>&date=<?php echo date('Y-m-d'); ?>" 
                                       class="text-danger fw-bold text-decoration-none" 
                                       title="点击查看<?php echo $b; ?>号楼未签到学生详情">
                                        <?php echo $stat['notChecked']; ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo $stat['notChecked']; ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="text-primary">点击展开</span></td>
                        </tr>
                        <tr class="collapse bg-light" id="collapseBuilding<?php echo $b; ?>">
                            <td colspan="7">
                                <div class="row">
                                    <?php 
                                    // 决定要显示的区域
                                    $areasToShow = $showEmptyData ? ["A","B"] : [];
                                    if (!$showEmptyData && isset($existingStructure[$b])) {
                                        $areasToShow = array_keys($existingStructure[$b]);
                                        sort($areasToShow);
                                    } elseif ($showEmptyData) {
                                        $areasToShow = ["A","B"];
                                    }
                                    
                                    foreach ($areasToShow as $area): 
                                        // 如果不显示空数据且该区域没有学生，跳过
                                        if (!$showEmptyData && (!isset($stat['areas'][$area]) || empty($stat['areas'][$area]))) continue;
                                    ?>
                                        <div class="col-md-6">
                                            <h6><?php echo $b; ?>号楼<?php echo $area; ?>区</h6>
                                            <table class="table table-sm table-bordered mb-2">
                                                <thead>
                                                    <tr>
                                                        <th>楼层</th>
                                                        <th>总人数</th>
                                                        <th>在寝</th>
                                                        <th>离寝</th>
                                                        <th>请假</th>
                                                        <th>未签到</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    // 决定要显示的楼层
                                                    if ($showEmptyData) {
                                                        $floorsToShow = range(1, 6);
                                                    } else {
                                                        $floorsToShow = isset($existingStructure[$b][$area]) ? array_keys($existingStructure[$b][$area]) : [];
                                                        sort($floorsToShow);
                                                    }
                                                    
                                                    foreach ($floorsToShow as $f): 
                                                        $fstat = isset($stat['areas'][$area][$f]) ? $stat['areas'][$area][$f] : ['total'=>0,'present'=>0,'absent'=>0,'leave'=>0,'notChecked'=>0]; 
                                                        
                                                        // 如果不显示空数据且该楼层没有学生，跳过
                                                        if (!$showEmptyData && $fstat['total'] == 0) continue;
                                                    ?>
                                                        <tr>
                                                            <td><?php echo $f; ?>层</td>
                                                            <td><?php echo $fstat['total']; ?></td>
                                                            <td><?php echo $fstat['present']; ?></td>
                                                            <td><?php echo $fstat['absent']; ?></td>
                                                            <td><?php echo $fstat['leave']; ?></td>
                                                            <td>
                                                                <?php if ($fstat['notChecked'] > 0): ?>
                                                                    <a href="statistics.php?status=未签到&building=<?php echo $b; ?>&building_area=<?php echo $area; ?>&building_floor=<?php echo $f; ?>&date=<?php echo date('Y-m-d'); ?>" 
                                                                       class="text-danger fw-bold text-decoration-none" 
                                                                       title="点击查看<?php echo $b; ?>号楼<?php echo $area; ?>区<?php echo $f; ?>层未签到学生详情">
                                                                        <?php echo $fstat['notChecked']; ?>
                                                                    </a>
                                                                <?php else: ?>
                                                                    <?php echo $fstat['notChecked']; ?>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <!-- 快速操作 -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">快速操作</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <a href="<?php echo BASE_URL; ?>/statistics.php" class="btn btn-primary btn-block">
                            <i class="bi bi-bar-chart"></i> 查看统计
                        </a>
                    </div>
                    <div class="col-md-6 mb-3">
                        <a href="<?php echo BASE_URL; ?>/leave.php" class="btn btn-warning btn-block">
                            <i class="bi bi-calendar-check"></i> 请假管理
                        </a>
                    </div>
                    <div class="col-md-6 mb-3">
                        <a href="<?php echo BASE_URL; ?>/students.php" class="btn btn-info btn-block">
                            <i class="bi bi-people"></i> 学生管理
                        </a>
                    </div>
                    <div class="col-md-6 mb-3">
                        <a href="<?php echo BASE_URL; ?>/leave.php?action=upload" class="btn btn-success btn-block">
                            <i class="bi bi-upload"></i> 上传请假名单
                        </a>
                    </div>
                    <div class="col-md-6 mb-3">
                        <button type="button" class="btn btn-primary btn-block" data-bs-toggle="modal" data-bs-target="#batchCheckinModal">
                            <i class="bi bi-check-circle"></i> 批量签到
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 最近更新 -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">最近更新</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>学号</th>
                                <th>姓名</th>
                                <th>状态</th>
                                <th>更新时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($recentUpdates) && !empty($recentUpdates)): ?>
                                <?php foreach ($recentUpdates as $update): ?>
                                    <tr>
                                        <td><?php echo $update['student_id']; ?></td>
                                        <td><?php echo $update['name']; ?></td>
                                        <td>
                                            <?php 
                                            $statusClass = getStatusClass($update['status']);
                                            echo '<span class="badge bg-' . $statusClass . '">' . $update['status'] . '</span>';
                                            ?>
                                        </td>
                                        <td><?php echo formatDateTime($update['check_time']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">暂无记录</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 图表脚本 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 检查图表元素是否存在，如果存在才初始化图表
    
    // 查寝状态分布图表
    var statusChartElement = document.getElementById('statusDistributionChart');
    if (statusChartElement) {
        var statusCtx = statusChartElement.getContext('2d');
        var statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['在寝', '请假', '离寝'],
                datasets: [{
                    data: [<?php echo $totalPresent; ?>, <?php echo $totalLeave; ?>, <?php echo $totalAbsent; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    // 楼栋在寝率图表
    var buildingChartElement = document.getElementById('buildingPresentChart');
    if (buildingChartElement) {
        var buildingCtx = buildingChartElement.getContext('2d');
        var buildingChart = new Chart(buildingCtx, {
            type: 'bar',
            data: {
                labels: [
                    <?php 
                    $buildingLabels = [];
                    $presentRates = [];
                    foreach ($buildingStats as $buildingNum => $stat) {
                        $buildingLabels[] = '"' . $buildingNum . '号楼"';
                        $presentRate = $stat['total'] > 0 ? round(($stat['present'] / $stat['total']) * 100, 1) : 0;
                        $presentRates[] = $presentRate;
                    }
                    echo implode(',', $buildingLabels);
                    ?>
                ],
                datasets: [{
                    label: '在寝率(%)',
                    data: [<?php echo implode(',', $presentRates); ?>],
                    backgroundColor: 'rgba(78, 115, 223, 0.8)',
                    borderColor: 'rgba(78, 115, 223, 1)',
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
    }
});

// 切换空数据显示的函数
function toggleEmptyDataDisplay(showEmpty) {
    // 构建新的URL参数
    const url = new URL(window.location);
    if (showEmpty) {
        url.searchParams.set('show_empty', '1');
    } else {
        url.searchParams.delete('show_empty');
    }
    
    // 显示加载状态
    const toggleSwitch = document.getElementById('showEmptyDataToggle');
    const originalText = toggleSwitch.nextElementSibling.textContent;
    toggleSwitch.nextElementSibling.textContent = '切换中...';
    toggleSwitch.disabled = true;
    
    // 跳转到新URL
    window.location.href = url.toString();
}
</script>

<!-- 辅导员楼层统计 -->
<?php if (!empty($counselor) && !empty($counselorStats)): ?>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><?php echo htmlspecialchars($counselor); ?> - 楼层统计</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                <thead class="table-light">
                    <tr>
                        <th>楼栋</th>
                        <th>区域</th>
                        <th>楼层</th>
                        <th>总人数</th>
                        <th>在寝</th>
                        <th>离寝</th>
                        <th>请假</th>
                        <th>未签到</th>
                        <th>在寝率</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($counselorStats as $stat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($stat['building']); ?></td>
                        <td><?php echo htmlspecialchars($stat['building_area']); ?></td>
                        <td><?php echo htmlspecialchars($stat['building_floor']); ?></td>
                        <td><?php echo $stat['total']; ?></td>
                        <td class="text-success"><?php echo $stat['present']; ?></td>
                        <td class="text-danger"><?php echo $stat['absent']; ?></td>
                        <td class="text-warning"><?php echo $stat['leave']; ?></td>
                        <td class="text-muted"><?php echo $stat['not_checked']; ?></td>
                        <td>
                            <?php 
                            $rate = $stat['total'] > 0 ? round(($stat['present'] / $stat['total']) * 100, 1) : 0;
                            echo $rate . '%';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 自动刷新功能 -->
<script>
(function() {
    // 从localStorage读取设置
    const AUTO_REFRESH_KEY = 'dashboard_auto_refresh';
    const REFRESH_INTERVAL_KEY = 'dashboard_refresh_interval';
    
    // 默认设置
    let autoRefreshEnabled = localStorage.getItem(AUTO_REFRESH_KEY) === 'true';
    let refreshInterval = parseInt(localStorage.getItem(REFRESH_INTERVAL_KEY)) || 60; // 默认60秒
    let refreshTimer = null;
    let countdownTimer = null;
    let countdown = 0;
    
    // 创建控制面板
    function createRefreshPanel() {
        const panel = document.createElement('div');
        panel.id = 'auto-refresh-panel';
        panel.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
            min-width: 250px;
        `;
        
        panel.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <strong style="font-size: 14px;">
                    <i class="bi bi-arrow-clockwise"></i> 自动刷新
                </strong>
                <button id="close-refresh-panel" style="border: none; background: none; cursor: pointer; font-size: 18px; color: #999;">
                    ×
                </button>
            </div>
            <div style="margin-bottom: 10px;">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" id="auto-refresh-toggle" ${autoRefreshEnabled ? 'checked' : ''} style="margin-right: 8px;">
                    <span style="font-size: 13px;">启用自动刷新</span>
                </label>
            </div>
            <div style="margin-bottom: 10px;">
                <label style="font-size: 13px; display: block; margin-bottom: 5px;">刷新间隔：</label>
                <select id="refresh-interval-select" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" ${!autoRefreshEnabled ? 'disabled' : ''}>
                    <option value="30" ${refreshInterval === 30 ? 'selected' : ''}>30秒</option>
                    <option value="60" ${refreshInterval === 60 ? 'selected' : ''}>60秒</option>
                    <option value="120" ${refreshInterval === 120 ? 'selected' : ''}>2分钟</option>
                    <option value="300" ${refreshInterval === 300 ? 'selected' : ''}>5分钟</option>
                </select>
            </div>
            <div id="refresh-countdown" style="font-size: 12px; color: #666; text-align: center; display: ${autoRefreshEnabled ? 'block' : 'none'};">
                下次刷新：<span id="countdown-text">--</span>秒
            </div>
        `;
        
        document.body.appendChild(panel);
        
        // 绑定事件
        document.getElementById('close-refresh-panel').addEventListener('click', () => {
            panel.style.display = 'none';
        });
        
        document.getElementById('auto-refresh-toggle').addEventListener('change', (e) => {
            autoRefreshEnabled = e.target.checked;
            localStorage.setItem(AUTO_REFRESH_KEY, autoRefreshEnabled);
            document.getElementById('refresh-interval-select').disabled = !autoRefreshEnabled;
            document.getElementById('refresh-countdown').style.display = autoRefreshEnabled ? 'block' : 'none';
            
            if (autoRefreshEnabled) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
        
        document.getElementById('refresh-interval-select').addEventListener('change', (e) => {
            refreshInterval = parseInt(e.target.value);
            localStorage.setItem(REFRESH_INTERVAL_KEY, refreshInterval);
            
            if (autoRefreshEnabled) {
                stopAutoRefresh();
                startAutoRefresh();
            }
        });
    }
    
    // 启动自动刷新
    function startAutoRefresh() {
        stopAutoRefresh(); // 先停止之前的定时器
        
        countdown = refreshInterval;
        updateCountdown();
        
        // 倒计时定时器
        countdownTimer = setInterval(() => {
            countdown--;
            updateCountdown();
            
            if (countdown <= 0) {
                refreshPage();
            }
        }, 1000);
    }
    
    // 停止自动刷新
    function stopAutoRefresh() {
        if (refreshTimer) {
            clearTimeout(refreshTimer);
            refreshTimer = null;
        }
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }
    
    // 更新倒计时显示
    function updateCountdown() {
        const countdownText = document.getElementById('countdown-text');
        if (countdownText) {
            countdownText.textContent = countdown;
        }
    }
    
    // 刷新页面
    function refreshPage() {
        // 保持当前的URL参数
        window.location.reload();
    }
    
    // 创建快捷按钮
    function createQuickButton() {
        const button = document.createElement('button');
        button.id = 'refresh-quick-btn';
        button.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
        button.title = '自动刷新设置';
        button.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #4e73df;
            color: white;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            cursor: pointer;
            font-size: 20px;
            z-index: 999;
            transition: all 0.3s;
        `;
        
        button.addEventListener('mouseenter', () => {
            button.style.background = '#2e59d9';
            button.style.transform = 'scale(1.1)';
        });
        
        button.addEventListener('mouseleave', () => {
            button.style.background = '#4e73df';
            button.style.transform = 'scale(1)';
        });
        
        button.addEventListener('click', () => {
            const panel = document.getElementById('auto-refresh-panel');
            if (panel) {
                panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            }
        });
        
        document.body.appendChild(button);
    }
    
    // 初始化
    document.addEventListener('DOMContentLoaded', () => {
        createRefreshPanel();
        createQuickButton();
        
        if (autoRefreshEnabled) {
            startAutoRefresh();
        }
    });
})();
</script>

<!-- 批量签到模态框 -->
<div class="modal fade" id="batchCheckinModal" tabindex="-1" aria-labelledby="batchCheckinModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchCheckinModalLabel">批量手动签到</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- 第一步：输入学号 -->
                <div id="batchCheckinStepInput">
                    <div class="mb-3">
                        <label for="batchStudentIds" class="form-label">
                            <i class="bi bi-pencil-square"></i> 输入学号 
                            <small class="text-muted">(支持逗号、空格、换行分隔，最多100人)</small>
                        </label>
                        <textarea class="form-control" id="batchStudentIds" rows="6" 
                                  placeholder="支持多种格式输入：&#10;2021001,2021002,2021003&#10;2021004 2021005 2021006&#10;2021007&#10;2021008"></textarea>
                        <small class="form-text text-muted">
                            <i class="bi bi-info-circle"></i> 提示：可以直接从Excel复制粘贴学号列
                        </small>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="queryBatchStudents()">
                        <i class="bi bi-search"></i> 查询学生信息
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearBatchInput()">
                        <i class="bi bi-x-circle"></i> 清空
                    </button>
                </div>
                
                <!-- 第二步：显示查询结果 -->
                <div id="batchCheckinStepResult" style="display: none;">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 请确认以下学生信息无误后，点击"确认签到"按钮
                    </div>
                    
                    <!-- 找到的学生 -->
                    <div id="foundStudentsSection" style="display: none;">
                        <h6 class="text-success">
                            <i class="bi bi-check-circle"></i> 找到的学生（<span id="foundCount">0</span>人）
                        </h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>学号</th>
                                        <th>姓名</th>
                                        <th>班级</th>
                                        <th>宿舍</th>
                                    </tr>
                                </thead>
                                <tbody id="foundStudentsBody">
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- 未找到的学号 -->
                    <div id="notFoundStudentsSection" style="display: none;">
                        <h6 class="text-danger">
                            <i class="bi bi-exclamation-triangle"></i> 未找到的学号（<span id="notFoundCount">0</span>个）
                        </h6>
                        <div class="alert alert-warning">
                            <div id="notFoundStudentsList"></div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" onclick="backToInput()">
                            <i class="bi bi-arrow-left"></i> 返回修改
                        </button>
                        <button type="button" class="btn btn-success" id="confirmBatchCheckinBtn" onclick="confirmBatchCheckin()">
                            <i class="bi bi-check-circle"></i> 确认签到（<span id="confirmCount">0</span>人）
                        </button>
                    </div>
                </div>
                
                <!-- 加载状态 -->
                <div id="batchCheckinLoading" style="display: none;" class="text-center my-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">处理中...</span>
                    </div>
                    <p class="mt-2">处理中，请稍候...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 批量签到功能JavaScript -->
<script>
// 存储查询到的学生数据
let batchFoundStudents = [];

// 清空输入
function clearBatchInput() {
    document.getElementById('batchStudentIds').value = '';
}

// 返回输入界面
function backToInput() {
    document.getElementById('batchCheckinStepInput').style.display = 'block';
    document.getElementById('batchCheckinStepResult').style.display = 'none';
}

// 查询学生信息
function queryBatchStudents() {
    const studentIds = document.getElementById('batchStudentIds').value.trim();
    
    if (!studentIds) {
        alert('请输入学号');
        return;
    }
    
    // 显示加载状态
    document.getElementById('batchCheckinStepInput').style.display = 'none';
    document.getElementById('batchCheckinLoading').style.display = 'block';
    
    // 发送Ajax请求
    const formData = new FormData();
    formData.append('action', 'batch_checkin');
    formData.append('student_ids', studentIds);
    
    fetch('<?php echo BASE_URL; ?>/', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // 隐藏加载状态
        document.getElementById('batchCheckinLoading').style.display = 'none';
        
        if (data.success) {
            // 存储找到的学生数据
            batchFoundStudents = data.found;
            
            // 显示结果
            displayBatchQueryResult(data.found, data.not_found);
        } else {
            alert('查询失败：' + data.message);
            document.getElementById('batchCheckinStepInput').style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('查询失败，请重试');
        document.getElementById('batchCheckinLoading').style.display = 'none';
        document.getElementById('batchCheckinStepInput').style.display = 'block';
    });
}

// 显示查询结果
function displayBatchQueryResult(foundStudents, notFoundIds) {
    // 显示找到的学生
    const foundCount = foundStudents.length;
    const notFoundCount = notFoundIds.length;
    
    if (foundCount > 0) {
        document.getElementById('foundStudentsSection').style.display = 'block';
        document.getElementById('foundCount').textContent = foundCount;
        document.getElementById('confirmCount').textContent = foundCount;
        
        let tableHtml = '';
        foundStudents.forEach(student => {
            const dormitory = student.building + '号楼' + student.building_area + student.building_floor + '-' + student.room_number;
            tableHtml += `
                <tr>
                    <td>${student.student_id}</td>
                    <td>${student.name}</td>
                    <td>${student.class_name}</td>
                    <td>${dormitory}</td>
                </tr>
            `;
        });
        document.getElementById('foundStudentsBody').innerHTML = tableHtml;
    } else {
        document.getElementById('foundStudentsSection').style.display = 'none';
    }
    
    // 显示未找到的学号
    if (notFoundCount > 0) {
        document.getElementById('notFoundStudentsSection').style.display = 'block';
        document.getElementById('notFoundCount').textContent = notFoundCount;
        document.getElementById('notFoundStudentsList').textContent = notFoundIds.join('、');
    } else {
        document.getElementById('notFoundStudentsSection').style.display = 'none';
    }
    
    // 如果没有找到任何学生，禁用确认按钮
    if (foundCount === 0) {
        document.getElementById('confirmBatchCheckinBtn').disabled = true;
    } else {
        document.getElementById('confirmBatchCheckinBtn').disabled = false;
    }
    
    // 显示结果页面
    document.getElementById('batchCheckinStepResult').style.display = 'block';
}

// 确认批量签到
function confirmBatchCheckin() {
    if (batchFoundStudents.length === 0) {
        alert('没有可签到的学生');
        return;
    }
    
    // 显示加载状态
    document.getElementById('batchCheckinStepResult').style.display = 'none';
    document.getElementById('batchCheckinLoading').style.display = 'block';
    
    // 提取学号
    const studentIds = batchFoundStudents.map(s => s.student_id);
    
    // 发送Ajax请求
    const formData = new FormData();
    formData.append('action', 'confirm_batch_checkin');
    formData.append('student_ids', JSON.stringify(studentIds));
    
    fetch('<?php echo BASE_URL; ?>/', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // 关闭模态框
        const modal = bootstrap.Modal.getInstance(document.getElementById('batchCheckinModal'));
        if (modal) {
            modal.hide();
        }
        // 刷新页面（会在页面顶部显示消息）
        location.reload();
    })
    .catch(error => {
        console.error('Error:', error);
        // 隐藏加载状态
        document.getElementById('batchCheckinLoading').style.display = 'none';
        document.getElementById('batchCheckinStepResult').style.display = 'block';
        alert('签到失败，请重试');
    });
}

// 模态框关闭时重置状态
document.getElementById('batchCheckinModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('batchStudentIds').value = '';
    document.getElementById('batchCheckinStepInput').style.display = 'block';
    document.getElementById('batchCheckinStepResult').style.display = 'none';
    document.getElementById('batchCheckinLoading').style.display = 'none';
    batchFoundStudents = [];
});
</script>

<?php
// 加载页脚
include 'templates/footer.php';
?> 