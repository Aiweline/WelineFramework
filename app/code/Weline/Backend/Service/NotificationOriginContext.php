<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Runtime\ScopeContext;

/**
 * 通知收录时的统一来源上下文（CLI / FPM / WLS + 区域 + Website Scope）。
 *
 * 适配器只转发已收录正文，禁止在各渠道 formatMessage 里硬编码站点范围。
 */
final class NotificationOriginContext
{
    public const FOOTER_MARKER = '--- weline-notification-origin ---';

    /**
     * @param array<string, mixed> $data w_msg / Writer 入参
     * @return array<string, mixed>
     */
    public function enrich(array $data): array
    {
        $metadata = \is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if (!empty($metadata['origin']['enriched'])) {
            return $data;
        }

        $origin = $this->capture($data);
        $metadata['origin'] = $origin + ['enriched' => true];
        $data['metadata'] = $metadata;
        $data['content'] = $this->appendFooter((string) ($data['content'] ?? ''), $origin);

        if (\trim((string) ($data['scope'] ?? '')) === '' && $origin['scope'] !== '') {
            $data['scope'] = $origin['scope'];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   runtime_mode: string,
     *   area: string,
     *   website_code: string,
     *   website_url: string,
     *   scope: string,
     *   request_host: string,
     *   process_host: string,
     *   project_root: string,
     *   sapi: string,
     *   request_id: string
     * }
     */
    public function capture(array $data = []): array
    {
        $runtimeMode = Runtime::getMode();
        $area = $this->resolveArea($runtimeMode);
        $websiteCode = '';
        $websiteUrl = '';
        $scope = \trim((string) ($data['scope'] ?? ''));
        $requestHost = '';
        $requestId = '';

        try {
            if (RequestContext::isInitialized()) {
                $websiteCode = \trim(RequestContext::getWelineWebsiteCode());
                $websiteUrl = \trim(RequestContext::getWelineWebsiteUrl());
                if ($scope === '') {
                    $scope = \trim(ScopeContext::getScope());
                }
                $requestId = \trim((string) (RequestContext::getRequestId() ?? ''));
            }
        } catch (\Throwable) {
            // CLI / 无请求上下文：保持空字段
        }

        if ($scope === '' && isset($data['metadata']) && \is_array($data['metadata'])) {
            $scope = \trim((string) ($data['metadata']['scope'] ?? ''));
            if ($websiteCode === '') {
                $websiteCode = \trim((string) ($data['metadata']['website_code'] ?? ''));
            }
        }

        $serverHost = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $requestHost = \trim($serverHost);

        $processHost = '';
        try {
            $processHost = \trim((string) \gethostname());
        } catch (\Throwable) {
            $processHost = '';
        }

        $projectRoot = '';
        if (isset($data['metadata']) && \is_array($data['metadata'])) {
            $projectRoot = \trim((string) ($data['metadata']['deploy_root'] ?? $data['metadata']['project_root'] ?? ''));
        }
        if ($projectRoot === '' && \defined('BP')) {
            $projectRoot = \rtrim((string) BP, "/\\");
        }

        return [
            'runtime_mode' => $runtimeMode,
            'area' => $area,
            'website_code' => $websiteCode,
            'website_url' => $websiteUrl,
            'scope' => $scope,
            'request_host' => $requestHost,
            'process_host' => $processHost,
            'project_root' => $projectRoot,
            'sapi' => \PHP_SAPI,
            'request_id' => $requestId,
        ];
    }

    /**
     * @param array<string, string> $origin
     */
    public function formatFooter(array $origin): string
    {
        $lines = [
            self::FOOTER_MARKER,
            (string) __('运行模式') . ': ' . $this->labelOrDash($origin['runtime_mode'] ?? ''),
            (string) __('请求区域') . ': ' . $this->labelOrDash($origin['area'] ?? ''),
            (string) __('Website') . ': ' . $this->labelOrDash($origin['website_code'] ?? '', (string) __('全局')),
            (string) __('站点范围') . ': ' . $this->labelOrDash($origin['scope'] ?? '', (string) __('全局')),
            (string) __('站点URL') . ': ' . $this->labelOrDash($origin['website_url'] ?? ''),
            (string) __('请求Host') . ': ' . $this->labelOrDash($origin['request_host'] ?? ''),
            (string) __('进程主机') . ': ' . $this->labelOrDash($origin['process_host'] ?? ''),
            (string) __('项目路径') . ': ' . $this->labelOrDash($origin['project_root'] ?? ''),
            'SAPI: ' . $this->labelOrDash($origin['sapi'] ?? ''),
        ];
        if (\trim((string) ($origin['request_id'] ?? '')) !== '') {
            $lines[] = (string) __('请求ID') . ': ' . \trim((string) $origin['request_id']);
        }

        return \implode("\n", $lines);
    }

    private function appendFooter(string $content, array $origin): string
    {
        if (\str_contains($content, self::FOOTER_MARKER)) {
            return $content;
        }
        $footer = $this->formatFooter($origin);
        $content = \rtrim($content);

        return $content === '' ? $footer : $content . "\n\n" . $footer;
    }

    private function resolveArea(string $runtimeMode): string
    {
        if ($runtimeMode === RuntimeInterface::MODE_CLI) {
            return 'cli';
        }
        try {
            if (RequestContext::isInitialized()) {
                $area = \trim(RequestContext::getWelineArea());
                if ($area !== '') {
                    return $area;
                }
            }
        } catch (\Throwable) {
        }

        return 'none';
    }

    private function labelOrDash(string $value, string $emptyLabel = '—'): string
    {
        $value = \trim($value);

        return $value !== '' ? $value : $emptyLabel;
    }
}
