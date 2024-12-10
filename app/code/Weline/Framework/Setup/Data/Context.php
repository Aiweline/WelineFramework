<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Data;

use Weline\Framework\App\Env;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;

class Context
{
    private Printing $printer;

    private array $modules;

    protected string $module_name;

    protected string $module_version;

    protected string $module_description;

    /**
     * Context 初始函数...
     *
     * @param string $module_name
     * @param string $module_version
     * @param string $module_description
     */
    public function __construct(string $module_name, string $module_version, string $module_description = '')
    {
        $this->module_name        = $module_name;
        $this->module_version     = $module_version;
        $this->module_description = $module_description;
        $this->modules            = Env::getInstance()->getModuleList();
        $this->printer            = new Printing();
    }

    public function getModule(): Module
    {
        return new Module(Env::getInstance()->getModuleByName($this->module_name));
    }

    public function getModuleName()
    {
        return $this->module_name;
    }

    public function getModulePath(): string
    {
        return isset($this->modules[$this->module_name]) ? $this->modules[$this->module_name]['base_path'] : APP_CODE_PATH . str_replace('_', DS, $this->module_name);
    }

    /**
     * @DESC         |读取要升级的模块版本
     *
     * 参数区：
     *
     * @return bool|mixed
     */
    public function getVersion(): mixed
    {
        return isset($this->modules[$this->module_name]['version']) ? $this->modules[$this->module_name]['version'] : false;
    }

    /**
     * @DESC         |返回新的版本号
     *
     * 参数区：
     *
     * @return string
     */
    public function getNewVersion(): string
    {
        return $this->module_version;
    }

    /**
     * @DESC         |返回模块描述
     *
     * 参数区：
     *
     * @return string
     */
    public function getDescription(): string
    {
        return isset($this->modules[$this->module_name]['description']) ? $this->modules[$this->module_name]['description'] : '';
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return string
     */
    public function getNewDescription(): string
    {
        return $this->module_description;
    }

    /**
     * @DESC         |模块是否被禁用
     *
     * 参数区：
     *
     * @return bool
     */
    public function isDisable(): bool
    {
        $module_status = $this->modules[$this->module_name]['status'];

        return $module_status ? false : true;
    }

    /**
     * @DESC         |获取打印助手
     *
     * 参数区：
     *
     * @return Printing
     */
    public function getPrinter(): Printing
    {
        return $this->printer;
    }
}
