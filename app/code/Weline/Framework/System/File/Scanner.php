<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Weline\Framework\App\Env;
use Weline\Framework\Register\Register;
use Weline\Framework\Register\RegisterInterface;
use Weline\Framework\System\File\Data\File;

class Scanner extends \Weline\Framework\System\File\App\Scanner
{
    /**
     * @DESC         |扫描vendor供应商目录 模块
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return array|false
     */
    //    public function scanAppModules()
    //    {
    //        $vendors = $this->scanAppVendors();
    //        foreach ($vendors as $key => $vendor) {
    //            unset($vendors[$key]);
    //            if ($vendor_files = $this->scanVendorModules($vendor)) {
    //                $vendors[$vendor] = $vendor_files;
    //            }
    //        }
    //
    //        return $vendors;
    //    }

    /**
     * @DESC         |扫描应用供应商
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return array|false
     */
    public function scanAppVendors(): bool|array
    {
        $vendors = [];
        foreach (glob(APP_CODE_PATH . '*' . DS, GLOB_ONLYDIR) as $vendor) {
            $vendors[] = basename($vendor);
        }
        return $vendors;
    }

    /**
     * @DESC         |扫描供应商模块
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param $vendor
     *
     * @return array
     */
    public function scanVendorModules($vendor): array
    {
        $modules        = [];
        $app_modules    = glob(APP_CODE_PATH . $vendor . DS . '*' . DS . Register::register_file, GLOB_NOSORT);
        $modules['app'] = $this->parseModules($app_modules);
        # 转化为composer供应商名称
        $vendor_name         = Register::convertToComposerName($vendor);
        $core_modules        = glob(VENDOR_PATH . $vendor_name . DS . '*' . DS . Register::register_file, GLOB_NOSORT);
        $modules['composer'] = $this->parseModules($core_modules);
        return $modules;
    }

    /**
     * @DESC          # 扫描带有文件的模块
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/6 22:08
     * 参数区：
     *
     * @param string        $file_or_dir
     * @param \Closure|null $callback
     *
     * @return array
     */
    public function scanVendorModulesWithFiles(string $file_or_dir = '', \Closure $callback = null): array
    {
        # 使用现有激活的模块进行文件扫描
        $modules       = Env::getInstance()->getActiveModules();
        $modules_files = [];
        foreach ($modules as $key => $module) {
            $position  = $module['position'];
            $name      = $module['name'];
            $base_path = $module['base_path'];
            if('Weline_Framework' == $name) {
                $framework_module_paths = glob($base_path.'*', GLOB_ONLYDIR);
                foreach ($framework_module_paths as $framework_module_path) {
                    $app_need_file_or_dir = $framework_module_path.DS . $file_or_dir;
                    if (is_file($app_need_file_or_dir)) {
                        $modules_files[$name] = $app_need_file_or_dir;
                        continue;
                    }
                }
            }
            // app下的代码优先度更高
            if ($position === 'app') {
                unset($modules[$key]);
                $app_need_file_or_dir = $base_path . $file_or_dir;
                if (is_file($app_need_file_or_dir)) {
                    $modules_files[$name] = $app_need_file_or_dir;
                    continue;
                }
                //                $this->globFile($base_path.'')
                //                $file_data = $this->scanDirTree($app_need_file_or_dir);
                //                if (!empty($file_data)) {
                //                    $modules_files[$name] = $file_data;
                //                }
            }
            if ($position === 'composer') {
                # app模组代码没有才能添加
                if (!isset($modules_files[$name])) {
                    $app_need_file_or_dir = $base_path . $file_or_dir;
                    if (is_file($app_need_file_or_dir)) {
                        $modules_files[$name] = $app_need_file_or_dir;
                        continue;
                    }
                    /*$file_data = $this->scanDirTree($app_need_file_or_dir);
                    if (!empty($file_data)) {
                        $modules_files[$name] = $file_data;
                    }*/
                }
            }
            if ($position === 'framework') {
                # app模组代码没有才能添加
                $app_need_file_or_dir = $base_path . $file_or_dir;
                if (is_file($app_need_file_or_dir)) {
                    $modules_files[$name] = $app_need_file_or_dir;
                    continue;
                }

                /*$file_data = $this->scanDirTree($app_need_file_or_dir);
                if (!empty($file_data)) {
                    $modules_files[$name] = $file_data;
                }*/
            }
            if ($position === 'system') {
                # 系统模组代码没有才能添加
                $system_need_file_or_dir = $base_path . $file_or_dir;
                if (is_file($system_need_file_or_dir)) {
                    $modules_files[$name] = $system_need_file_or_dir;
                    continue;
                }
                /*$file_data = $this->scanDirTree($system_need_file_or_dir);
                if (!empty($file_data)) {
                    $modules_files[$name] = $file_data;
                }*/
            }
        }

        # 带有回调处理的方法
        if ($callback) {
            $modules_files = $callback($modules_files);
        }
        return $modules_files;
    }

