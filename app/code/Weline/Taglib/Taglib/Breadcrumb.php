<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/16 08:23:43
 */

namespace Weline\Taglib\Taglib;

use TheSeer\Tokenizer\Exception;
use Weline\Backend\Model\Menu;
use Weline\Framework\Database\Model;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;

class Breadcrumb implements \Weline\Taglib\TaglibInterface
{
    static Request|null $request = null;

    /**
     * @inheritDoc
     */
    static public function name(): string
    {
        return 'breadcrumb';
    }

    /**
     * @inheritDoc
     */
    static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function attr(): array
    {
        return ['model' => 1, 'action_field' => 0, 'id_field' => 0, 'parent_field' => 0, 'order_field' => 0, 'name_field' => 0];
    }

    /**
     * @inheritDoc
     */
    static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $request      = self::getRequest();
            $path         = $request->getRouteUrlPath();
            $model        = $attributes['model'];
            $action_field = $attributes['action_field'] ?? 'action';
            $field        = $attributes['id_field'] ?? '';
            $parent_field = $attributes['parent_field'] ?? 'pid';
            $name_field   = $attributes['name_field'] ?? 'name';
            $order_field  = $attributes['order_field'] ?? 'position';
            $sort         = $attributes['sort'] ?? 'ASC';

            $html = '<ol class=\'breadcrumb m-0\'>';
            /**@var \Weline\Framework\Database\Model $model */
            $model = ObjectManager::getInstance($model);
            $menu  = $model->reset()->where($action_field, $path)->find()->fetch();
            if (!$menu->getId()) {
                return '';
            }
            $current_html = '';
            self::getHtml($menu, $name_field, $action_field, $current_html);;
            $menu->getParentPaths($model, $field, $parent_field, $order_field, $sort);
            $parent = $menu->getData('parents')[0] ?? [];
            if ($parent) self::breadcrumb($parent, $html, $action_field, $name_field);
            $html .= "{$current_html}</ol>";
            return $html;
        };
    }

    public static function breadcrumb(Model $parent, string &$html, &$action_field, &$name_field)
    {
        $sub_parent = $parent->getData('parents')[0] ?? [];
        if ($sub_parent) {
            self::breadcrumb($sub_parent, $html, $action_field, $name_field);
        }
        $html = self::getHtml($parent, $name_field, $action_field, $html);
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    static function document(): string
    {
        return htmlentities(<<<DOC
自动生成面包屑导航列表。
<breadcrumb model="Weline\Demo\Model\Demo" id_field="demo_id" parent_field="pid" order_field="position" name_field="name"/> 
或者 <breadcrumb type="path" model="Weline\Demo\Model\Demo"  id_field="demo_id" parent_field="pid" order_field="position" name_field="name"/>
<breadcrumb model="Weline\Demo\Model\Demo" id_field="demo_id" parent_field="pid" order_field="position" name_field="name"></breadcrumb> 
或者 <breadcrumb model="Weline\Demo\Model\Demo"  id_field="demo_id" parent_field="pid" order_field="position" name_field="name"></breadcrumb>
model: 模型
id_field: 模型ID字段
parent_field: 模型父字段
order_field: 模型排序字段
name_field: 模型名称字段
DOC
        );
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/16 14:04
     * 参数区：
     *
     * @param \Weline\Framework\Database\Model $parent
     * @param                                  $name_field
     * @param                                  $action_field
     * @param string                           $html
     *
     * @return string
     */
    protected static function getHtml(Model $parent, &$name_field, &$action_field, string &$html): string
    {
        $name    = __($parent[$name_field]);
        $request = self::getRequest();
        if (!empty($parent[$action_field])) {
            if ($request->isBackend()) {
                $action = $request->getUrlBuilder()->getBackendUrl($parent[$action_field]);
            } else {
                $action = $request->getUrlBuilder()->getUrl($parent[$action_field]);
            }
        } else {
            $action = 'javascript: void(0);';
        }
        $html .= "<li class='breadcrumb-item'><a href='{$action}'>{$name}</a></li>";
        return $html;
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/16 14:12
     * 参数区：
     * @return \Weline\Framework\Http\Request
     */
    static function getRequest(): Request
    {
        if (!isset(self::$request)) {
            self::$request = ObjectManager::getInstance(Request::class);
        }
        return self::$request;
    }
}