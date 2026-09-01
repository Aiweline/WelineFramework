<?php

declare(strict_types=1);

namespace Weline\Maintenance\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\MaintenanceStaticPage;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Maintenance\Service\MaintenanceDevPreview;
use Weline\Maintenance\Service\MaintenanceStaticGenerator;

/**
 * DEV-only live maintenance page preview (HTML/JSON + language switcher).
 *
 * URL: /maintenance/frontend/dev-preview
 * JSON: /maintenance/frontend/dev-preview?api=1&lang=en_US
 */
class DevPreview extends FrontendController
{
    public function index(): string
    {
        if (!MaintenanceDevPreview::isAvailable()) {
            throw new ResponseTerminateException(
                404,
                'Not Found',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $requestUri = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '/');
        if ($requestUri === '') {
            $requestUri = (string)$this->request->getUri();
        }
        $cookieHeader = (string)\Weline\Framework\Env\WelineEnv::server('HTTP_COOKIE', '');
        $langParam = MaintenanceStaticPage::normalizeLangCode((string)$this->request->getParam('lang', ''));
        $lang = $langParam !== ''
            ? $langParam
            : MaintenanceStaticPage::resolveLangFromRequestUri($requestUri, $cookieHeader);
        $generator = new MaintenanceStaticGenerator();

        $apiParam = (string)$this->request->getParam('api', '');
        if ($apiParam === '1' || $apiParam === 'true') {
            $retryAfter = MaintenanceDevPreview::retryAfter();
            throw new ResponseTerminateException(
                200,
                $generator->renderJson($lang, $retryAfter),
                [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'X-Maintenance-Dev-Preview' => '1',
                ],
            );
        }

        throw new ResponseTerminateException(
            200,
            $generator->renderDevPreviewHtml($lang),
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Robots-Tag' => 'noindex, nofollow',
                'X-Maintenance-Dev-Preview' => '1',
            ],
        );
    }
}
