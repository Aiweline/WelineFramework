<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\View\Template;

/**
 * 控制器模板获取后观察者
 * 将控制器返回的内容提取并包装到布局文件中
 */
class ControllerFetchFileAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var DataObject $eventData */
        $eventData = $event->getData('data');
        if (!$eventData instanceof DataObject) {
            return;
        }

        $layoutType = $eventData->getData('layoutType');
        $contentTemplate = $eventData->getData('contentTemplate');
        $fileName = $eventData->getData('fileName');
        $content = $eventData->getData('content');
        // 关键检查：只有当控制器设置了 layoutType 时才处理
        // layoutType 必须不为 null 且不为空字符串
        if ($layoutType === null || $layoutType === '') {
            return;
        }

        // 如果 ControllerFetchFileBefore 没有设置 contentTemplate，说明没有找到布局文件或不需要处理
        // contentTemplate 是 ControllerFetchFileBefore 在找到布局文件时设置的原始模板路径
        // 如果 contentTemplate 存在，说明 ControllerFetchFileBefore 已经处理过，fileName 就是布局文件路径
        if (empty($contentTemplate)) {
            return;
        }

        try {
            // 获取模板实例（单例，数据在整个生命周期中保持）
            $template = Template::getInstance();
            
            // 渲染内容模板，获取控制器返回的内容
            // Template 是单例，控制器传递的变量（如 $user）会自动保持，不需要手动保存
            $contentHtml = $template->fetch($contentTemplate);
            
            // 准备布局数据，只传递 content
            // 其他参数（如 title、sidebar 等）已在 ControllerFetchFileBefore 中通过 ThemeData 加载到模板实例
            // fetch 方法的第二个参数会通过 addData 添加到模板数据中，不会覆盖现有数据
            $layoutData = [
                'content' => $contentHtml,
            ];
            
            // 渲染布局文件，将内容作为参数传递
            // 传入的 $layoutData 会与模板现有数据合并，控制器传递的变量（如 $user）和布局参数仍然可用
            $layoutContent = $template->fetch($fileName, $layoutData);
            
            // 更新事件数据中的内容
            $eventData->setData('content', $layoutContent);
            
        } catch (\Exception $e) {
            // 如果出现异常，保持原内容，不影响原有功能
            // 可以记录日志，但不抛出异常
            return;
        }
    }
}

