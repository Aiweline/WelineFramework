<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/25 00:16:53
 */

namespace Weline\Component\Block\Tool;

use Weline\Component\ComponentInterface;

class SortBar extends \Weline\Framework\View\Block implements ComponentInterface
{
    public const default_sorts = [
        'default' => [
            'field' => 'create_time',
            'sort'  => 'desc'
        ],
        'lasted'  => [
            'field' => 'create_time',
            'sort'  => 'desc'
        ],
        'update'  => [
            'field' => 'update_time',
            'sort'  => 'desc'
        ]
    ];

    private array $sort_data = [];
    protected string $_template = 'Weline_Component::tool/sort-bar.phtml';

    public function __init(): void
    {
        parent::__init();
        $action = $this->getData('action');
        if ($action) {
            $action = $this->request->isBackend() ? $this->getBackendUrl($this->getData('action')) : $this->getUrl($this->getData('action'));
        }
        # 查看排序器缓存
        $sorter_name = $this->getData('sorter');# 排序器名字，用于读取映射缓存
        if (empty($sorter_name)) {
            throw new \Exception(__('排序器属性sorter不能为空，示例：%1', $this->doc()));
        }
        $this->assign('sorts', $this->getSorts($sorter_name));
        $this->assign('action', $action);
    }

    public function getSorts(string $sorter_name, $current = false): array
    {
        $sorts = $this->getDefaultSorts($sorter_name);
        # params添加，并由是否当前排序来决定返回是否反转数据
        $params = $this->request->getParams();
        foreach ($sorts['data'] as $key => &$sort) {
            $target         = isset($params[$key]);
            $sort['target'] = $target;
            if ($target) {
                $sort['sort'] = $params[$key];
            }
            if ($current) {
                continue;
            }
            switch (strtolower($sort['sort'])) {
                case 'desc':
                    $sort['sort'] = 'asc';
                    $sort['icon'] = $sorts['down_icon'];
                    break;
                case 'asc':
                    $sort['sort'] = 'desc';
                    $sort['icon'] = $sorts['up_icon'];
                    break;
            }
            $sort['href'] = $this->request->getUrlBuilder()->extractedUrl([$key => $sort['sort']], true);
            $sort['name'] = __($key);
        }
        return $sorts['data'];
    }

    public function getCurrentSorts(string $sorter_name): array
    {
        return $this->getSorts($sorter_name, true);
    }

    public function getDefaultSorts(string $sorter_name)
    {
        if (isset($this->sort_data[$sorter_name])) {
            return $this->sort_data[$sorter_name];
        }
        # 查看排序器缓存
        $sorts = $this->_cache->get($sorter_name) ?: [];
        if (!$sorts) {
            $action_sorts       = $this->getData('sorts');#lasted=>create_time:desc
            $up_icon            = $this->getData('up_icon') ? '<i class="' . $this->getData('up_icon') . '"></i>' : '<i class="mdi mdi-arrow-up"></i>';
            $down_icon          = $this->getData('down_icon') ? '<i class="' . $this->getData('down_icon') . '"></i>' : '<i class="mdi mdi-arrow-down"></i>';
            $sorts['up_icon']   = $up_icon;
            $sorts['down_icon'] = $down_icon;
            $sorts['data']      = self::default_sorts;
            if ($action_sorts) {
                $action_sorts = explode(',', $action_sorts);
                foreach ($action_sorts as $key => $action_sort) {
                    unset($action_sorts[$key]);
                    $action_sort = explode('=>', $action_sort);
                    if (count($action_sort) === 2) {
                        $action_sort_value             = explode(':', $action_sort[1]);
                        $action_sorts[$action_sort[0]] = [
                            'field' => $action_sort_value[0],
                            'sort'  => $action_sort_value[1] ?? 'desc',
                        ];
                    }
                }
                if ($action_sorts) {
                    $sorts['data'] = array_merge($sorts['data'], $action_sorts);
                }
            }
            $this->_cache->set($sorter_name, $sorts);
        }
        return $sorts;
    }

    public function doc(): string
    {
        return htmlspecialchars($this->tmp_replace('
<h3><lang>排序组件：快速构建排序按钮</lang></h3>
<w:block class="Weline\Component\Block\Tool\SortBar"
action="*/demo/listing"
up_icon="mdi mdi-arrow-up"
down_icon="mdi mdi-arrow-down"
sorts="hot=>viewed:desc,price=>price:asc"
sorter="index_sorter"/>
<div>
[action]: <lang>排序点击时请求的地址</lang> <br>
[sorts]: hot=>viewed:desc,price=>price:asc <lang>结构说明：参数名=>映射的字段:排序方法 英文逗号隔开每个排序</lang><br>
[sorter]: <lang>控制器获取对应sorter可以获取这些排序字段</lang>
</div>
'));
    }
}
