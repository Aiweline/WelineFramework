<?php

namespace Weline\DataTable\Taglib;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\TaglibInterface;

class Field implements TaglibInterface
{
    const default_sortable = false;

    static public function name(): string
    {
        return 'field';
    }

    static function tag(): bool
    {
        return true;
    }

    static function attr(): array
    {
        return [
            'name' => true,
            'sortable' => false,
            'url' => false,
            'multi' => false,
            'icon' => false,
        ];
    }

    static function tag_start(): bool
    {
        return false;
    }

    static function tag_end(): bool
    {
        return false;
    }

    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attrs) {
            /** @var Request $req */
            $req = ObjectManager::getInstance(Request::class);
            # url构造参数
            $url_params = $_GET;

            $name = $attrs['name'] ?? '';
            $multi = boolval($attrs['multi'] ?? false);
            $sortable = boolval($attrs['sortable'] ?? self::default_sortable);
            $sort_name = 'sort.' . $name;

            $url_params['current'] = $name;

            $current = $req->getGet('current', '');
            $current_sort_name = $current ? 'sort.' . $current : '';

            $order = $current_sort_name ? strtolower($req->getGet($current_sort_name, 'desc')) : 'desc';

            # 获取所有排序
            $sorts = $req->getGetByPre('sort.');
            if (!$multi) { # 非多字段排序，卸载掉其他字段，保留current指定的排序
                foreach ($sorts as $key => $sort) {
                    if ($key != $current_sort_name) {
                        $sorts[$key] = '';
                    }
                }
            }
            $url_params = array_merge($url_params, $sorts);

            $url = $attrs['url'] ?? '';
            $content = $tag_data[2] ?? '';

            # 当前字段可排序时显示排序图标，并且当前字段如果被激活，则显示当前排序状态
            $icon_str = '';
            $field_active = $sort_name == $current_sort_name; # 当前字段是否激活
            if ($sortable) {
                $icon_str = "<i class=\"fa fa-sort{{icon}}\"></i>";
                # 排序取反
                $icon_status = '';
                if ($order and $field_active) {
                    $order = $order == 'asc' ? 'desc' : 'asc';
                    $icon_status = $order == 'asc' ? '-down' : '-asc';
                }
                $icon_str = str_replace('{{icon}}', $icon_status, $icon_str);
            }
            # 生成各个字段排序url
            # -- 排序修改成 当前字段
            $url_params['sort.' . $url_params['current']] = $order;

            $url = $url ?: $req->getUrlBuilder()->getCurrentUrl($url_params, false);
            $url = $req->getUrlBuilder()->extractedUrl($url_params, false, $url);
            $start = <<<DOC
<div data-field="$name" data-sort-field="$sort_name" class="table-head-item border-1">
DOC;
            if ($sortable) {
                $field_active = $field_active ? 'active text-info' : '';
                $start .= "<a href=\"$url\" class='$field_active'>" . $content . $icon_str . '</a>';
            } else {
                $start .= $content;
            }
            return $start . '</div>';
        };
    }

    static function tag_self_close(): bool
    {
        return false;
    }

    static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    static function document(): string
    {
        $sort = __('排序');
        $name = __('字段名');
        $icon = 'fas fa-caret-up';
        $label = __('字段显示名');
        return <<<DOC
<field name="$name" label="$label" sort="$sort" icon="$icon">
<!-- 内容 -->
</field>
DOC;
    }
}