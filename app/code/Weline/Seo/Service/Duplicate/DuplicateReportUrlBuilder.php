<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * Stable backend report URL for a duplicate-check run.
 *
 * Always keep the area frontName (admin path prefix). Bare `/seo/backend/...`
 * without that prefix resolves as storefront and 404s.
 */
final class DuplicateReportUrlBuilder
{
    public const REPORT_ROUTE = 'seo/backend/duplicate/report';

    /** @deprecated use REPORT_ROUTE; kept for callers that expect a path constant */
    public const REPORT_PATH = '/seo/backend/duplicate/report';

    public function __construct(
        private readonly ?string $backendFrontName = null
    ) {
    }

    public function pathForRun(int $runId, ?string $grade = null, bool $withFrontName = true): string
    {
        if ($runId < 1) {
            throw new \InvalidArgumentException('run_id must be >= 1');
        }
        $query = ['run_id' => (string)$runId];
        if ($grade !== null && $grade !== '') {
            $query['grade'] = $grade;
        }
        $route = '/' . self::REPORT_ROUTE . '?' . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        if (!$withFrontName) {
            return $route;
        }

        $prefix = $this->resolveFrontName();

        return ($prefix !== '' ? '/' . $prefix : '') . $route;
    }

    public function panelPath(bool $withFrontName = true): string
    {
        $route = '/seo/backend/duplicate';
        if (!$withFrontName) {
            return $route;
        }
        $prefix = $this->resolveFrontName();

        return ($prefix !== '' ? '/' . $prefix : '') . $route;
    }

    /**
     * @param string|null $backendBaseUrl Origin or full backend base (scheme://host[:port])
     * @param bool $withFrontName When base is an origin, prepend area frontName (default true)
     */
    public function absoluteForRun(
        int $runId,
        ?string $backendBaseUrl = null,
        ?string $grade = null,
        bool $withFrontName = true
    ): string {
        $explicit = \trim((string)$backendBaseUrl);
        if ($explicit !== '') {
            return \rtrim($explicit, '/') . $this->pathForRun($runId, $grade, $withFrontName);
        }

        $fromFramework = $this->tryFrameworkBackendUrl($runId, $grade);
        if ($fromFramework !== null) {
            return $fromFramework;
        }

        return $this->resolveOrigin() . $this->pathForRun($runId, $grade, true);
    }

    public function absolutePanelUrl(?string $backendBaseUrl = null): string
    {
        $explicit = \trim((string)$backendBaseUrl);
        if ($explicit !== '') {
            return \rtrim($explicit, '/') . $this->panelPath(true);
        }

        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $built = \trim((string)$url->getBackendUrl('seo/backend/duplicate'));
            if ($built !== '') {
                return $built;
            }
        } catch (\Throwable) {
            // fall through
        }

        return $this->resolveOrigin() . $this->panelPath(true);
    }

    private function tryFrameworkBackendUrl(int $runId, ?string $grade): ?string
    {
        $params = ['run_id' => (string)$runId];
        if ($grade !== null && $grade !== '') {
            $params['grade'] = $grade;
        }

        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $built = \trim((string)$url->getBackendUrl(self::REPORT_ROUTE, $params));
            if ($built !== '') {
                return $built;
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    private function resolveFrontName(): string
    {
        if ($this->backendFrontName !== null) {
            return \trim($this->backendFrontName, '/');
        }
        try {
            return \trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveOrigin(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';

        return $scheme . '://' . $host;
    }
}
