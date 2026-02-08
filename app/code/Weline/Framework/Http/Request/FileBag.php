<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Request;

/**
 * FileBag - 上传文件管理类
 * 
 * 封装 $_FILES 访问，遵循单一职责原则。
 * 提供统一的上传文件访问接口。
 * 
 * @since PHP 8.4
 */
class FileBag
{
    /**
     * 文件存储
     */
    private array $files = [];
    
    /**
     * 是否已初始化
     */
    private bool $initialized = false;
    
    /**
     * 构造函数
     * 
     * @param array $files 文件数组（可选，默认从 $_FILES 获取）
     */
    public function __construct(array $files = [])
    {
        $this->files = $this->normalizeFiles($files);
    }
    
    /**
     * 从超全局变量初始化
     * 
     * @return static
     */
    public function initFromGlobals(): static
    {
        if ($this->initialized) {
            return $this;
        }
        
        $this->files = $this->normalizeFiles($_FILES);
        $this->initialized = true;
        
        return $this;
    }
    
    /**
     * 规范化文件数组
     * 
     * PHP 的 $_FILES 数组结构在多文件上传时比较奇怪，
     * 此方法将其规范化为更易用的结构。
     * 
     * @param array $files 原始文件数组
     * @return array 规范化后的文件数组
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        
        foreach ($files as $key => $file) {
            if (is_array($file['name'] ?? null)) {
                // 多文件上传情况
                $normalized[$key] = $this->normalizeMultipleFiles($file);
            } else {
                // 单文件上传情况
                $normalized[$key] = $file;
            }
        }
        
        return $normalized;
    }
    
    /**
     * 规范化多文件上传
     * 
     * @param array $file 原始多文件数组
     * @return array 规范化后的文件数组
     */
    private function normalizeMultipleFiles(array $file): array
    {
        $result = [];
        $count = count($file['name']);
        
        for ($i = 0; $i < $count; $i++) {
            $result[$i] = [
                'name' => $file['name'][$i] ?? '',
                'type' => $file['type'][$i] ?? '',
                'tmp_name' => $file['tmp_name'][$i] ?? '',
                'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$i] ?? 0,
            ];
        }
        
        return $result;
    }
    
    // ==================== 文件访问方法 ====================
    
    /**
     * 获取上传文件
     * 
     * @param string $key 文件字段名，空字符串返回所有
     * @return array|null
     */
    public function get(string $key = ''): array|null
    {
        if ($key === '') {
            return $this->files;
        }
        return $this->files[$key] ?? null;
    }
    
    /**
     * 检查文件是否存在
     * 
     * @param string $key 文件字段名
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->files[$key]);
    }
    
    /**
     * 获取所有文件
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->files;
    }
    
    /**
     * 获取文件数量
     * 
     * @return int
     */
    public function count(): int
    {
        return count($this->files);
    }
    
    // ==================== 文件验证方法 ====================
    
    /**
     * 检查文件是否上传成功
     * 
     * @param string $key 文件字段名
     * @return bool
     */
    public function isUploadedSuccessfully(string $key): bool
    {
        $file = $this->get($key);
        if (!$file) {
            return false;
        }
        
        // 处理多文件情况
        if (isset($file[0])) {
            foreach ($file as $f) {
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    return false;
                }
            }
            return true;
        }
        
