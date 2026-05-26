<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Config\Reader\XmlReader;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class MenuXmlReader extends XmlReader
{
    private const RELATIVE_PATH = 'etc' . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'menu.xml';

    public function __construct(
        Scanner $scanner,
        Parser  $parser,
                $path = 'etc/backend/menu.xml'
    )
    {
        parent::__construct($scanner, $parser, $path);
    }

    /**
     * 获取 menu.xml 文件列表：仅激活模块，用 base_path + etc/backend/menu.xml 直接定位，不扫描目录。
     *
     * @param \Closure|null $callback 保留签名兼容，此处未使用
     * @return array<string, string> 模块名 => 文件绝对路径
     */
    public function getFileList(null|\Closure $callback = null): array
    {
        $result = [];
        $modules = Env::getInstance()->getActiveModules();
        $order = ['app' => 0, 'framework' => 1, 'system' => 2, 'composer' => 3];
        uasort($modules, static fn($a, $b) => ($order[$a['position'] ?? 'composer'] ?? 4) <=> ($order[$b['position'] ?? 'composer'] ?? 4));
        foreach ($modules as $module) {
            $name = $module['name'] ?? '';
            $basePath = rtrim($module['base_path'] ?? '', '/\\');
            if ($name === '' || $basePath === '') {
                continue;
            }
            $filePath = $basePath . DIRECTORY_SEPARATOR . self::RELATIVE_PATH;
            if (is_file($filePath)) {
                $result[$name] = $filePath;
            }
        }
        return $callback ? $callback($result) : $result;
    }

    /**
     * 读取菜单配置：仅激活模块，base_path 直接定位，逐文件解析合并，降低内存占用。
     */
    public function read(): array
    {
        $module_menus = [];
        $fileList = $this->getFileList();
        foreach ($fileList as $module => $filePath) {
            $config = $this->parser->parseFile($filePath);
            $module_and_file = $module . '::' . $filePath;
            $one = $this->processOneMenuConfig($config, $filePath, $module_and_file);
            if ($one !== null) {
                $module_menus[$module] = $one;
            }
            unset($config, $one);
        }
        foreach ($module_menus as &$module_menu) {
            $data = $module_menu['data'];
            if ($data) {
                $orders = array_column($data, 'order');
                array_multisort($orders, SORT_ASC, $data);
                $module_menu['data'] = $data;
            }
        }
        return $module_menus;
    }

    /**
     * 处理单个 menu.xml 解析结果，返回 ['file' => ..., 'data' => [...]] 或 null 表示跳过。
     */
    private function processOneMenuConfig(array $config, string $filePath, string $module_and_file): ?array
    {
        if (!isset($config['menus']) || !is_array($config['menus'])) {
            w_log_warning(__('跳过格式不正确的菜单配置文件：%{1}', [$module_and_file]));
            return null;
        }
        if (
            !isset($config['menus']['_attribute']['noNamespaceSchemaLocation'])
            && 'urn:weline:module:Weline_Backend::etc/xsd/menu.xsd' !== ($config['menus']['_attribute']['noNamespaceSchemaLocation'] ?? '')
        ) {
            $this->checkElementAttribute(
                $config['menus'],
                'noNamespaceSchemaLocation',
                __('菜单元素menus必须设置：noNamespaceSchemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd"，文件：%{1}', $module_and_file)
            );
        }
        $menusContent = $config['menus']['_value'] ?? $config['menus'];
        $menusContent = is_array($menusContent) ? $menusContent : [];
        $data = [];
        foreach ($menusContent as $key => $menuGroup) {
            if ($key === '_attribute' || $key === '_value') {
                continue;
            }
            if ($key === 'menu') {
                $data = array_merge($data, $this->parseMenuElement($menuGroup, '', $module_and_file));
            }
        }
        return ['file' => $filePath, 'data' => $data];
    }
    
    /**
     * 递归解析 <menu> 元素
     *
     * @param array|mixed $menuData 菜单数据（可能是单个菜单或菜单数组）
     * @param string $parentSource 父菜单的 source（用于自动继承）
     * @param string $moduleAndFile 模块和文件标识（用于错误提示）
     * @return array 扁平化的菜单数组
     */
    private function parseMenuElement(mixed $menuData, string $parentSource, string $moduleAndFile): array
    {
        $items = [];
        
        if (!is_array($menuData)) {
            return $items;
        }
        
        $menuList = is_int(array_key_first($menuData)) ? $menuData : [$menuData];
        
        foreach ($menuList as $menu) {
            if (!isset($menu['_attribute'])) {
                continue;
            }
            
            $attrs = $menu['_attribute'];
            
            $this->checkElementAttribute($menu, 'source', __('菜单配置错误：menu元素缺少source属性,文件：%{1}', $moduleAndFile));
            $this->checkElementAttribute($menu, 'name', __('菜单配置错误：menu元素缺少name属性,文件：%{1}', $moduleAndFile));
            $this->checkElementAttribute($menu, 'title', __('菜单配置错误：menu元素缺少title属性,文件：%{1}', $moduleAndFile));
            $this->checkElementAttribute($menu, 'order', __('菜单配置错误：menu元素缺少order属性,文件：%{1}', $moduleAndFile));
            
            if (empty($attrs['parent']) && !empty($parentSource)) {
                $attrs['parent'] = $parentSource;
            }
            
            if (!isset($attrs['action'])) {
                $attrs['action'] = '';
            }
            if (!isset($attrs['icon'])) {
                $attrs['icon'] = '';
            }
            if (!isset($attrs['is_system']) || '0' !== $attrs['is_system']) {
                $attrs['is_system'] = 1;
            }
            if (!isset($attrs['is_backend']) || '0' !== $attrs['is_backend']) {
                $attrs['is_backend'] = 1;
            }
            
            $items[] = $attrs;
            
            $currentSource = $attrs['source'] ?? '';
            
            if (isset($menu['_value']) && is_array($menu['_value']) && isset($menu['_value']['menu'])) {
                $childItems = $this->parseMenuElement($menu['_value']['menu'], $currentSource, $moduleAndFile);
                $items = array_merge($items, $childItems);
            }
        }
        
        return $items;
    }
}
