<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Plugin;

use Weline\Backend\Api\Config\KeysInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;

class StarPage
{
    private const FRONTEND_START_PAGE_CONFIG_KEY = 'frontend_start_page_path';
    private const FRONTEND_START_PAGE_CONFIG_MODULE = 'Weline_Websites';

    public function beforeGetUrlPath(Request $request, $url = '')
    {
        $result = $request->parse_url($url)['path'] ?? '';
        if (empty($result) or $result == '/') {
            if (PHP_SAPI === 'cli' && !$this->isHttpRuntimeRequest($request)) {
                return $result ?? '';
            }

            /** @var SystemConfig $configModel */
            $configModel = ObjectManager::getInstance(SystemConfig::class);
            $result = $this->getConfiguredStartPagePath($request, $configModel);
        }

        return is_scalar($result) ? trim((string)$result) : '';
    }

    private function isHttpRuntimeRequest(Request $request): bool
    {
        return $request instanceof \Weline\Framework\Http\WlsRequest && trim($request->getMethod()) !== '';
    }

    private function resolveWebsiteScope(Request $request, SystemConfig $configModel): string
    {
        $websiteCode = trim(RequestContext::getWelineWebsiteCode());
        if ($websiteCode === '') {
            $websiteCode = trim((string)$request->getServer('WELINE_WEBSITE_CODE'));
        }
        if ($websiteCode === '') {
            $websiteCode = trim((string)($_SERVER['WELINE_WEBSITE_CODE'] ?? ''));
        }

        return $websiteCode !== ''
            ? $configModel->normalizeScope($websiteCode)
            : SystemConfig::SCOPE_GLOBAL;
    }

    private function getConfiguredStartPagePath(Request $request, SystemConfig $configModel): string
    {
        $scope = $this->resolveWebsiteScope($request, $configModel);
        foreach ([
            [self::FRONTEND_START_PAGE_CONFIG_KEY, self::FRONTEND_START_PAGE_CONFIG_MODULE, SystemConfig::area_FRONTEND],
            [KeysInterface::key_start_page_path, KeysInterface::start_module, SystemConfig::area_BACKEND],
        ] as [$key, $module, $area]) {
            $result = $configModel->getConfig(
                key: $key,
                module: $module,
                area: $area,
                default: '',
                scope: $scope
            );
            if (is_scalar($result) && trim((string)$result) !== '') {
                return trim((string)$result);
            }
        }

        return '';
    }
}
