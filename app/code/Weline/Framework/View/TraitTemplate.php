<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\State;
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
        $cache_key = ($is_backend ? 'backend' : 'frontend') . "_{$position}_object";
        if (PROD && $object = $this->viewCache->get($cache_key)) {
            return $object;
        }
        $eventData = ['position' => $position, 'is_backend' => $is_backend, 'class' => ''];
        $this->eventsManager->dispatch("Framework_View::{$position}", $eventData);
        $eventDataObj = $this->eventsManager->getEventData("Framework_View::{$position}");
        if (!$eventDataObj) {
            return '';
        }
        $class = $eventDataObj->getData('class');
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

    /**
     * 根据模板路径（含 Module:: 或相对路径）解析出模板文件的真实绝对路径。
     * 用于部件扫描等场景，不依赖当前请求的 module，仅根据路径中的模块前缀解析。
     *
     * @param string $templatePath 如 Weline_Theme::theme/frontend/widgets/header/logo/default.phtml
     * @return string 绝对路径，不存在时也返回拼接路径；无法解析时返回空字符串
     */
    public function getTemplateRealPath(string $templatePath): string
    {
        $templatePath = trim($templatePath);
        if ($templatePath === '') {
            return '';
        }
        $fileDir = '';
        try {
            list($fileName, , $view_dir, , ) = $this->processFileSource($templatePath, $fileDir);
        } catch (\Throwable $e) {
            return '';
        }
        $fileName = str_replace('/', DS, $fileName);
        $ext = substr(strrchr($fileName, '.'), 1);
        $tplFile = $view_dir . $fileName;
        if ($ext === '' || $ext === false) {
            $tplFile .= $this->getFileExt();
        }
        return str_replace(['/', '\\'], DS, $tplFile);
    }

    public function processFileSource(string $fileName, string $file_dir): array
    {
        if (is_int(strpos($fileName, '::'))) {
            $pre_module_name = substr($fileName, 0, strpos($fileName, '::'));
            # 到模块配置中获取模块的模板文件路径
            $module_lists = Env::getInstance()->getModuleList();
            if (!isset($module_lists[$pre_module_name])) {
                throw new Exception(__('异常：你指定的模板文件所在的模块不存在！模块：%{1}，所使用的模板：%{2}', [$pre_module_name, $fileName]));
            }
            $fileName = str_replace($pre_module_name . '::', '', $fileName);
            # 替换掉当前模块的视图目录
            $module_base_path = $module_lists[$pre_module_name]['base_path'];
            $view_dir = $module_base_path . Data\DataInterface::dir . DS;
            $template_dir = $module_base_path . Data\DataInterface::dir . DS . Data\DataInterface::dir_type_TEMPLATE . DS;
            if (PROD) {
                $compile_dir = Env::path_framework_generated_complicate . DS . $module_lists[$pre_module_name]['path'] . Data\DataInterface::dir . DS;
            } else {
                $compile_dir = $module_base_path . Data\DataInterface::dir . DS . Data\DataInterface::dir_type_TEMPLATE_COMPILE . DS;
            }
            # 文件目录
            $file_dir = str_replace($pre_module_name . '::', '', $file_dir);
        } else {
            $view_dir = $this->getRequest()->getModulePath() . 'view' . DS;
            $template_dir = $view_dir . Data\DataInterface::view_TEMPLATE_DIR . DS;
            if (PROD) {
                $module_path_arr = explode(DS, trim($this->getRequest()->getModulePath(), DS));
                $module = array_pop($module_path_arr);
                $vendor = array_pop($module_path_arr);
                $module_path = $vendor . DS . $module . DS;
                $compile_dir = Env::path_framework_generated_complicate . $module_path . Data\DataInterface::dir . DS;
            } else {
                $compile_dir = $view_dir . Data\DataInterface::view_TEMPLATE_COMPILE_DIR . DS;
            }
        }
        return [$fileName, $file_dir, $view_dir, $template_dir, $compile_dir];
    }

    public function processModuleSourceFilePath(string $type, string $source): array
    {
        $t_f = $type . DS . $source;
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
        if (empty($t_f_arr)) {
            $mod = $this->getRequest()->getModuleName();
            return [$t_f, $mod];
        }
        $mod = array_shift($t_f_arr);
        return [$t_f, $mod];
    }

    public function fetchTagSourceFile(string $type, string $source)
    {
        $source = trim($source);
        $cache_key = $type . '_' . $source . Cookie::getLangLocal();
        $data = '';
        switch ($type) {
            case DataInterface::dir_type_TEMPLATE:
            case DataInterface::dir_type_THEME:
                if ($t_f = $this->viewCache->get($cache_key)) {
                    $data = $this->fetch($t_f);
                    break;
                }
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                $data = $this->fetch($t_f, $module_name);
                $this->viewCache->set($cache_key, $t_f);
                break;
            case DataInterface::dir_type_STATICS:
                // 静态 URL 不读写缓存，避免跨请求/环境读到错误 URL（key 若未含 BP/模块足够信息会串用）
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                # 第三方模组或当前模组
                if ($module_name) {
                    $modules = Env::getInstance()->getModuleList();
                    if (isset($modules[$module_name]) && $module = $modules[$module_name]) {
                        $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
                        $base_url_path = $this->getModuleViewDir($module_view_dir_path, DataInterface::view_STATICS_DIR, $module_name);
                        $t_f = str_replace($module_name . '::', '', $t_f);
                    }
                } else {
                    // 没有指定模块时，使用当前请求模块的静态资源目录
                    $current_module_name = $this->getRequest()->getModuleName();
                    $modules = Env::getInstance()->getModuleList();
                    if (isset($modules[$current_module_name]) && $module = $modules[$current_module_name]) {
                        $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
                        $base_url_path = $this->getModuleViewDir($module_view_dir_path, DataInterface::view_STATICS_DIR, $current_module_name);
                    } else {
                        $base_url_path = rtrim($this->statics_dir, DataInterface::dir_type_STATICS);
                    }
                }
                $t_f = preg_replace('#^statics[/\\\\]+#', '', str_replace('\\', '/', $t_f));
                $t_f = ltrim($t_f, '/');
                $url_base = $this->getUrlPath($base_url_path);
                if ($url_base === '' && $base_url_path !== '') {
                    $name_for_base = $module_name ?? $this->getRequest()->getModuleName();
                    if ($name_for_base !== '') {
                        $url_base = '/' . str_replace('_', '/', $name_for_base) . '/view/statics/';
                        if (defined('PROD') && PROD) {
                            $themePath = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
                            $themePath = str_replace('\\', '/', $themePath);
                            $url_base = '/static/' . $themePath . '/' . ltrim($url_base, '/');
                        }
                        $url_base = rtrim($url_base, '/') . '/';
                    }
                }
                $data = $url_base . $t_f;
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
     * @param bool $rand_version
     *
     * @return bool|string|void
     * @throws Exception
     */
    public function fetchTagSource(string $type, string $source, bool $rand_version_with_system = true)
    {
        $source = trim($source);
        $source = trim($source, DS);
        $cache_key = $type . '_' . $source . Cookie::getLangLocal();
        switch ($type) {
            case DataInterface::dir_type_STATICS:
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                # 第三方模组或当前模组
                if ($module_name) {
                    $modules = Env::getInstance()->getModuleList();
                    if (isset($modules[$module_name]) && $module = $modules[$module_name]) {
                        $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
                        $base_url_path = $this->getModuleViewDir($module_view_dir_path, DataInterface::view_STATICS_DIR, $module_name);
                        $t_f = str_replace($module_name . '::', '', $t_f);
                    } else {
                        throw new Exception(__('资源不存在：%{1}，模组：%{2}', [$source, $module_name]));
                    }
                } else {
                    // 没有指定模块时，使用当前请求模块的静态资源目录
                    $current_module_name = $this->getRequest()->getModuleName();
                    $modules = Env::getInstance()->getModuleList();
                    if (isset($modules[$current_module_name]) && $module = $modules[$current_module_name]) {
                        $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
                        $base_url_path = $this->getModuleViewDir($module_view_dir_path, DataInterface::view_STATICS_DIR, $current_module_name);
                    } else {
                        $base_url_path = rtrim($this->statics_dir, DataInterface::dir_type_STATICS);
                    }
                }
                // base_url_path 已含 view/statics/，去掉 t_f 前多余的 statics/ 避免重复
                $t_f = preg_replace('#^statics[/\\\\]+#', '', str_replace('\\', '/', $t_f));
                $t_f = ltrim($t_f, '/');
                $url_base = $this->getUrlPath($base_url_path);
                // 当 getUrlPath 返回空时兜底：$base_url_path 有值但路径未匹配 APP_CODE_PATH/VENDOR_PATH(PROD 下 PUB) 时会返回空，见 getUrlPath() 注释
                if ($url_base === '' && $base_url_path !== '') {
                    $name_for_base = $module_name ?? $this->getRequest()->getModuleName();
                    if ($name_for_base !== '') {
                        $url_base = '/' . str_replace('_', '/', $name_for_base) . '/view/statics/';
                        if (defined('PROD') && PROD) {
                            $themePath = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
                            $themePath = str_replace('\\', '/', $themePath);
                            $url_base = '/static/' . $themePath . '/' . ltrim($url_base, '/');
                        }
                        $url_base = rtrim($url_base, '/') . '/';
                    }
                }
                $data = $url_base . $t_f;
                break;
            case DataInterface::dir_type_THEME:
                // 检查是否是静态资源文件（js/css等），如果是则返回URL，否则返回文件路径
                $isStaticResource = preg_match('/\.(js|css|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot|otf|pdf|zip)$/i', $source);
                if ($isStaticResource) {
                    list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                    $module_name = $module_name !== '' ? $module_name : $this->getRequest()->getModuleName();
                    if ($module_name === '') {
                        throw new Exception(__('资源不存在：%{1}', [$source]));
                    }

                    $t_f = str_replace($module_name . '::', '', $t_f);
                    $t_f = preg_replace('#^theme[\\\\/]#', '', str_replace('\\', '/', $t_f));
                    $t_f = ltrim((string)$t_f, '/');
                    [$themeArea, $themeRelativePath] = array_pad(explode('/', $t_f, 2), 2, '');
                    $themeArea = strtolower(trim((string)$themeArea)) === 'backend' ? 'backend' : 'frontend';

                    $data = $this->resolveThemeAssetUrlByEvent($module_name, $themeArea, $themeRelativePath);
                    if ($data === '') {
                        // 回退：无 Theme 模块监听时，使用模块默认静态目录 URL。
                        $data = $this->fetchTagSourceFile(DataInterface::dir_type_STATICS, $module_name . '::' . $themeRelativePath);
                    }
                } else {
                    // 模板文件：返回文件路径
                    $data = $this->viewCache->get($cache_key);
                    if (PROD && $data && is_file($data)) {
                        return $data;
                    }
                    list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                    $data = $this->getFetchFile($t_f, $module_name);
                }
                break;
            case DataInterface::dir_type_BASE:
            case DataInterface::dir_type_TEMPLATE:
            case DataInterface::dir_type_BLOCKS:
            default:
                $data = $this->viewCache->get($cache_key);
                if (PROD && $data && is_file($data)) {
                    return $data;
                }
                list($t_f, $module_name) = $this->processModuleSourceFilePath($type, $source);
                $data = $this->getFetchFile($t_f, $module_name);
                break;
        }
        $data = str_replace('\\', '/', $data);
        $data = str_replace('//', '/', $data);
        
        # 静态资源版本号处理
        if ($rand_version_with_system && ($type === 'statics' || $type === 'theme')) {
            // 先移除已存在的版本号参数（防止重复添加）
            $data = preg_replace('/[?&]v=[^&]*/', '', $data);
            // 清理可能残留的 ? 或 &
            $data = rtrim($data, '?&');
            
            $version = $this->getStaticResourceVersion();
            if ($version) {
                $data .= (str_contains($data, '?') ? '&' : '?') . 'v=' . $version;
            } elseif (Env::dev('static_rand_version')) {
                // 回退到随机版本号
                $version = random_int(10000, 100000);
                $data .= (str_contains($data, '?') ? '&' : '?') . 'v=' . $version;
            }
        }
        
        // 静态资源不缓存带版本号的 URL（版本号需要动态计算）
        // 只缓存基础 URL 可能导致问题，这里选择不缓存静态资源 URL
        if ($type !== 'statics' && $type !== 'theme') {
            $this->viewCache->set($cache_key, $data);
        }
        return $data;
    }
    
    /**
     * 获取静态资源版本号
     * 
     * 优先级：
     * 1. 预览模式：使用预览 Token
     * 2. 系统配置的静态版本号（发布时更新）
     * 3. 返回 null（使用默认随机版本号）
     * 
     * @return string|null 版本号
     */
    private function getStaticResourceVersion(): ?string
    {
        // 1. 通过事件询问外部模块（如 Theme）是否处于预览模式，以及对应 token。
        $previewToken = $this->resolvePreviewTokenByEvent();
        if ($previewToken !== '') {
            return 'preview_' . substr($previewToken, 0, 8);
        }
        
        // 2. 读取系统配置的静态版本号
        $staticVersion = Env::getInstance()->getConfig('theme.static_version');
        if ($staticVersion) {
            return $staticVersion;
        }
        
        return null;
    }

    private function resolveThemeAssetUrlByEvent(string $moduleName, string $area, string $relativePath): string
    {
        $eventData = new DataObject([
            'module_name' => $moduleName,
            'area' => $area,
            'relative_path' => $relativePath,
            'url' => '',
        ]);
        $this->eventsManager->dispatch('Weline_Framework_View::resolve_theme_asset_url', $eventData);
        $url = trim((string)$eventData->getData('url'));
        return $url;
    }

    private function resolvePreviewTokenByEvent(): string
    {
        $eventData = new DataObject([
            'is_preview' => false,
            'preview_token' => '',
        ]);
        $this->eventsManager->dispatch('Weline_Framework_View::resolve_preview_token', $eventData);
        if (!$eventData->getData('is_preview')) {
            return '';
        }
        return trim((string)$eventData->getData('preview_token'));
    }

    /**
     * 获取模板(view/templates)目录下资源的可访问URL（生产环境兼容）。
     * - 支持 module::path 写法；path 相对于 templates/ 目录（如 style/jion-landing/asset/img/banner.png）
     * - DEV：直接将真实路径转为URL（/app/code/...）。
     * - PROD：发布到模块静态目录下（/pub/static/...），并返回对应URL。
     */
    public function fetchTemplateStatic(string $source, bool $rand_version_with_system = true): string
    {
        $source = trim($source);
        if ('/' !== DS) {
            $source = str_replace('/', DS, $source);
        }
        
        // 手动解析 module::path，避免 processModuleSourceFilePath 自动添加 templates/ 前缀
        $module_name = null;
        $rel_path = $source;
        if (strpos($source, '::') !== false) {
            $parts = explode('::', $source, 2);
            $module_name = trim($parts[0]);
            $rel_path = trim($parts[1], DS);
        }
        
        // 移除 rel_path 中可能存在的 templates/ 前缀（避免重复）
        $rel_path = ltrim($rel_path, DS);
        if (strpos($rel_path, 'templates' . DS) === 0) {
            $rel_path = substr($rel_path, strlen('templates' . DS));
        }
        
        // 确保路径以 templates/ 开头
        $rel_path = 'templates' . DS . $rel_path;
        
        // 获取模块路径
        $modules = Env::getInstance()->getModuleList();
        if ($module_name && isset($modules[$module_name]) && $module = $modules[$module_name]) {
            $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
        } else {
            $module_name = $this->getRequest()->getModuleName();
            $module_view_dir_path = $this->getRequest()->getModulePath() . 'view' . DS;
        }
        
        // 构建真实文件路径
        $real_path = rtrim($module_view_dir_path, DS) . DS . $rel_path;

        if (!PROD) {
            // 开发环境：直接转URL
            $dir = dirname($real_path);
            $url_base = $this->getUrlPath($dir);
            $url = rtrim($url_base, '/') . '/' . basename($real_path);
            return str_replace('//', '/', str_replace('\\', '/', $url));
        }

        // 生产环境：发布到静态目录
        $statics_base_dir = $this->getModuleViewDir($module_view_dir_path, DataInterface::view_STATICS_DIR, $module_name);
        $publish_target = rtrim($statics_base_dir, DS) . DS . $rel_path;
        $publish_dir = dirname($publish_target);
        if (!is_dir($publish_dir)) {
            mkdir($publish_dir, 0770, true);
        }
        if (is_file($real_path)) {
            if (!is_file($publish_target) || filemtime($real_path) > @filemtime($publish_target)) {
                @copy($real_path, $publish_target);
            }
        }
        $url = rtrim($this->getUrlPath($publish_dir), '/') . '/' . basename($publish_target);
        if ($rand_version_with_system && Env::dev('static_rand_version')) {
            $url .= '?v=' . random_int(10000, 100000);
        }
        return str_replace('//', '/', str_replace('\\', '/', $url));
    }

    /**
     * @DESC         |按照类型获取view目录
     *
     * 参数区：
     *
     * @param string $type
     *
     * @return bool
     */
    public function templateStaticExists(string $source): bool
    {
        $source = trim($source);
        if ('/' !== DS) {
            $source = str_replace('/', DS, $source);
        }

        $module_name = null;
        $rel_path = $source;
        if (strpos($source, '::') !== false) {
            $parts = explode('::', $source, 2);
            $module_name = trim($parts[0]);
            $rel_path = trim($parts[1], DS);
        }

        $rel_path = ltrim($rel_path, DS);
        if (strpos($rel_path, 'templates' . DS) === 0) {
            $rel_path = substr($rel_path, strlen('templates' . DS));
        }
        $rel_path = 'templates' . DS . $rel_path;

        $modules = Env::getInstance()->getModuleList();
        if ($module_name && isset($modules[$module_name]) && $module = $modules[$module_name]) {
            $module_view_dir_path = $module['base_path'] . DataInterface::dir . DS;
        } else {
            $module_view_dir_path = $this->getRequest()->getModulePath() . 'view' . DS;
        }

        return is_file(rtrim($module_view_dir_path, DS) . DS . $rel_path);
    }

    /**
     * @param string $type
     * @return string
     */
    private function getViewDir(string $type = ''): string
    {
        return $this->getModuleViewDir($this->view_dir, $type, $this->request->getModuleName());
    }

    private function getModuleViewDir(string $module_view_dir_path, string $type, string $module_name)
    {
        if (empty($module_view_dir_path)) {
            return '';
        }
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
                // key 必须含模块名与 BP，避免跨模块/跨环境串用（如 CLI 与 Web 路径不一致导致缓存错）
                $cache_key = 'getViewDir' . (defined('BP') ? BP : '') . $module_name . $module_view_dir_path . $type . (PROD ? 'prod' : 'dev');
                if ($cache_static_dir = $this->viewCache->get($cache_key)) {
                    return $cache_static_dir;
                }
                # 生产环境处理
                if (PROD) {
                    $module_view_dir_path_arr = $path_arr = explode(DS, $module_view_dir_path);
                    $view_dir = array_pop($module_view_dir_path_arr);
                    $view_dir = array_pop($module_view_dir_path_arr) . DS . $view_dir;
                    array_pop($module_view_dir_path_arr);
                    array_pop($module_view_dir_path_arr);
                    $module_view_dir_path = implode(DS, $module_view_dir_path_arr) . DS . str_replace('_', DS, $module_name) . DS . $view_dir;
                }
                $path = $module_view_dir_path . DataInterface::view_STATICS_DIR . DS;
                # 生产环境处理
                if (PROD) {
                    $path = str_replace(APP_CODE_PATH, PUB . 'static' . DS . $this->theme['path'] . DS, $path);
                    $path = str_replace(VENDOR_PATH, PUB . 'static' . DS . $this->theme['path'] . DS, $path);
                }
                $this->viewCache->set($cache_key, $path);
                break;
            case DataInterface::dir_type_THEME:
                $cache_key = 'getViewDir' . (defined('BP') ? BP : '') . $module_name . $module_view_dir_path . $type . (PROD ? 'prod' : 'dev');
                if ($cache_static_dir = $this->viewCache->get($cache_key)) {
                    return $cache_static_dir;
                }
                $path = $module_view_dir_path . 'theme' . DS;
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
        if (!empty($path) and !is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
    }

    /**
     * @DESC         |转化静态文件的URL路径（将磁盘路径转为可访问的 URL 路径）
     *
     * 参数区：
     *
     * @param string $real_path 磁盘上的真实路径（可为反斜杠或正斜杠）
     *
     * @return string 以 / 开头的 URL 路径，如 /Aiweline/PlayingInChina/view/statics/...；以下情况返回空字符串：
     *                 - 开发：real_path 不以 APP_CODE_PATH 或 VENDOR_PATH 开头（如 CLI 下常量未正确初始化、路径格式不一致）
     *                 - 生产：real_path 不以 PUB 开头
     */
    private function getUrlPath(string $real_path): string
    {
        $normalized = str_replace('\\', '/', $real_path);
        $url_path = '';
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $startsWithPath = static function (string $path, string $prefix) use ($isWindows): bool {
            if ($prefix === '') {
                return false;
            }
            return $isWindows
                ? str_starts_with(strtolower($path), strtolower($prefix))
                : str_starts_with($path, $prefix);
        };
        $stripPrefix = static function (string $path, string $prefix) use ($isWindows): string {
            if ($prefix === '') {
                return $path;
            }
            if ($isWindows) {
                return (string)preg_replace('/^' . preg_quote($prefix, '/') . '/i', '', $path, 1);
            }
            return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
        };
        if (DEV) {
            $appCode = str_replace('\\', '/', APP_CODE_PATH);
            $vendorPath = defined('VENDOR_PATH') ? str_replace('\\', '/', VENDOR_PATH) : '';
            if ($startsWithPath($normalized, $appCode)) {
                $url_path = '/' . ltrim($stripPrefix($normalized, $appCode), '/');
            } elseif ($startsWithPath($normalized, $vendorPath)) {
                $url_path = '/' . ltrim($stripPrefix($normalized, $vendorPath), '/');
            }
        } else {
            $pubPath = defined('PUB') ? str_replace('\\', '/', PUB) : '';
            if ($startsWithPath($normalized, $pubPath)) {
                $url_path = '/' . ltrim($stripPrefix($normalized, $pubPath), '/');
            }
        }
        return $url_path === '' ? '' : rtrim($url_path, '/') . '/';
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
        $cache_key = $filename . State::getLangLocal() . '|' . $this->resolveThemeCacheKeyForFetchFile($filename);
        $skipCache = isset($this->request) && $this->request && $this->request->getData('skip_view_file_cache');
        if (!$skipCache) {
            $cache_filename = $this->viewCache->get($cache_key);
            if ($cache_filename && is_file($cache_filename)) {
                return $cache_filename;
            }
        }
        /*---------观察者模式 检测文件是否被继承-----------*/
        $fileData = new DataObject(['filename' => $filename, 'type' => 'compile', 'object' => $this]);
        $this->eventsManager->dispatch(
            'Weline_Framework_View::fetch_file',
            $fileData
        );
        $event_filename = $fileData->getData('filename');
        $this->viewCache->set($cache_key, $event_filename);
        return $event_filename;
    }

    private function resolveThemeCacheKeyForFetchFile(string $filename): string
    {
        $path = str_replace('\\', '/', $filename);
        $area = str_contains($path, '/backend/') ? 'backend' : 'frontend';
        $baseKey = 'area:' . $area;

        // 交由外部模块（如 Theme）通过事件回填主题/预览相关后缀，Framework 自身不感知具体实现。
        $eventData = new DataObject([
            'filename' => $filename,
            'area' => $area,
            'suffix' => '',
        ]);
        $this->eventsManager->dispatch('Weline_Framework_View::resolve_theme_cache_suffix', $eventData);
        $suffix = trim((string)$eventData->getData('suffix'));

        return $suffix === '' ? $baseKey : ($baseKey . '|' . $suffix);
    }
}
