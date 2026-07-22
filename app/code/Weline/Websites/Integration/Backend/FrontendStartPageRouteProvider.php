<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\Backend;

use Weline\Backend\Api\Runtime\FrontendStartPageRouteProviderInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\Websites\Api\DomainStartPageConfig;

final class FrontendStartPageRouteProvider implements FrontendStartPageRouteProviderInterface
{
    public function __construct(
        private readonly ConfigReader $configReader,
    ) {
    }

    public function resolve(Request $request): string
    {
        // AI publish writes a domain-hash key in global scope so root routing can
        // resolve before website detection finishes. Prefer that exact host key.
        $domainRoute = $this->resolveDomainStartPagePath($request);
        if ($domainRoute !== '') {
            return $domainRoute;
        }

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

    private function resolveDomainStartPagePath(Request $request): string
    {
        $host = $this->resolveRequestHost($request);
        $key = DomainStartPageConfig::key($host);
        if ($key === '') {
            return '';
        }

        $result = $this->configReader->get(
            $key,
            'Weline_Websites',
            $this->configReader->frontendArea(),
            '',
            $this->configReader->globalScope(),
        );

        return \is_scalar($result) ? \trim((string)$result) : '';
    }

    private function resolveRequestHost(Request $request): string
    {
        foreach ([
            (string)$request->getServer('HTTP_HOST'),
            (string)($_SERVER['HTTP_HOST'] ?? ''),
            (string)$request->getServer('SERVER_NAME'),
            (string)($_SERVER['SERVER_NAME'] ?? ''),
        ] as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '') {
                continue;
            }
            // Strip port so DomainStartPageConfig matches the stored apex domain.
            $host = \preg_replace('/:\\d+$/', '', $candidate) ?? $candidate;
            $host = DomainStartPageConfig::normalizeDomain($host);
            if ($host !== '') {
                return $host;
            }
        }

        return '';
    }
}
