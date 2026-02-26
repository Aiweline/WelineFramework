<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

/**
 * @DESC | 统一存储接口，定义所有存储后端必须实现的方法
 */
interface StorageInterface
{
    /**
     * 获取存储驱动标识
     */
    public function getDriver(): string;
    
    /**
     * 写入文件
     *
     * @param string $path 相对路径
     * @param string|resource $contents 文件内容或文件流
     * @param array $options 额外选项（如 visibility, content_type 等）
     * @return bool 是否成功
     */
    public function put(string $path, $contents, array $options = []): bool;
    
    /**
     * 以流的方式写入文件
     *
     * @param string $path 相对路径
     * @param resource $resource 文件流
     * @param array $options 额外选项
     * @return bool 是否成功
     */
    public function putStream(string $path, $resource, array $options = []): bool;
    
    /**
     * 读取文件内容
     *
     * @param string $path 相对路径
     * @return string|null 文件内容，失败返回 null
     */
    public function get(string $path): ?string;
    
    /**
     * 以流的方式读取文件
     *
     * @param string $path 相对路径
     * @return resource|null 文件流，失败返回 null
     */
    public function getStream(string $path);
    
    /**
     * 删除文件
     *
     * @param string $path 相对路径
     * @return bool 是否成功
     */
    public function delete(string $path): bool;
    
    /**
     * 批量删除文件
     *
     * @param array $paths 相对路径数组
     * @return bool 是否全部成功
     */
    public function deleteMultiple(array $paths): bool;
    
    /**
     * 检查文件是否存在
     *
     * @param string $path 相对路径
     * @return bool
     */
    public function exists(string $path): bool;
    
    /**
     * 获取文件的公开 URL
     *
     * @param string $path 相对路径
     * @return string|null URL，失败返回 null
     */
    public function url(string $path): ?string;
    
    /**
     * 获取文件的临时签名 URL（适用于私有存储桶）
     *
     * @param string $path 相对路径
     * @param int $expiration 过期时间（秒）
     * @return string|null 签名 URL，失败返回 null
     */
    public function temporaryUrl(string $path, int $expiration = 3600): ?string;
    
    /**
     * 获取文件大小（字节）
     *
     * @param string $path 相对路径
     * @return int|null 文件大小，失败返回 null
     */
    public function size(string $path): ?int;
    
    /**
     * 获取文件最后修改时间
     *
     * @param string $path 相对路径
     * @return int|null Unix 时间戳，失败返回 null
     */
    public function lastModified(string $path): ?int;
    
    /**
     * 获取文件 MIME 类型
     *
     * @param string $path 相对路径
     * @return string|null MIME 类型，失败返回 null
     */
    public function mimeType(string $path): ?string;
    
    /**
     * 复制文件
     *
     * @param string $from 源路径
     * @param string $to 目标路径
     * @return bool 是否成功
     */
    public function copy(string $from, string $to): bool;
    
    /**
     * 移动/重命名文件
     *
     * @param string $from 源路径
     * @param string $to 目标路径
     * @return bool 是否成功
     */
    public function move(string $from, string $to): bool;
    
    /**
     * 列出目录内容
     *
     * @param string $directory 目录路径
     * @param bool $recursive 是否递归
     * @return array 文件/目录信息数组
     */
    public function list(string $directory = '', bool $recursive = false): array;
    
    /**
     * 创建目录
     *
     * @param string $path 目录路径
     * @return bool 是否成功
     */
    public function makeDirectory(string $path): bool;
    
    /**
     * 删除目录
     *
     * @param string $path 目录路径
     * @return bool 是否成功
     */
    public function deleteDirectory(string $path): bool;
    
    /**
     * 测试连接是否正常
     *
     * @return bool 连接是否正常
     */
    public function testConnection(): bool;
    
    /**
     * 获取存储配置信息（隐藏敏感信息）
     *
     * @return array
     */
    public function getInfo(): array;
}
