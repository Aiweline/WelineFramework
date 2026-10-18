<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;

/**
 * Extends maintenance whitelist with theme/static asset paths only.
 *
 * HTML/JSON body ownership stays with Weline_Maintenance (Interceptor →
 * MaintenanceStaticPage host-scoped pub/errors/maintenance), same authority as
 * WLS ServiceUnavailablePage. Do not render theme shell or mark handled here —
 * that made Nginx→FPM show a different page than WLS.
 */
class Maintenance implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $parse = (array)$event->getData('parse');
        $area = (string)($parse['area'] ?? 'frontend');
        $isApiRequest = \in_array($area, ['rest_frontend', 'rest_backend'], true);
        $isBackend = $area === 'backend';

        if (!$isApiRequest) {
            $acceptHeader = (string)\w_env('server.http_accept', '');
            $isApiRequest = str_contains($acceptHeader, 'application/json');
        }
        // API / backend: no storefront theme asset whitelist contribution.
        if ($isApiRequest || $isBackend) {
            return;
        }

        /** @var DataObject $data */
        $data = $event->getData('data');
        $white_urls = $data->getData('white_urls') ?? [];
        $white_urls[] = 'Weline/Theme/view/statics/ui/';
        $white_urls[] = 'static/Weline/Theme/ui/';
        $white_urls[] = 'Weline/Theme/view/theme/backend/assets/images/theme/logo.png';
        $white_urls[] = 'Weline/Theme/view/theme/frontend/assets/images/theme/icon.png';
        $white_urls[] = 'Weline/Theme/view/theme/frontend/assets/images/theme/apple-touch-icon.png';
        $white_urls[] = 'Weline/Backend/view/statics/img/favicon.png';
        $data->setData('white_urls', $white_urls);
    }
}
