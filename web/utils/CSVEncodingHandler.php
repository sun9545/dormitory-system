<?php
/**
 * CSV文件编码处理统一类
 * 
 * 用于统一处理CSV文件的编码检测、转换和数据清理
 * 确保预览和导入阶段使用完全一致的编码处理逻辑
 * 
 * @author System
 * @version 1.0
 * @date 2025-09-26
 */

class CSVEncodingHandler {
    
    /**
     * 支持的编码类型
     */
    const SUPPORTED_ENCODINGS = ['UTF-8', 'GBK', 'GB2312', 'BIG5'];
    
    /**
     * 试探性编码检测顺序
     */
    const FALLBACK_ENCODINGS = ['GBK', 'GB2312', 'BIG5'];
    
    /**
     * 默认编码（当所有检测都失败时使用）
     */
    const DEFAULT_ENCODING = 'GBK';
    
    /**
     * 日志文件路径
     */
    const LOG_FILE = 'logs/csv_processing.log';
    
    /**
     * 中文字符检测正则表达式
     */
    const CHINESE_CHAR_PATTERN = '/[\x{4e00}-\x{9fa5}]/u';
    
    /**
     * 检测并转换CSV文件编码（修复版）
     * 
     * @param string $content 文件内容
     * @param callable $logCallback 日志回调函数
     * @return array 包含转换后内容、检测到的编码、是否转换的数组
     */
    public static function detectAndConvertEncoding($content, $logCallback = null) {
        $result = [
            'content' => $content,
            'encoding' => 'UTF-8',
            'converted' => false,
            'detection_method' => 'none'
        ];
        
        try {
            // 记录开始处理
            if ($logCallback) {
                $logCallback("开始编码检测 - 文件大小: " . strlen($content) . " 字节");
            }
            
            // 第一步：检查是否已经是有效的UTF-8
            if (mb_check_encoding($content, 'UTF-8')) {
                if ($logCallback) {
                    $logCallback("内容已经是有效的UTF-8编码");
                }
                $result['encoding'] = 'UTF-8';
                $result['detection_method'] = 'utf8_valid';
                $result['content'] = self::cleanContent($content);
                return $result;
            }
            
            // 第二步：标准编码检测
            $encoding = mb_detect_encoding($content, self::SUPPORTED_ENCODINGS, true);
            
            if ($encoding && $encoding !== 'UTF-8') {
                if ($logCallback) {
                    $logCallback("检测到编码: " . $encoding);
                }
                
                // 进行编码转换
                $convertedContent = mb_convert_encoding($content, 'UTF-8', $encoding);
                
                // 验证转换结果是否包含有效的中文字符
                if ($convertedContent !== false && mb_check_encoding($convertedContent, 'UTF-8')) {
                    $result['content'] = self::cleanContent($convertedContent);
                    $result['encoding'] = $encoding;
                    $result['converted'] = true;
                    $result['detection_method'] = 'standard';
                    
                    if ($logCallback) {
                        $logCallback("编码转换成功: {$encoding} -> UTF-8");
                    }
                } else {
                    if ($logCallback) {
                        $logCallback("编码转换失败，使用原内容");
                    }
                    $result['content'] = self::cleanContent($content);
                }
            } else {
                // 如果检测失败，直接使用原内容
                if ($logCallback) {
                    $logCallback("编码检测失败，直接使用原内容");
                }
                $result['content'] = self::cleanContent($content);
                $result['detection_method'] = 'fallback';
            }
            
        } catch (Exception $e) {
            if ($logCallback) {
                $logCallback("编码处理异常: " . $e->getMessage());
            }
            
            // 异常时返回清理后的原内容
            $result['content'] = self::cleanContent($content);
            $result['detection_method'] = 'error';
        }
        
        return $result;
    }
    
    /**
     * 试探性编码检测
     * 
     * @param string $content 文件内容
     * @param callable $logCallback 日志回调函数
     * @return string 检测到的编码
     */
    private static function detectEncodingByTrial($content, $logCallback = null) {
        foreach (self::FALLBACK_ENCODINGS as $tryEncoding) {
            if ($logCallback) {
                $logCallback("尝试编码: " . $tryEncoding);
            }
            
            $testContent = mb_convert_encoding($content, 'UTF-8', $tryEncoding);
            
            // 检查转换是否成功且包含有效中文字符
            if ($testContent !== false && preg_match(self::CHINESE_CHAR_PATTERN, $testContent)) {
                if ($logCallback) {
                    $logCallback("试探检测成功: " . $tryEncoding);
                }
                return $tryEncoding;
            }
        }
        
        // 所有试探都失败，使用默认编码
        if ($logCallback) {
            $logCallback("所有编码检测失败，使用默认编码: " . self::DEFAULT_ENCODING);
        }
        
        return self::DEFAULT_ENCODING;
    }
    
    /**
     * 清理文件内容
     * 
     * @param string $content 原始内容
     * @return string 清理后的内容
     */
    private static function cleanContent($content) {
        // 移除UTF-8 BOM
        if (substr($content, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) {
            $content = substr($content, 3);
        }
        
        // 移除其他可能的BOM标记
        $content = str_replace([
            "\xEF\xBB\xBF",  // UTF-8 BOM
            "\xEF\xBD\xBF",  // UTF-8 BOM 变体
            "\xFE\xFF",      // UTF-16 BE BOM
            "\xFF\xFE"       // UTF-16 LE BOM
        ], '', $content);
        
        // 移除零宽度字符
        $content = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $content);
        
        return $content;
    }
    