        return ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }
    
    /**
     * 获取上传错误信息
     * 
     * @param int $errorCode 错误代码
     * @return string
     */
    public static function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_OK => 'File uploaded successfully.',
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'Unknown upload error.',
        };
    }
    
    /**
     * 验证文件类型
     * 
     * @param string $key 文件字段名
     * @param array $allowedTypes 允许的 MIME 类型
     * @return bool
     */
    public function isValidMimeType(string $key, array $allowedTypes): bool
    {
        $file = $this->get($key);
        if (!$file) {
            return false;
        }
        
        // 处理多文件情况
        if (isset($file[0])) {
            foreach ($file as $f) {
                if (!in_array($f['type'] ?? '', $allowedTypes, true)) {
                    return false;
                }
            }
            return true;
        }
        
        return in_array($file['type'] ?? '', $allowedTypes, true);
    }
    
    /**
     * 验证文件大小
     * 
     * @param string $key 文件字段名
     * @param int $maxSize 最大大小（字节）
     * @return bool
     */
    public function isValidSize(string $key, int $maxSize): bool
    {
        $file = $this->get($key);
        if (!$file) {
            return false;
        }
        
        // 处理多文件情况
        if (isset($file[0])) {
            foreach ($file as $f) {
                if (($f['size'] ?? 0) > $maxSize) {
                    return false;
                }
            }
            return true;
        }
        
        return ($file['size'] ?? 0) <= $maxSize;
    }
    
    /**
     * 验证文件扩展名
     * 
     * @param string $key 文件字段名
     * @param array $allowedExtensions 允许的扩展名（不带点）
     * @return bool
     */
    public function isValidExtension(string $key, array $allowedExtensions): bool
    {
        $file = $this->get($key);
        if (!$file) {
            return false;
        }
        
        $allowedExtensions = array_map('strtolower', $allowedExtensions);
        
        // 处理多文件情况
        if (isset($file[0])) {
            foreach ($file as $f) {
                $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions, true)) {
                    return false;
                }
            }
            return true;
        }
        
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        return in_array($ext, $allowedExtensions, true);
    }
    
    // ==================== 文件操作方法 ====================
    
    /**
     * 移动上传文件
     * 
     * @param string $key 文件字段名
     * @param string $destination 目标路径
     * @param int $index 多文件时的索引（默认 0）
     * @return bool
     */
    public function move(string $key, string $destination, int $index = 0): bool
    {
        $file = $this->get($key);
        if (!$file) {
            return false;
        }
        
        // 处理多文件情况
        if (isset($file[0])) {
            $file = $file[$index] ?? null;
            if (!$file) {
                return false;
            }
        }
        
        $tmpName = $file['tmp_name'] ?? '';
        if (!$tmpName || !is_uploaded_file($tmpName)) {
            return false;
        }
        
        // 确保目标目录存在
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return move_uploaded_file($tmpName, $destination);
    }
    
    /**
     * 获取文件临时路径
     * 
     * @param string $key 文件字段名
     * @param int $index 多文件时的索引（默认 0）
     * @return string|null
     */
    public function getTmpName(string $key, int $index = 0): ?string
    {
        $file = $this->get($key);
        if (!$file) {
            return null;
        }
        
        if (isset($file[0])) {
            return $file[$index]['tmp_name'] ?? null;
        }
        
        return $file['tmp_name'] ?? null;
    }
    
    /**
     * 获取原始文件名
     * 
     * @param string $key 文件字段名
     * @param int $index 多文件时的索引（默认 0）
     * @return string|null
     */
    public function getOriginalName(string $key, int $index = 0): ?string
    {
        $file = $this->get($key);
        if (!$file) {
            return null;
        }
        
        if (isset($file[0])) {
            return $file[$index]['name'] ?? null;
        }
        
        return $file['name'] ?? null;
    }
    
    /**
     * 获取文件大小
     * 
     * @param string $key 文件字段名
     * @param int $index 多文件时的索引（默认 0）
     * @return int
     */
    public function getSize(string $key, int $index = 0): int
    {
        $file = $this->get($key);
        if (!$file) {
            return 0;
        }
        
        if (isset($file[0])) {
            return (int) ($file[$index]['size'] ?? 0);
        }
        
        return (int) ($file['size'] ?? 0);
    }
    
    /**
     * 获取文件 MIME 类型
     * 
     * @param string $key 文件字段名
     * @param int $index 多文件时的索引（默认 0）
     * @return string|null
     */
    public function getMimeType(string $key, int $index = 0): ?string
    {
        $file = $this->get($key);
        if (!$file) {
            return null;
        }
        
        if (isset($file[0])) {
            return $file[$index]['type'] ?? null;
        }
        
        return $file['type'] ?? null;
    }
    
    // ==================== 重置和清理 ====================
    
    /**
     * 重置文件
     * 
     * @return static
     */
    public function reset(): static
    {
        $this->files = [];
        $this->initialized = false;
        return $this;
    }
    
    /**
     * 替换所有文件
     * 
     * @param array $files 新的文件数组
     * @return static
     */
    public function replace(array $files): static
    {
        $this->files = $this->normalizeFiles($files);
        return $this;
    }
}
