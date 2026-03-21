<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Theme\Controller\Router as ThemeRouter;

/**
 * 在模块 Router 链之前处理带 preview_theme 的首页路径，避免被其它模块抢先重写
 */
class ProcessPreviewThemeUriBefore implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        try {
            $request = ObjectManager::getInstance(Request::class);
            if ($request->isBackend()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $path = $data->getData('path');
        if (!is_string($path)) {
            return;
        }

        $rule = $data->getData('rule');
        $ruleArr = [];
        if ($rule instanceof DataObject) {
            $ruleArr = $rule->getData();
        } elseif (is_array($rule)) {
            $ruleArr = $rule;
        }

        if (!empty($ruleArr['module'])) {
            return;
        }

        $previewTheme = (int)($_GET['preview_theme'] ?? 0);
        if ($previewTheme > 0) {
            try {
                /** @var Session $session */
                $session = ObjectManager::getInstance(Session::class);
                $session->setData('preview_theme_id', $previewTheme);
                $session->setData('preview_theme_area', 'frontend');
            } catch (\Throwable) {
            }
        }

        ThemeRouter::rewritePreviewThemeQuery($path, $ruleArr);

        $data->setData('path', $path);
        $data->setData('rule', new DataObject($ruleArr));
    }
}
