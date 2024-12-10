<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\File;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scanner;

class Reader extends DataObject
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
        string $path = 'etc' . DS . 'module.xml',
        array  $data = []
    )
    {
        $this->path = $path;
        parent::__construct($data);
    }

    public function __init()
    {
        if (!isset($this->scanner)) {
            $this->scanner = ObjectManager::getInstance(Scanner::class);
        }
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
     * @DESC          # 设置相对模块的文件路径
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/24 17:23
     * 参数区：
     *
     * @param string $file_path
     *
     * @return string
     */
    public function setFilePath(string $file_path): string
    {
        $this->path = $file_path;
        return $this->path;
    }

    public function getFilePath(): string
    {
        return $this->path;
    }
}
