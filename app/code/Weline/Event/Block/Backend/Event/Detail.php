<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Event\Block\Backend\Event;

use Weline\Framework\View\Block;

class Detail extends Block
{
    /**
     * 渲染事件详情内容
     */
    public function render(): string
    {
        $event = $this->getData('event');
        $eventName = $this->getData('event_name');
        
        if (!$event) {
            return '<div class="alert alert-warning">事件不存在</div>';
        }
        
        // Block 的 fetchHtml 方法会使用 'blocks' 类型查找模板，但我们的模板在 'templates' 目录
        // 所以使用 fetchTagHtml 方法，指定 'templates' 类型
        return $this->fetchTagHtml('templates', 'Weline_Event::Backend/Event/detail-content.phtml');
    }
}

