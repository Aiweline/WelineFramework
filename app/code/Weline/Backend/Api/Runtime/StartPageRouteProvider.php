<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Backend\Config\KeysInterface;
use Weline\Backend\Model\Config;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\StartPageRouteProviderInterface;
use Weline\SystemConfig\Api\ConfigReader;

final class StartPageRouteProvider implements StartPageRouteProviderInterface
{
    public function __construct(
        private readonly ConfigReader $configReader,
        private readonly RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    public function resolveConfiguredRoute(Request $request): string
    {
        try {
            $frontendProvider = $this->runtimeProviderResolver->resolve(FrontendStartPageRouteProviderInterface::class);
            if ($frontendProvider instanceof FrontendStartPageRouteProviderInterface) {
                $frontendRoute = $frontendProvider->resolve($request);
                if ($frontendRoute !== '') {
                    return $frontendRoute;
                }
            }

            $result = $this->configReader->get(
                KeysInterface::key_start_page_path,
                KeysInterface::start_module,
                $this->configReader->backendArea(),
                '',
                $this->scope($request, $this->configReader),
            );
            if (\is_scalar($result) && \trim((string)$result) !== '') {
                return \trim((string)$result);
            }
        } catch (\Throwable) {
        }

        try {
            $result = ObjectManager::getInstance(Config::class)->getConfig(
                KeysInterface::key_start_page_path,
                KeysInterface::start_module,
            );
            return is_scalar($result) ? trim((string)$result) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function scope(Request $request, ConfigReader $reader): string
    {
        $websiteCode = trim(RequestContext::getWelineWebsiteCode());
        if ($websiteCode === '') {
            $websiteCode = trim((string)$request->getServer('WELINE_WEBSITE_CODE'));
        }
        if ($websiteCode === '') {
            $websiteCode = trim((string)($_SERVER['WELINE_WEBSITE_CODE'] ?? ''));
        }
        return $websiteCode !== '' ? $reader->normalizeScope($websiteCode) : $reader->globalScope();
    }
}
