<?php

declare(strict_types=1);

namespace Weline\Component\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 后台 OffCanvas 结果页（成功/失败/信息），供 iframe 重定向使用。
 * 路由：component/offcanvas/success -> getSuccess，component/offcanvas/error -> getError，
 *      component/backend/offcanvas/getResult -> getResult（框架级 success/error/info 桥接页）。
 *
 * 使用主题布局：默认 default.blank（无菜单/边栏，含 head 与 BackendToast），
 * 可通过请求参数 layout 指定非 blank，如 layout=default 使用 default.default。
 */
class Offcanvas extends BaseController
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
     * 框架级结果桥接：接收 type/msg/url/reload，通过 BackendToast 显示并关 offcanvas。
     * 由 ResultRedirectBefore 在 iframe + ResultManager 有数据时自动重定向到此。
     */
    public function getResult(): string
    {
        $this->applyOffcanvasLayout();

        $type = $this->request->getParam('type') ?: 'success';
        $msg = $this->request->getParam('msg') ?: __('请求成功！');
        $url = $this->request->getParam('url') ?: '';
        $reload = $this->request->getParam('reload') !== '0';

        $data = ['type' => $type, 'msg' => $msg, 'url' => $url, 'reload' => $reload];

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
        $this->assign('msg', $this->request->getParam('msg') ?? __('请求成功！'));
        $this->assign('reload', $this->request->getParam('reload') ?? 1);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? '');
        $this->assign('url', $this->request->getParam('url') ?? '');
        return (string) $this->fetch('Weline_Component::templates/Offcanvas/success');
    }

    public function getError(): string
    {
        $this->applyOffcanvasLayout();
        $this->assign('msg', $this->request->getParam('msg') ?? __('请求失败！'));
        $this->assign('reload', $this->request->getParam('reload') ?? 0);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? '');
        $this->assign('url', $this->request->getParam('url') ?? '');
        return (string) $this->fetch('Weline_Component::templates/Offcanvas/error');
    }
}
