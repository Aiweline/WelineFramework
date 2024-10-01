<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Data\HtmlInterface;

trait TraitTemplate
{
    /**
     * @DESC          # 读取页头代码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 23:24
     * 参数区：
     * @return HtmlInterface|string
     */
    public function getHeader(): HtmlInterface|string
    {
        return $this->fetchClassObject('header');
    }

    /**
     * @DESC          # 读取页脚代码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 23:26
     * 参数区：
     * @return HtmlInterface|string
     */
    public function getFooter(): HtmlInterface|string
    {
        return $this->fetchClassObject('footer');
    }

    private function fetchClassObject(string $position): HtmlInterface|string
    {
        $is_backend = $this->request->isBackend();
        $cache_key  = ($is_backend ? 'backend' : 'frontend') . "_{$position}_object";
        if (PROD && $object = $this->viewCache->get($cache_key)) {
            return $object;
        }
        $this->eventsManager->dispatch("Framework_View::{$position}", ['is_backend' => $is_backend, 'class' => '']);
        $class = $this->eventsManager->getEventData("Framework_View::{$position}")->getData('class');
        if (empty($class) || !class_exists($class)) {
            return '';
        }
        $object = ObjectManager::getInstance($class);
        if (PROD) {
            $this->viewCache->set($cache_key, $object);
        }
        return $object;
    }

    /**--------------------------资源处理------------------------------*/

    public function processFileSource(string $fileName, string $file_dir): array
    {
        if (is_int(strpos($fileName, '::'))) {
            $pre_module_name = substr($fileName, 0, strpos($fileName, '::'));
            # 到模块配置中获取模块的模板文件路径
            $module_lists = Env::getInstance()->getModuleList();
            if (!isset($module_lists[$pre_module_name])) {
                throw new Exception(__('异常：你指定的模板文件所在的模块不存在！模块：%1，所使用的模板：%2', [$pre_module_name, $fileName]));
            }
            $fileName = str_replace($pre_module_name . '::', '', $fileName);
            # 替换掉当前模块的视图目录
            $module_base_path = $module_lists[$pre_module_name]['base_path'];
            $view_dir         = $module_base_path . Data\DataInterface::dir . DS;
            $template_dir     = $module_base_path . Data\DataInterface::dir . DS . Data\DataInterface::dir_type_TEMPLATE . DS;
            if (PROD) {
                $compile_dir = Env::path_framework_generated_complicate . DS . $module_lists[$pre_module_name]['path'] . Data\DataInterface::dir . DS;
            } else {
                $compile_dir = $module_base_path . Data\DataInterface::dir . DS . Data\DataInterface::dir_type_TEMPLATE_COMPILE . DS;
            }
            # 文件目录
            $file_dir = str_replace($pre_module_name . '::', '', $file_dir);
        } else {
            $view_dir     = $this->getRequest()->getModulePath() . 'view' . DS;
            $template_dir = $view_dir . Data\DataInterface::view_TEMPLATE_DIR . DS;
            if (PROD) {
                $module_path_arr = explode(DS, trim($this->getRequest()->getModulePath(), DS));
                $module          = array_pop($module_path_arr);
                $vendor          = array_pop($module_path_arr);
                $module_path     = $vendor . DS . $module . DS;
                $compile_dir     = Env::path_framework_generated_complicate . $module_path . Data\DataInterface::dir . DS;
            } else {
                $compile_dir = $view_dir . Data\DataInterface::view_TEMPLATE_COMPILE_DIR . DS;
            }
        }
        return [$fileName, $file_dir, $view_dir, $template_dir, $compile_dir];
    }

    public function processModuleSourceFilePath(string $type, string $source): array
    {
        $t_f     = $type . DS . $source;
        $t_f_arr = [];
        if ('/' !== DS) {
            $source = str_replace('/', DS, $source);
        }
        # 如果存在向别的模块调用模板的情况
        if (is_int(strpos($source, "::"))) {
            $t_f_arr = explode("::", $source);
            if (count($t_f_arr) > 1) {
                $t_f_arr[1] = trim($t_f_arr[1], DS);
                if (is_int(strpos($t_f_arr[1], $type))) {
                    $t_f_arr[2] = $t_f_arr[1];
                    $t_f_arr[1] = "::";
                } else {
                    $t_f_arr[2] = $t_f_arr[1];
                    $t_f_arr[1] = "::" . $type . DS;
                }
                $t_f = implode("", $t_f_arr);
            }
        };
        return [$t_f, array_shift($t_f_arr)];
    }

