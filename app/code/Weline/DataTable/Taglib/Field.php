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
            'sort' => false,
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
            $name = $attrs['name'] ?? '';
            $sortable = boolval($attrs['sortable'] ?? self::default_sortable);
            $origin_sort_name = ($attrs['sort'] ?? $name);
            $sort_name = 'sort.' . $origin_sort_name;
            $current = $req->getGet('current', '');
            $current_sort_name = $current ? 'sort.' . $current : '';
            $order = strtolower($req->getGet($current_sort_name, 'desc'));
            $url = $attrs['url'] ?? '';
            $content = $tag_data[2] ?? '';
            $icon_str = '';
            $field_active = $sort_name == $current_sort_name;
            if ($sortable) {
                $icon_str = "<i class=\"fa fa-sort{{icon}}\"></i>";
                # 排序取反
                $icon_status = '';
                if ($order and $field_active) {
                    $order = $order == 'asc' ? 'desc' : 'asc';
                    $icon_status = $order == 'asc' ? '-down' : '-up';
                }
                $icon_str = str_replace('{{icon}}', $icon_status, $icon_str);
            }
            # 生成各个字段排序url
            # -- 排序修改成 当前字段
            $params['current'] = $origin_sort_name;
            $params['sort.' . $origin_sort_name] = $order;
            # 卸载其他排序
            foreach ($_GET as $key => $item) {
                if (str_contains($key, 'sort.')) {
                    unset($_GET[$key]);
                }
            }
            $url = $url ?: $req->getUrlBuilder()->getCurrentUrl($_GET, false);
            $url = $req->getUrlBuilder()->extractedUrl($params, true, $url);
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