    /**
     * 清理CSV字段数据
     * 
     * @param string $value 字段值
     * @return string 清理后的字段值
     */
    public static function cleanFieldData($value) {
        $value = trim($value);
        
        // 只移除真正有害的控制字符，保护中文字符
        // 移除NULL字符、制表符之外的控制字符
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // 移除UTF-8 BOM和替换字符
        $value = str_replace(["\xEF\xBB\xBF", "\xEF\xBD\xBF"], '', $value);
        
        // 移除零宽度字符
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
        
        return $value;
    }
    
    /**
     * 生成内容哈希值（用于缓存）
     * 
     * @param string $content 文件内容
     * @return string 哈希值
     */
    public static function generateContentHash($content) {
        return md5($content);
    }
    
    /**
     * 缓存编码检测结果
     * 
     * @param string $contentHash 内容哈希
     * @param array $encodingResult 编码检测结果
     */
    public static function cacheEncodingResult($contentHash, $encodingResult) {
        if (!isset($_SESSION['csv_encoding_cache'])) {
            $_SESSION['csv_encoding_cache'] = [];
        }
        
        $_SESSION['csv_encoding_cache'][$contentHash] = [
            'encoding' => $encodingResult['encoding'],
            'converted' => $encodingResult['converted'],
            'detection_method' => $encodingResult['detection_method'],
            'timestamp' => time()
        ];
    }
    
    /**
     * 获取缓存的编码检测结果
     * 
     * @param string $contentHash 内容哈希
     * @return array|null 缓存的结果或null
     */
    public static function getCachedEncodingResult($contentHash) {
        if (!isset($_SESSION['csv_encoding_cache'][$contentHash])) {
            return null;
        }
        
        $cached = $_SESSION['csv_encoding_cache'][$contentHash];
        
        // 检查缓存是否过期（30分钟）
        if (time() - $cached['timestamp'] > 1800) {
            unset($_SESSION['csv_encoding_cache'][$contentHash]);
            return null;
        }
        
        return $cached;
    }
    
    /**
     * 清理过期的编码缓存
     */
    public static function cleanExpiredCache() {
        if (!isset($_SESSION['csv_encoding_cache'])) {
            return;
        }
        
        $currentTime = time();
        foreach ($_SESSION['csv_encoding_cache'] as $hash => $cache) {
            if ($currentTime - $cache['timestamp'] > 1800) {
                unset($_SESSION['csv_encoding_cache'][$hash]);
            }
        }
    }
    
    /**
     * 带缓存的编码检测和转换
     * 
     * @param string $content 文件内容
     * @param callable $logCallback 日志回调函数
     * @return array 编码处理结果
     */
    public static function detectAndConvertEncodingWithCache($content, $logCallback = null) {
        // 清理过期缓存
        self::cleanExpiredCache();
        
        // 生成内容哈希
        $contentHash = self::generateContentHash($content);
        
        // 尝试从缓存获取结果
        $cachedResult = self::getCachedEncodingResult($contentHash);
        
        if ($cachedResult) {
            if ($logCallback) {
                $logCallback("使用缓存结果 - 编码: " . $cachedResult['encoding']);
            }
            
            // 重建完整结果
            $encodingResult = [
                'content' => $content,
                'encoding' => $cachedResult['encoding'],
                'converted' => $cachedResult['converted'],
                'detection_method' => $cachedResult['detection_method']
            ];
            
            // 如果需要转换，重新应用转换和清理
            if ($cachedResult['converted'] && $cachedResult['encoding'] !== 'UTF-8') {
                $encodingResult['content'] = mb_convert_encoding($content, 'UTF-8', $cachedResult['encoding']);
            }
            $encodingResult['content'] = self::cleanContent($encodingResult['content']);
            
            return $encodingResult;
        } else {
            // 执行完整的编码检测
            $encodingResult = self::detectAndConvertEncoding($content, $logCallback);
            
            // 缓存结果
            self::cacheEncodingResult($contentHash, $encodingResult);
            
            return $encodingResult;
        }
    }
    
    /**
     * 统一日志记录函数
     * 
     * @param string $level 日志级别 (INFO, ERROR, SUCCESS)
     * @param string $message 日志消息
     * @param string $module 模块名称
     */
    public static function logMessage($level, $message, $module = 'CSV') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$module}: {$message}" . PHP_EOL;
        
        // 写入日志文件
        if (is_writable(dirname(self::LOG_FILE))) {
            file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
        }
        
        // 同时写入PHP错误日志
        error_log($logEntry);
    }
    
    /**
     * 格式化错误响应
     * 
     * @param string $message 用户友好的错误信息
     * @param string $errorCode 错误代码
     * @param string $details 技术详细信息
     * @return array 格式化的错误响应
     */
    public static function formatErrorResponse($message, $errorCode = 'CSV_PROCESSING_ERROR', $details = null) {
        $response = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode
        ];
        
        if ($details) {
            $response['details'] = $details;
        }
        
        // 记录错误日志
        self::logMessage('ERROR', "{$errorCode}: {$message}" . ($details ? " - {$details}" : ''));
        
        return $response;
    }
    
    /**
     * 格式化成功响应
     * 
     * @param array $data 响应数据
     * @param array $metadata 元数据信息
     * @return array 格式化的成功响应
     */
    public static function formatSuccessResponse($data, $metadata = []) {
        $response = [
            'success' => true,
            'data' => $data
        ];
        
        // 合并元数据
        $response = array_merge($response, $metadata);
        
        // 记录成功日志
        $total = isset($metadata['total']) ? $metadata['total'] : count($data);
        $previewType = isset($metadata['preview_type']) ? $metadata['preview_type'] : 'unknown';
        self::logMessage('SUCCESS', "CSV预览成功 - 模块: {$previewType}, 行数: {$total}");
        
        return $response;
    }
}