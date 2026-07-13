<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\Backend;

use Weline\Backend\Api\Runtime\FrontendStartPageRouteProviderInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Api\ConfigReader;

final class FrontendStartPageRouteProvider implements FrontendStartPageRouteProviderInterface
{
    public function __construct(
        private readonly ConfigReader $configReader,
    ) {
    }

    public function resolve(Request $request): string
    {
        $websiteCode = \trim(RequestContext::getWelineWebsiteCode());
        if ($websiteCode === '') {
            $websiteCode = \trim((string)$request->getServer('WELINE_WEBSITE_CODE'));
        }
        if ($websiteCode === '') {
            $websiteCode = \trim((string)($_SERVER['WELINE_WEBSITE_CODE'] ?? ''));
        }
        $scope = $websiteCode !== ''
            ? $this->configReader->normalizeScope($websiteCode)
            : $this->configReader->globalScope();
        $result = $this->configReader->get(
            'frontend_start_page_path',
            'Weline_Websites',
            $this->configReader->frontendArea(),
            '',
            $scope,
        );
        return \is_scalar($result) ? \trim((string)$result) : '';
    }
}
