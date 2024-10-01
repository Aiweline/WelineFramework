<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\System\File\Data\File;
use Weline\Framework\System\File\Scanner;

class ModuleFileReader extends DataObject
{
    /**
     * @var Scanner
     */
    protected Scanner $scanner;

    protected string $path;

    /**
     * ModuleFileReader 初始函数...
     *
     * @param Scanner $scanner
     * @param string  $path
     */
    public function __construct(
        Scanner $scanner,
        string  $path = 'etc' . DS . 'module.xml'
    )
    {
        $this->scanner = $scanner;
        $this->path    = $path;
        parent::__construct();
    }


    /**
     * @DESC          # 读取文件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/6 22:55
     * 参数区：
     *
     * @param \Closure|null $callback
     *
     * @return array
     */
    public function getFileList(\Closure $callback = null): array
    {
        return $this->scanner->scanVendorModulesWithFiles($this->path, $callback);
    }

    /**
     * @DESC          # 读取文件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/6 22:55
     * 参数区：
     *
     * @param \Closure|null $callback
     *
     * @return array
     */
    public function getFileListWithCodeDir(\Closure $callback = null): array
    {
        return $this->scanner->scanCodeFiles($this->path, $callback);
    }

    public function getFilePath(): string
    {
        return $this->path;
    }

    /**
     * @param string $path
     *
     * @return ModuleFileReader
     */
    public function setPath(string $path)
    {
        $this->path = $path;
        return $this;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