    public function fetchTagSourceFile(string $type, string $source)
    {
        $source    = trim($source);
        $cache_key = $type . '_' . $source.Cookie::getLangLocal();
        $data      = '';
        switch ($type) {
            case DataInterface::dir_type_TEMPLATE:
                if ($t_f = $this->viewCache->get($cache_key)) {
                    $data = $this->fetch($t_f);
                    break;
                }
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                $data = $this->fetch($t_f, $module_name);
                $this->viewCache->set($cache_key, $t_f);
                break;
            case DataInterface::dir_type_STATICS:
                if ($data = $this->viewCache->get($cache_key)) {
                    break;
                }
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                $base_url_path = rtrim($this->statics_dir, DataInterface::dir_type_STATICS);
                # 第三方模组
                if ($module_name) {
                    $modules = Env::getInstance()->getModuleList();
                    if (isset($modules[$module_name]) && $module = $modules[$module_name]) {
                        $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
                        $base_url_path        = $this->getModuleViewDir($module_view_dir_path, DataInterface::view_STATICS_DIR, $module_name);
                        $t_f                  = str_replace($module_name . '::', '', $t_f);
                    }
                }
                $data = rtrim($this->getUrlPath($base_url_path), DataInterface::dir_type_STATICS) . DS . $t_f;
                $this->viewCache->set($cache_key, $data);
                break;
            default:
        }
        if ($data) {
            $data = str_replace('\\', '/', $data);
            $data = str_replace('//', '/', $data);
        }
        return $data;
    }

    /**
     * @DESC          # 读取模板标签资源
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/13 20:45
     * 参数区：
     *
     * @param string $type
     * @param string $source
     * @param bool   $rand_version
     *
     * @return bool|string|void
     * @throws Exception
     */
    public function fetchTagSource(string $type, string $source, bool $rand_version_with_system = true)
    {
        $source    = trim($source);
        $source    = trim($source, DS);
        $cache_key = $type . '_' . $source.Cookie::getLangLocal();
        switch ($type) {
            case DataInterface::dir_type_STATICS:
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                # 第三方模组
                if ($module_name) {
                    $modules = Env::getInstance()->getModuleList();
                    if (isset($modules[$module_name]) && $module = $modules[$module_name]) {
                        $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
                        $base_url_path        = $this->getModuleViewDir($module_view_dir_path, DataInterface::view_STATICS_DIR, $module_name);
                        $t_f                  = str_replace($module_name . '::', '', $t_f);
                    } else {
                        throw new Exception(__('资源不存在：%1，模组：%2', [$source, $module_name]));
                    }
                } else {
                    $base_url_path = rtrim($this->statics_dir, DataInterface::dir_type_STATICS);
                }
                $data = rtrim($this->getUrlPath($base_url_path), DataInterface::dir_type_STATICS) . DS . $t_f;
                break;
            case DataInterface::dir_type_BASE:
            case DataInterface::dir_type_TEMPLATE:
            case DataInterface::dir_type_BLOCKS:
            default:
                $data = $this->viewCache->get($cache_key);
                if (PROD &&$data&& is_file($data)) {
                    return $data;
                }
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                $data = $this->getFetchFile($t_f, $module_name);
                break;
        }
        $data = str_replace('\\', '/', $data);
        $data = str_replace('//', '/', $data);
        # 是否静态文件添加
        if ($rand_version_with_system and ($type === 'statics' && Env::getInstance()->getConfig('static_file_rand_version'))) {
            $version = random_int(10000, 100000);
            $data    .= '?v=' . $version;
        }
        $this->viewCache->set($cache_key, $data);
        return $data;
    }

