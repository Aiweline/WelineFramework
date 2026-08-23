<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

/**
 * 面向文件管理 UI 的数据化目录管理契约。
 *
 * 调用方只能传递存储源名称与相对路径，不能取得驱动实例或敏感配置。
 */
interface StorageDirectoryManagerInterface
{
    /**
     * @return array<string, bool>
     */
    public function capabilities(string $storage): array;

    /**
     * @return list<array{path:string,name:string,type:string,size:int,last_modified:?int}>
     */
    public function list(string $storage, string $directory = '', bool $recursive = false): array;

    public function makeDirectory(string $storage, string $path): bool;

    public function move(string $storage, string $from, string $to): bool;

    public function delete(string $storage, string $path): bool;
}
