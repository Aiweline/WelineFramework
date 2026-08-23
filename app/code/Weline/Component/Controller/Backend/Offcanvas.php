<?php

declare(strict_types=1);

namespace Weline\Component\Controller\Backend;

use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 后台 OffCanvas 结果页（成功/失败/信息），供 iframe 重定向使用。
 * 路由：component/offcanvas/success -> getSuccess，component/offcanvas/error -> getError，
 *      component/backend/offcanvas/getResult -> getResult（框架级 success/error/info 桥接页）。
 *
 * 使用主题布局：默认 default.blank（无菜单/边栏，含 head 与 Weline.UI.toast），
 * 可通过请求参数 layout 指定非 blank，如 layout=default 使用 default.default。
 */
class Offcanvas extends BackendPageController
{
    /**
     * 设置布局类型：默认 blank，否则走主题配置（支持 layout 参数区分 blank/非blank）
     */
    private function applyOffcanvasLayout(): void
    {
        $layout = $this->request->getParam('layout');
        if ($layout !== null && $layout !== '') {
            $this->layoutType = str_contains($layout, '.') ? $layout : 'default.' . $layout;
        } else {
            $this->layoutType = 'default.blank';
        }
    }

    /**
     * 框架级结果桥接：接收 type/msg/url/reload，通过 Weline.UI.toast 显示并关 offcanvas。
     * 由 Framework ResultBridgeRedirect 在 iframe + ResultManager 有数据时自动重定向到此（桥接页地址由事件返回）。
     */
    public function getResult(): string
    {
        $this->applyOffcanvasLayout();

        $data = $this->resultData(
            (string)($this->request->getParam('type') ?: 'success'),
            (string)($this->request->getParam('msg') ?: __('请求成功！')),
            true,
        );

        $eventData = new \Weline\Framework\DataObject\DataObject(['data' => $data]);
        ObjectManager::getInstance(EventsManager::class)->dispatch('Framework_Component::result_render', $eventData);

        $output = $eventData->getData('output');
        if ($output !== null && $output !== '') {
            return (string) $output;
        }

        return (string) $this->fetch('Weline_Component::templates/Offcanvas/result', $data);
    }

    public function getSuccess(): string
    {
        $this->applyOffcanvasLayout();
        return (string)$this->fetch(
            'Weline_Component::templates/Offcanvas/result',
            $this->resultData('success', (string)($this->request->getParam('msg') ?: __('请求成功！')), true),
        );
    }

    public function getError(): string
    {
        $this->applyOffcanvasLayout();
        return (string)$this->fetch(
            'Weline_Component::templates/Offcanvas/result',
            $this->resultData('error', (string)($this->request->getParam('msg') ?: __('请求失败！')), false),
        );
    }

    /**
     * @return array{type:string,msg:string,content:string,url:string,reload:bool,time:int}
     */
    private function resultData(string $type, string $message, bool $defaultReload): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['success', 'error', 'info'], true)) {
            $type = 'info';
        }

        $reloadValue = $this->request->getParam('reload');
        $reload = $reloadValue === null || $reloadValue === ''
            ? $defaultReload
            : filter_var($reloadValue, FILTER_VALIDATE_BOOLEAN);

        return [
            'type' => $type,
            'msg' => $message,
            'content' => (string)($this->request->getParam('content') ?? ''),
            'url' => (string)($this->request->getParam('url') ?? ''),
            'reload' => $reload,
            'time' => max(0, min(30, (int)($this->request->getParam('time') ?? 3))),
        ];
    }
}
