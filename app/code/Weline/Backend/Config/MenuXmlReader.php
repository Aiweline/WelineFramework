<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Config;

use Weline\Backend\Cache\BackendCache;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Config\Reader\XmlReader;
use Weline\Framework\Exception\Core;
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
        // 菜单提取
        $module_menus = [];
        $has_orders   = [];
        $data = [];
        foreach ($configs as $module_and_file => $config) {
            $m_a_f_arr                     = explode('::', $module_and_file);
            $module                        = array_shift($m_a_f_arr);
            $module_menu_file              = array_pop($m_a_f_arr);
            $module_menus[$module]['file'] = $module_menu_file;
            $module_menus[$module]['data'] = [];
            if (
                !isset($config['menus']['_attribute']['noNamespaceSchemaLocation']) && (
                    'urn:weline:module:Weline_Backend::etc/xsd/menu.xsd' !== $config['menus']['_attribute']['noNamespaceSchemaLocation']
                )
            ) {
                $this->checkElementAttribute(
                    $config['menus'],
                    'noNamespaceSchemaLocation',
                    __('菜单元素menus必须设置：noNamespaceSchemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd"，文件：%1', $module_and_file)
                );
            }
            foreach ($config['menus'] as $menu) {
                if (isset($menu['add'])) {
                    if (is_int(array_key_first($menu['add']))) {
                        foreach ($menu['add'] as $menu_data) {
                            if (isset($menu_data['_attribute'])) {
                                $this->checkElementAttribute(
                                    $menu_data,
                                    'name',
                                    __('菜单配置错诶：add元素缺少name属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/"  icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                                );
                                $this->checkElementAttribute(
                                    $menu_data,
                                    'source',
                                    __('菜单配置错诶：add元素缺少source属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/"  icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                                );
                                $this->checkElementAttribute(
                                    $menu_data,
                                    'title',
                                    __('菜单配置错诶：add元素缺少title属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/"  icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                                );
                                $this->checkElementAttribute(
                                    $menu_data,
                                    'action',
                                    __('菜单配置错诶：add元素缺少action属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/"  icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                                );
                                $this->checkElementAttribute(
                                    $menu_data,
                                    'order',
                                    __('菜单配置错诶：add元素缺少icon属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/" icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                                );
                                if (!isset($menu['add']['_attribute']['icon'])) {
                                    $menu['add']['_attribute']['icon'] = '';
                                }
                                /*$menu['add']['_attribute']['order'] = $menu['add']['_attribute']['order']??0;
                                if (in_array($menu_data['_attribute']['order'], $has_orders)) {
                                    throw new \Exception(__('菜单排序值 %1 重复,请重新设置order排序值.错误所在文件：%2', [$menu['add']['_attribute']['order'], $module_menu_file]));
                                }
                                $has_orders[] = $menu_data['_attribute']['order'];*/
                                # 默认属性
                                if (!isset($menu_data['_attribute']['is_system']) || '0' !== $menu_data['_attribute']['is_system']) {
                                    $menu_data['_attribute']['is_system'] = 1;
                                }
                                if (!isset($menu_data['_attribute']['is_backend']) || '0' !== $menu_data['_attribute']['is_backend']) {
                                    $menu_data['_attribute']['is_backend'] = 1;
                                }

                                $module_menus[$module]['data'][] = $menu_data['_attribute'];
                            }
                        }
                    } else {
                        $this->checkElementAttribute(
                            $menu['add'],
                            'name',
                            __('菜单配置错诶：add元素缺少name属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/" icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                        );
                        $this->checkElementAttribute(
                            $menu['add'],
                            'source',
                            __('菜单配置错诶：add元素缺少source属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/" icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                        );
                        $this->checkElementAttribute(
                            $menu['add'],
                            'title',
                            __('菜单配置错诶：add元素缺少title属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/" icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                        );
                        $this->checkElementAttribute(
                            $menu['add'],
                            'action',
                            __('菜单配置错诶：add元素缺少action属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/" icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                        );
                        $this->checkElementAttribute(
                            $menu['add'],
                            'order',
                            __('菜单配置错诶：add元素缺少order属性,文件：%1 配置示例： <add source="Weline_Backend::dashboard" name="Dashboard" title="Dashboard" action="/" icon="mdi-home-variant-outline" order="999"/>', $module_and_file)
                        );
                        if (!isset($menu['add']['_attribute']['icon'])) {
                            $menu['add']['_attribute']['icon'] = '';
                        }
                        /*$menu['add']['_attribute']['order'] = $menu['add']['_attribute']['order']??0;
                        if (in_array($menu['add']['_attribute']['order'], $has_orders)) {
                            throw new \Exception(__('菜单排序值 %1 重复,请重新设置order排序值.错误所在文件：%2', [$menu['add']['_attribute']['order'], $module_menu_file]));
                        }
                        $has_orders[] = $menu['add']['_attribute']['order'];*/
                        $menu['add']['_attribute']['is_system'] = 1;
                        if ($menu['add']['_attribute']) {
                            $module_menus[$module]['data'][] = $menu['add']['_attribute'];
                        }
                    }
                }
            }
        }
        foreach ($module_menus as &$module_menu) {
            $data = $module_menu['data'];
            if ($data) {
                $orders = array_column($data, 'order');
                array_multisort($orders, SORT_ASC);
                foreach ($orders as $order) {
                    foreach ($data as $k => $v) {
                        if ($v['order'] == $order) {
                            $module_menu['data'][$k] = $v;
                        }
                    }
                }
            }
        }
        return $module_menus;
    }
}