    /**
     * @DESC          # 扫描规则目录的代码文件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/12 22:55
     * 参数区：
     *
     * @param string        $file_or_dir 示例：*\Framework\*\etc\event.xml
     * @param \Closure|null $callback
     *
     * @return array
     */
    public function scanCodeFiles(string $file_or_dir = '', \Closure $callback = null): array
    {
        $file_or_dir             = trim($file_or_dir, DS);
        $file_or_dir_arr         = explode(DS, $file_or_dir);
        $file_or_dir_path_length = count($file_or_dir_arr);# 目录深度
        $file_or_dir_last        = array_pop($file_or_dir_arr);


        $this->__init();
        $app_modules = $this->scanDirTree(APP_CODE_PATH);
        $this->__init();
        $core_modules    = $this->scanDirTree(VENDOR_PATH);
        $modules         = array_merge($core_modules, $app_modules);
        $vendors_modules = [];
        //        p($modules);
        /**@var File $file */
        foreach ($modules as $dir => $files) {
            foreach ($files as $file) {
                $file_arr = explode(DS, $file->getRelate());
                if ($file_or_dir_path_length === count($file_arr) && $file_or_dir_last === $file->getBasename()) {
                    $vendor                                                               = array_shift($file_arr);
                    $vendors_modules[$vendor][implode('_', array_slice($file_arr, 0, 2))] = $file->getOrigin();
                }
            }
        }

        # 带有回调处理的方法
        if ($callback) {
            $vendors_modules = $callback($vendors_modules);
        }
        return $vendors_modules;
    }

    /**
     * @DESC          # 根据匹配规则目录扫描代码文件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/12 22:52
     * 参数区：
     *
     * @param array         $pattern_dirs ['*\Framework\*\etc\event.xml','*\Framework\*\etc\module.xml']
     * @param \Closure|null $callback
     *
     * @return array
     */
    public function scanFilesWithPatternDirs(array $pattern_dirs = [], \Closure $callback = null): array
    {
        $vendors_modules = [];
        foreach ($pattern_dirs as $pattern_dir) {
            $file_or_dir             = trim($pattern_dir, DS);
            $file_or_dir_arr         = explode(DS, $file_or_dir);
            $file_or_dir_path_length = count($file_or_dir_arr);# 目录深度
            $file_or_dir_last        = array_pop($file_or_dir_arr);

            $this->__init();
            $app_modules = $this->scanDirTree(APP_CODE_PATH);
            $this->__init();
            $core_modules = $this->scanDirTree(VENDOR_PATH);
            $modules      = array_merge($core_modules, $app_modules);
            /**@var File $file */
            foreach ($modules as $dir => $files) {
                foreach ($files as $file) {
                    $file_arr = explode(DS, $file->getRelate());
                    if ($file_or_dir_path_length === count($file_arr) && $file_or_dir_last === $file->getBasename()) {
                        $vendor                                                               = array_shift($file_arr);
                        $vendors_modules[$vendor][implode('_', array_slice($file_arr, 0, 2))] = $file->getOrigin();
                    }
                }
            }
        }

        # 带有回调处理的方法
        if ($callback) {
            $vendors_modules = $callback($vendors_modules);
        }
        return $vendors_modules;
    }
}
