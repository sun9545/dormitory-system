# API统一响应类 - 使用指南

**文件位置：** `utils/api_response.php`  
**创建日期：** 2025-10-12

---

## 📋 简介

`ApiResponse` 类提供了统一的API响应格式，规范了所有API的返回结构和错误处理。

---

## 🎯 标准响应格式

### 成功响应
```json
{
    "code": 0,
    "success": true,
    "message": "操作成功",
    "data": {...},
    "timestamp": 1697123456
}
```

### 错误响应
```json
{
    "code": 400,
    "success": false,
    "message": "参数错误",
    "data": null,
    "timestamp": 1697123456
}
```

---

## 📚 使用方法

### 1. 成功响应

```php
require_once '../utils/api_response.php';

// 最简单的成功响应
ApiResponse::success();
// 输出：{"code":0,"success":true,"message":"操作成功","data":null,"timestamp":...}

// 返回数据
ApiResponse::success(['id' => 123, 'name' => '张三']);
// 输出：{"code":0,"success":true,"message":"操作成功","data":{"id":123,"name":"张三"},...}

// 自定义消息
ApiResponse::success(['id' => 123], '创建成功');
// 输出：{"code":0,"success":true,"message":"创建成功","data":{"id":123},...}
```

---

### 2. 错误响应

#### 通用错误
```php
ApiResponse::error('操作失败');
// 输出：{"code":500,"success":false,"message":"操作失败",...}

ApiResponse::error('数据不存在', ApiResponse::ERROR_NOT_FOUND);
// 输出：{"code":404,"success":false,"message":"数据不存在",...}
```

#### 参数错误（400）
```php
ApiResponse::paramError('缺少必填参数');
ApiResponse::paramError('学号格式不正确', ['field' => 'student_id']);
```

#### 未授权（401）
```php
ApiResponse::unauthorized();
ApiResponse::unauthorized('登录已过期，请重新登录');
```

#### 禁止访问（403）
```php
ApiResponse::forbidden('您没有权限访问此资源');
```

#### 资源不存在（404）
```php
ApiResponse::notFound('学生不存在');
```

#### 数据冲突（409）
```php
ApiResponse::conflict('学号已存在');
```

#### 频率限制（429）
```php
ApiResponse::rateLimit('请求过于频繁，请5分钟后再试');
```

#### 服务器错误（500）
```php
ApiResponse::serverError('服务器内部错误');
```

#### 数据库错误（501）
```php
ApiResponse::databaseError('数据库连接失败');
```

#### 业务错误（1000）
```php
ApiResponse::businessError('余额不足');
ApiResponse::validationError('数据验证失败', ['errors' => [...]]);
```

---

### 3. ESP32兼容格式

为了保持与ESP32设备的兼容性，提供了专用方法：

```php
// 成功
ApiResponse::esp32(true, '签到成功', ['student_id' => '2431110086']);

// 失败
ApiResponse::esp32(false, '学生不存在');
```

**输出格式：**
```json
{
    "code": 200,
    "success": true,
    "msg": "签到成功",
    "message": "签到成功",
    "data": {"student_id": "2431110086"},
    "timestamp": 1697123456
}
```

---

## 🔢 错误码体系

### 成功
| 错误码 | 说明 |
|--------|------|
| 0 | 成功 |

### 客户端错误（4xx）
| 错误码 | 说明 | HTTP状态码 |
|--------|------|-----------|
| 400 | 参数错误 | 400 |
| 401 | 未授权/未登录 | 401 |
| 403 | 禁止访问 | 403 |
| 404 | 资源不存在 | 404 |
| 409 | 数据冲突 | 409 |
| 429 | 请求过于频繁 | 429 |

### 服务器错误（5xx）
| 错误码 | 说明 | HTTP状态码 |
|--------|------|-----------|
| 500 | 服务器内部错误 | 500 |
| 501 | 数据库错误 | 500 |
| 502 | 外部服务错误 | 502 |

### 业务错误（1000+）
| 错误码 | 说明 | HTTP状态码 |
|--------|------|-----------|
| 1000 | 通用业务错误 | 400 |
| 1001 | 数据验证失败 | 400 |
| 1002 | 数据重复 | 400 |
| 1003 | 数据不存在 | 400 |
| 1004 | 权限不足 | 400 |

---

## 💡 实际应用示例

### 示例1：学生信息查询API

```php
<?php
require_once '../config/database.php';
require_once '../utils/api_response.php';

// 获取参数
$studentId = $_GET['student_id'] ?? '';

// 参数验证
if (empty($studentId)) {
    ApiResponse::paramError('学号不能为空');
}

// 查询数据库
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        ApiResponse::notFound('学生不存在');
    }
    
    // 返回成功
    ApiResponse::success($student, '查询成功');
    
} catch (PDOException $e) {
    error_log("数据库错误: " . $e->getMessage());
    ApiResponse::databaseError('查询失败');
}
```

---

### 示例2：签到API（ESP32调用）

