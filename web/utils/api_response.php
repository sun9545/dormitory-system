<?php
/**
 * 统一API响应类
 * 
 * 用于规范所有API的返回格式，提供统一的错误处理和响应结构
 * 
 * @author AI Assistant
 * @date 2025-10-12
 */

class ApiResponse {
    // ==================== 错误码定义 ====================
    
    // 成功
    const SUCCESS = 0;
    
    // 客户端错误 (4xx)
    const ERROR_PARAM = 400;           // 参数错误
    const ERROR_AUTH = 401;            // 未授权/未登录
    const ERROR_FORBIDDEN = 403;       // 禁止访问
    const ERROR_NOT_FOUND = 404;       // 资源不存在
    const ERROR_CONFLICT = 409;        // 冲突（如重复数据）
    const ERROR_RATE_LIMIT = 429;      // 请求过于频繁
    
    // 服务器错误 (5xx)
    const ERROR_SERVER = 500;          // 服务器内部错误
    const ERROR_DATABASE = 501;        // 数据库错误
    const ERROR_EXTERNAL = 502;        // 外部服务错误
    
    // 业务错误 (自定义)
    const ERROR_BUSINESS = 1000;       // 通用业务错误
    const ERROR_VALIDATION = 1001;     // 数据验证失败
    const ERROR_DUPLICATE = 1002;      // 数据重复
    const ERROR_NOT_EXIST = 1003;      // 数据不存在
    const ERROR_PERMISSION = 1004;     // 权限不足
    
    // ==================== 响应方法 ====================
    
    /**
     * 成功响应
     * 
     * @param mixed $data 返回的数据
     * @param string $message 成功消息
     * @param int $httpCode HTTP状态码（默认200）
     */
    public static function success($data = null, $message = '操作成功', $httpCode = 200) {
        self::output([
            'code' => self::SUCCESS,
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], $httpCode);
    }
    
    /**
     * 错误响应
     * 
     * @param string $message 错误消息
     * @param int $code 错误码（默认500）
     * @param mixed $data 附加数据（可选）
     * @param int $httpCode HTTP状态码（默认根据错误码自动判断）
     */
    public static function error($message = '操作失败', $code = self::ERROR_SERVER, $data = null, $httpCode = null) {
        // 如果未指定HTTP状态码，根据错误码自动判断
        if ($httpCode === null) {
            $httpCode = self::getHttpCodeFromErrorCode($code);
        }
        
        self::output([
            'code' => $code,
            'success' => false,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], $httpCode);
    }
    
    /**
     * 参数错误响应
     */
    public static function paramError($message = '参数错误', $data = null) {
        self::error($message, self::ERROR_PARAM, $data, 400);
    }
    
    /**
     * 未授权响应
     */
    public static function unauthorized($message = '未授权，请先登录', $data = null) {
        self::error($message, self::ERROR_AUTH, $data, 401);
    }
    
    /**
     * 禁止访问响应
     */
    public static function forbidden($message = '禁止访问', $data = null) {
        self::error($message, self::ERROR_FORBIDDEN, $data, 403);
    }
    
    /**
     * 资源不存在响应
     */
    public static function notFound($message = '资源不存在', $data = null) {
        self::error($message, self::ERROR_NOT_FOUND, $data, 404);
    }
    
    /**
     * 数据冲突响应
     */
    public static function conflict($message = '数据冲突', $data = null) {
        self::error($message, self::ERROR_CONFLICT, $data, 409);
    }
    
    /**
     * 频率限制响应
     */
    public static function rateLimit($message = '请求过于频繁，请稍后再试', $data = null) {
        self::error($message, self::ERROR_RATE_LIMIT, $data, 429);
    }
    
    /**
     * 服务器错误响应
     */
    public static function serverError($message = '服务器错误', $data = null) {
        self::error($message, self::ERROR_SERVER, $data, 500);
    }
    
    /**
     * 数据库错误响应
     */
    public static function databaseError($message = '数据库错误', $data = null) {
        self::error($message, self::ERROR_DATABASE, $data, 500);
    }
    
    /**
     * 业务错误响应
     */
    public static function businessError($message = '业务处理失败', $data = null) {
        self::error($message, self::ERROR_BUSINESS, $data, 400);
    }
    
    /**
     * 验证失败响应
     */
    public static function validationError($message = '数据验证失败', $data = null) {
        self::error($message, self::ERROR_VALIDATION, $data, 400);
    }
    
    // ==================== 兼容方法（ESP32专用） ====================
    
    /**
     * ESP32兼容格式响应
     * 用于保持与ESP32设备的兼容性
     * 
     * @param bool $success 是否成功
     * @param string $msg 消息
     * @param mixed $data 数据
     */
    public static function esp32($success, $msg, $data = null) {
        $code = $success ? 200 : 400;
        
        self::output([
            'code' => $code,
            'success' => $success,
            'msg' => $msg,
            'message' => $msg,  // 同时提供两种格式
            'data' => $data,
            'timestamp' => time()
        ], $code);
    }
    
    // ==================== 私有方法 ====================
    
    /**
     * 输出JSON响应
     * 
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     */
    private static function output($data, $httpCode = 200) {
        // 设置HTTP状态码
        http_response_code($httpCode);
        
        // 设置响应头
        header('Content-Type: application/json; charset=utf-8');
        
        // 输出JSON（保持中文不转义）
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // 终止脚本
        exit;
    }
    
    /**
     * 根据错误码获取HTTP状态码
     * 
     * @param int $errorCode 错误码
     * @return int HTTP状态码
     */
    private static function getHttpCodeFromErrorCode($errorCode) {
        // 4xx 客户端错误
        if ($errorCode >= 400 && $errorCode < 500) {
            return $errorCode;
        }
        
        // 5xx 服务器错误
        if ($errorCode >= 500 && $errorCode < 600) {
            return $errorCode;
        }
        
        // 业务错误（1000+）统一返回400
        if ($errorCode >= 1000) {
            return 400;
        }
        
        // 默认返回500
        return 500;
    }
    
    // ==================== 工具方法 ====================
    
    /**
     * 获取错误码说明
     * 
     * @param int $code 错误码
     * @return string 错误码说明
     */
    public static function getErrorMessage($code) {
        $messages = [
            self::SUCCESS => '成功',
            self::ERROR_PARAM => '参数错误',
            self::ERROR_AUTH => '未授权',
            self::ERROR_FORBIDDEN => '禁止访问',
            self::ERROR_NOT_FOUND => '资源不存在',
            self::ERROR_CONFLICT => '数据冲突',
            self::ERROR_RATE_LIMIT => '请求过于频繁',
            self::ERROR_SERVER => '服务器错误',
            self::ERROR_DATABASE => '数据库错误',
            self::ERROR_EXTERNAL => '外部服务错误',
            self::ERROR_BUSINESS => '业务错误',
            self::ERROR_VALIDATION => '验证失败',
            self::ERROR_DUPLICATE => '数据重复',
            self::ERROR_NOT_EXIST => '数据不存在',
            self::ERROR_PERMISSION => '权限不足',
        ];
        
        return $messages[$code] ?? '未知错误';
    }
}

