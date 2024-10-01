<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Setup\Data\DataInterface;

class Data
{
    private array $module_list = [];

    public function __construct()
    {
        $this->module_list = Env::getInstance()->getModuleList();
    }

    // FIXME 重构：缺少module对象，可通过module对象获取module的一切信息，就不用多次传模块名获取信息

    /**
     * @DESC         |获取升级类
     *
     * 参数区：
     *
     * @param string $module_name
     * @param string $type
     *
     * @return bool|string
     * @throws Exception
     */
    public function getSetupClass(string $module_name, string $type = DataInterface::type_INSTALL)
    {
        $removeFile = str_replace(DS, '\\', $this->getModulePath($module_name) . DataInterface::dir . DS . $type);
        if (is_file(APP_CODE_PATH . $removeFile)) {
            return APP_CODE_PATH . $removeFile;
        }
        if (is_file(BP . 'vendor/' . $removeFile)) {
            return BP . 'vendor/' . $removeFile;
        }

        return false;
    }

    // FIXME 重构：缺少module对象，可通过module对象获取module的一切信息，就不用多次传模块名获取信息

    /**
     * @DESC         |获取升级文件
     *
     * 参数区：
     *
     * @param string $module_name
     * @param string $type
     *
     * @return bool|string
     * @throws Exception
     */
    public function getSetupFile(string $module_name, string $type = DataInterface::type_INSTALL)
    {
        $setupFile = $this->getModuleClassFile($module_name, DataInterface::dir . DS . $type);
        if (is_file(APP_CODE_PATH . $setupFile)) {
            return APP_CODE_PATH . $setupFile;
        }
        if (is_file(BP . 'vendor/' . $setupFile)) {
            return BP . 'vendor/' . $setupFile;
        }

        return false;
    }

    // FIXME 重构：缺少module对象，可通过module对象获取module的一切信息，就不用多次传模块名获取信息
    public function getModuleClassFile(string $module_name, string $module_relate_class_file)
    {
        return str_replace(DS, '\\', $this->getModuleFile($module_name, $module_relate_class_file));
    }

    // FIXME 重构：缺少module对象，可通过module对象获取module的一切信息，就不用多次传模块名获取信息
    public function getModuleFile(string $module_name, string $module_relate_file)
    {
        return $this->getModulePath($module_name) . $module_relate_file;
    }

    // FIXME 重构：缺少module对象，可通过module对象获取module的一切信息，就不用多次传模块名获取信息
    public function getModulePath(string $module_name)
    {
        try {
            $module_path = $this->module_list[$module_name]['path'] . DS;
        } catch (\Exception $exception) {
            throw new Exception($module_name . __('模块不存在或者被删除！'));
        }

        return $module_path;
    }
}