    /**
     * @DESC         |按照类型获取view目录
     *
     * 参数区：
     *
     * @param string $type
     *
     * @return string
     */
    private function getViewDir(string $type = ''): string
    {
        return $this->getModuleViewDir($this->view_dir, $type, $this->request->getModuleName());
    }

    private function getModuleViewDir(string $module_view_dir_path, string $type, string $module_name)
    {
        switch ($type) {
            case DataInterface::dir_type_TEMPLATE:
                $path = $module_view_dir_path . DataInterface::view_TEMPLATE_DIR;
                break;
            case DataInterface::dir_type_TEMPLATE_COMPILE:
                if (PROD) {
                    $path = str_replace(APP_CODE_PATH, Env::path_framework_generated_complicate . DS, $module_view_dir_path) . DS . DataInterface::view_TEMPLATE_DIR . DS;
                } else {
                    $path = $module_view_dir_path . DataInterface::view_TEMPLATE_COMPILE_DIR;
                }
                break;
            case DataInterface::dir_type_STATICS:
                $cache_key = 'getViewDir' . $module_view_dir_path . $type.(PROD ? 'prod' : 'dev');
                if ($cache_static_dir = $this->viewCache->get($cache_key)) {
                    return $cache_static_dir;
                }
                # 生产环境处理
                if (PROD) {
                    $module_view_dir_path_arr =  $path_arr = explode(DS, $module_view_dir_path);
                    $view_dir = array_pop($module_view_dir_path_arr);
                    $view_dir=   array_pop($module_view_dir_path_arr).DS.$view_dir;
                    array_pop($module_view_dir_path_arr);
                    array_pop($module_view_dir_path_arr);
                    $module_view_dir_path = implode(DS, $module_view_dir_path_arr).DS.str_replace('_', DS, $module_name).DS.$view_dir;
                }
                $path = $module_view_dir_path . DataInterface::view_STATICS_DIR . DS;
                # 生产环境处理
                if (PROD) {
                    $path = str_replace(APP_CODE_PATH, PUB . 'static' . DS . $this->theme['path'] . DS, $path);
                    $path = str_replace(VENDOR_PATH, PUB . 'static' . DS . $this->theme['path'] . DS, $path);
                }
                $this->viewCache->set($cache_key, $path);
                break;
            default:
                $path = $module_view_dir_path;

                break;
        }
        $path = $path . DS;
        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
    }

    /**
     * @DESC         |转化静态文件的URL路径
     *
     * 参数区：
     *
     * @param string $real_path
     *
     * @return string
     */
    private function getUrlPath(string $real_path): string
    {
        $url_path = '';
        if (DEV) {
            if (is_int(strpos($real_path, APP_CODE_PATH))) {
                $url_path = rtrim(str_replace('\\', '/', DS . str_replace(APP_CODE_PATH, '', $real_path)), '/');
            } elseif (is_int(strpos($real_path, VENDOR_PATH))) {
                $url_path = rtrim(str_replace('\\', '/', DS . str_replace(VENDOR_PATH, '', $real_path)), '/');
            }
        } else {
            # 检测模块位置
            $url_path = rtrim(str_replace('\\', '/', DS . str_replace(PUB, '', $real_path)), '/');
        }
        return $url_path;
    }

    /**
     * @DESC         | 取得对应的文件
     *
     * 参数区：
     *
     * @param string $filename
     *
     * @return array|mixed|string|null
     * @throws Core
     */
    protected function fetchFile(string $filename): mixed
    {
        $cache_key = $filename.Cookie::getLangLocal();
        $cache_filename = $this->viewCache->get($cache_key);
        if ($cache_filename&&is_file($cache_filename)) {
            return $cache_filename;
        }
        /*---------观察者模式 检测文件是否被继承-----------*/
        $fileData = new DataObject(['filename' => $filename, 'type' => 'compile']);
        $this->eventsManager->dispatch(
            'Framework_View::fetch_file',
            ['object' => $this, 'data' => $fileData]
        );
        $event_filename = $fileData->getData('filename');
        $this->viewCache->set($cache_key, $event_filename);
        return $event_filename;
    }
}
