<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Config;

use Weline\Framework\Config\Reader\XmlReader;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class MenuXmlReader extends XmlReader
{
    public function __construct(
        Scanner $scanner,
        Parser  $parser,
                $path = 'etc/backend/menu.xml'
    )
    {
        parent::__construct($scanner, $parser, $path);
    }

    public function read(): array
    {
        $configs = parent::read();
        $module_menus = [];
        
        foreach ($configs as $module_and_file => $config) {
            if (!isset($config['menus']) || !is_array($config['menus'])) {
                w_log_warning(__('跳过格式不正确的菜单配置文件：%{1}', [$module_and_file]));
                continue;
            }
            
            $m_a_f_arr = explode('::', $module_and_file);
            $module = array_shift($m_a_f_arr);
            $module_menu_file = array_pop($m_a_f_arr);
            $module_menus[$module]['file'] = $module_menu_file;
            $module_menus[$module]['data'] = [];
            
            if (
                !isset($config['menus']['_attribute']['noNamespaceSchemaLocation']) && (
                    'urn:weline:module:Weline_Backend::etc/xsd/menu.xsd' !== ($config['menus']['_attribute']['noNamespaceSchemaLocation'] ?? '')
                )
            ) {
                $this->checkElementAttribute(
                    $config['menus'],
                    'noNamespaceSchemaLocation',
                    __('菜单元素menus必须设置：noNamespaceSchemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd"，文件：%{1}', $module_and_file)
                );
            }
            
            // Parser 将子节点放在 _value 下，需从 _value 中取 menu
            $menusContent = $config['menus']['_value'] ?? $config['menus'];
            $menusContent = is_array($menusContent) ? $menusContent : [];
            foreach ($menusContent as $key => $menuGroup) {
                if ($key === '_attribute' || $key === '_value') {
                    continue;
                }
                if ($key === 'menu') {
                    $menuItems = $this->parseMenuElement($menuGroup, '', $module_and_file);
                    $module_menus[$module]['data'] = array_merge($module_menus[$module]['data'], $menuItems);
                }
            }
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