```php
<?php
require_once '../config/database.php';
require_once '../utils/api_response.php';

// 获取POST数据
$data = json_decode(file_get_contents('php://input'), true);

$studentId = $data['student_id'] ?? '';
$deviceId = $data['device_id'] ?? '';

// 参数验证
if (empty($studentId) || empty($deviceId)) {
    ApiResponse::esp32(false, '参数错误');
}

// 业务逻辑
try {
    $pdo = getDBConnection();
    
    // 检查学生是否存在
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        ApiResponse::esp32(false, '学生不存在');
    }
    
    // 插入签到记录
    $stmt = $pdo->prepare("INSERT INTO check_records (student_id, device_id, status, check_time) VALUES (?, ?, '在寝', NOW())");
    $stmt->execute([$studentId, $deviceId]);
    
    // 返回成功
    ApiResponse::esp32(true, '签到成功', [
        'student_name' => $student['name'],
        'check_time' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    error_log("签到失败: " . $e->getMessage());
    ApiResponse::esp32(false, '签到失败');
}
```

---

### 示例3：批量操作API

```php
<?php
require_once '../config/database.php';
require_once '../utils/api_response.php';

// 获取POST数据
$data = json_decode(file_get_contents('php://input'), true);
$studentIds = $data['student_ids'] ?? [];

// 参数验证
if (empty($studentIds) || !is_array($studentIds)) {
    ApiResponse::paramError('学号列表不能为空');
}

if (count($studentIds) > 100) {
    ApiResponse::paramError('单次最多处理100条数据');
}

// 批量处理
try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    
    $successCount = 0;
    $failedList = [];
    
    foreach ($studentIds as $studentId) {
        // 处理每个学生...
        $successCount++;
    }
    
    $pdo->commit();
    
    // 返回处理结果
    ApiResponse::success([
        'total' => count($studentIds),
        'success' => $successCount,
        'failed' => count($failedList),
        'failed_list' => $failedList
    ], '批量处理完成');
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("批量处理失败: " . $e->getMessage());
    ApiResponse::serverError('批量处理失败');
}
```

---

## 🔄 迁移指南

### 旧代码
```php
// 旧的返回方式
echo json_encode(['success' => true, 'message' => '操作成功']);
exit;
```

### 新代码
```php
// 新的返回方式
ApiResponse::success(null, '操作成功');
// 注意：ApiResponse 会自动 exit，无需手动调用
```

---

## ✅ 最佳实践

### 1. 始终使用ApiResponse
```php
// ✅ 好
ApiResponse::success($data);

// ❌ 不好
echo json_encode(['success' => true, 'data' => $data]);
exit;
```

### 2. 选择合适的错误类型
```php
// ✅ 好 - 使用具体的错误方法
if (empty($studentId)) {
    ApiResponse::paramError('学号不能为空');
}

// ❌ 不好 - 使用通用错误
if (empty($studentId)) {
    ApiResponse::error('学号不能为空');
}
```

### 3. 提供详细的错误信息
```php
// ✅ 好 - 提供上下文信息
ApiResponse::validationError('数据验证失败', [
    'errors' => [
        'student_id' => '学号格式不正确',
        'name' => '姓名不能为空'
    ]
]);

// ❌ 不好 - 信息不明确
ApiResponse::error('验证失败');
```

### 4. 记录错误日志
```php
try {
    // 业务逻辑...
} catch (Exception $e) {
    // ✅ 好 - 记录详细错误
    error_log("API错误 [student_info]: " . $e->getMessage());
    ApiResponse::serverError('服务器错误');
}
```

---

## 📊 前端调用示例

### JavaScript (Fetch API)
```javascript
fetch('/api/student_info.php?student_id=2431110086')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            console.log('成功:', result.data);
        } else {
            console.error('错误:', result.message);
            // 根据错误码处理
            if (result.code === 401) {
                // 跳转到登录页
                window.location.href = '/login.php';
            }
        }
    });
```

### ESP32 (Arduino)
```cpp
HTTPClient http;
http.begin("http://server/api/checkin.php");
http.addHeader("Content-Type", "application/json");

String payload = "{\"student_id\":\"2431110086\",\"device_id\":\"ESP32_001\"}";
int httpCode = http.POST(payload);

if (httpCode == 200) {
    String response = http.getString();
    DynamicJsonDocument doc(1024);
    deserializeJson(doc, response);
    
    if (doc["success"].as<bool>()) {
        Serial.println("签到成功");
        Serial.println(doc["msg"].as<String>());
    } else {
        Serial.println("签到失败: " + doc["msg"].as<String>());
    }
}
```

---

## 🎯 总结

使用 `ApiResponse` 类的好处：
1. ✅ 统一的响应格式
2. ✅ 清晰的错误码体系
3. ✅ 简化的API开发
4. ✅ 更好的错误处理
5. ✅ 易于维护和调试
6. ✅ 兼容ESP32设备

---

**开始使用 ApiResponse 让你的API更专业！** 🚀
