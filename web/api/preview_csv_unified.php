<?php
require_once '../config/config.php';
require_once '../utils/auth.php';
require_once '../utils/CSVEncodingHandler.php';

// 检查是否被其他文件包含，如果不是则执行完整的安全检查
if (!defined('INCLUDED_FROM_OTHER_FILE')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        startSecureSession();
        
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => '未登录']);
            exit;
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => '方法不允许']);
            exit;
        }
        
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
            exit;
        }
        
        if (!isset($_FILES['preview_file']) || $_FILES['preview_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => '文件上传失败']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '初始化失败: ' . $e->getMessage()]);
        exit;
    }
} else {
    // 如果被包含，跳过安全检查（已经在调用文件中检查过）
}

// 开始CSV处理逻辑
try {
    $file = $_FILES['preview_file'];
    $content = file_get_contents($file['tmp_name']);
    
    // 使用修复后的编码处理
    $encodingResult = CSVEncodingHandler::detectAndConvertEncoding($content);
    $content = $encodingResult['content'];
    
    // 解析CSV内容，处理空行
    $lines = explode("\n", $content);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, function($line) {
        return !empty($line); // 过滤空行
    });
    $lines = array_values($lines); // 重新索引
    
    if (count($lines) < 2) {
        echo json_encode(['success' => false, 'message' => 'CSV文件格式错误：至少需要标题行和一行数据']);
        exit;
    }
    
    // 假设第一行就是表头（已修复模板生成问题）
    $headerRowIndex = 0;
    
    // 智能检测分隔符（使用找到的表头行）
    $delimiter = ',';
    if (count($lines) > $headerRowIndex) {
        $headerLine = $lines[$headerRowIndex];
        $commaCount = substr_count($headerLine, ',');
        $semicolonCount = substr_count($headerLine, ';');
        $tabCount = substr_count($headerLine, "\t");
        
        if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
            $delimiter = ';';
        } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
            $delimiter = "\t";
        }
    }
    
    // 解析表头（使用找到的表头行）
    $headers = str_getcsv($lines[$headerRowIndex], $delimiter);
    $headers = array_map('trim', $headers);
    
    // 调试信息：显示解析到的表头
    error_log("CSV表头: " . json_encode($headers));
    error_log("第一行原始数据: " . $lines[0]);
    if (isset($lines[1])) {
        error_log("第二行原始数据: " . $lines[1]);
    }
    
    
    // 获取预览类型
    $previewType = isset($_POST['preview_type']) ? $_POST['preview_type'] : 'student';
    
    // 根据模块类型设置字段映射
    $fieldMapping = [];
    $defaultRowData = [];
    
    if ($previewType === 'student') {
        $fieldMapping = [
            '学号' => 'student_id',
            '姓名' => 'name',
            '性别' => 'gender',
            '班级' => 'class_name',
            '楼栋' => 'building',
            '区域' => 'building_area',
            '楼层' => 'building_floor',
            '宿舍号' => 'room_number',
            '床号' => 'bed_number',
            '辅导员' => 'counselor',
            '辅导员电话' => 'counselor_phone'
        ];
        $defaultRowData = [
            'student_id' => '', 'name' => '', 'gender' => '', 'class_name' => '',
            'building' => '', 'building_area' => '', 'building_floor' => '',
            'room_number' => '', 'bed_number' => '', 'counselor' => '', 'counselor_phone' => ''
        ];
    } elseif ($previewType === 'leave') {
        $fieldMapping = [
            '班级' => 'class',
            '姓名' => 'name',
            '学号' => 'student_id'
        ];
        $defaultRowData = ['class' => '', 'name' => '', 'student_id' => ''];
    } elseif ($previewType === 'fingerprint') {
        $fieldMapping = [
            '学号' => 'student_id',
            '设备ID' => 'device_id',
            '指纹ID' => 'fingerprint_id',
            '手指编号(可选)' => 'finger_number' // 标准表头
        ];
        $defaultRowData = ['student_id' => '', 'device_id' => '', 'fingerprint_id' => '', 'finger_number' => ''];
    }
    
    $previewData = [];
    
    // 获取预览行数限制（支持自定义）
    $previewLimit = isset($_POST['preview_limit']) ? intval($_POST['preview_limit']) : 50;
    // 验证范围：最小10行，最大1000行，0表示全部
    if ($previewLimit < 0) {
        $previewLimit = 50; // 默认50行
    } elseif ($previewLimit > 1000 && $previewLimit !== 0) {
        $previewLimit = 1000; // 最大1000行
    }
    
    // 计算实际预览的结束位置
    $totalDataRows = count($lines) - $headerRowIndex - 1;
    if ($previewLimit === 0 || $previewLimit > $totalDataRows) {
        // 0表示全部，或者请求的行数超过总行数
        $endIndex = count($lines);
    } else {
        $endIndex = $headerRowIndex + 1 + $previewLimit;
    }
    
    // 处理数据行（从表头行之后开始）
    for ($i = $headerRowIndex + 1; $i < $endIndex; $i++) {
        $data = str_getcsv($lines[$i], $delimiter);
        
        // 初始化行数据
        $rowData = $defaultRowData;
        
        // 智能字段映射
        for ($j = 0; $j < count($headers) && $j < count($data); $j++) {
            $headerName = trim($headers[$j]);
            if (isset($fieldMapping[$headerName])) {
                $fieldName = $fieldMapping[$headerName];
                $originalValue = $data[$j];
                $cleanedValue = CSVEncodingHandler::cleanFieldData($data[$j]); // 使用修复后的cleanFieldData
                $rowData[$fieldName] = $cleanedValue;
                error_log("字段映射成功: '{$headerName}' -> '{$fieldName}' = '{$cleanedValue}'");
            } else {
                error_log("字段映射失败: 未找到表头 '{$headerName}' 的映射");
            }
        }
        error_log("行数据最终结果: " . json_encode($rowData));
        
        $previewData[] = $rowData;
    }
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => $previewData,
        'total' => count($lines) - $headerRowIndex - 1,
        'preview_count' => count($previewData),
        'preview_limit' => $previewLimit,
        'is_full_preview' => ($previewLimit === 0 || count($previewData) >= $totalDataRows),
        'encoding_detected' => $encodingResult['encoding'],
        'encoding_converted' => $encodingResult['converted'],
        'detection_method' => $encodingResult['detection_method'],
        'preview_type' => $previewType,
        'debug_info' => [
            'headers_parsed' => $headers,
            'header_row_index' => $headerRowIndex,
            'first_data_line' => isset($lines[$headerRowIndex + 1]) ? $lines[$headerRowIndex + 1] : 'N/A',
            'field_mapping' => $fieldMapping,
            'delimiter_used' => $delimiter,
            'total_lines' => count($lines)
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '处理失败: ' . $e->getMessage(),
        'error_code' => 'STUDENT_CSV_PROCESSING_ERROR'
    ]);
}