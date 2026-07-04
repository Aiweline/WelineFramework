<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1\Seo;

use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SiteCrawlerAuditInterface;

class Crawl extends DevToolRestController
{
    private const PAYLOAD_TYPE = 'seo_crawl';
    private const PAYLOAD_TTL = 1800;

    private ?DevToolPayloadStore $payloadStore = null;

    public function postStart()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('SEO 全站审计需要有效的 Weline Panel Token。', [], 403);
            }

            $options = $this->requestPayload();
            $options['startUrl'] = (string)($options['startUrl'] ?? $this->currentRequestUrl());
            $report = $this->crawler()->crawl($options);
            $id = (string)($report['crawl']['id'] ?? '');
            if ($id === '') {
                $id = $this->newId();
            }
            $report['crawl']['id'] = $id;

            $this->payloadStore()->set(self::PAYLOAD_TYPE, 'crawl:' . $id, $report, self::PAYLOAD_TTL);
            $this->payloadStore()->set(self::PAYLOAD_TYPE, 'latest', $report, self::PAYLOAD_TTL);

            return $this->success('success', [
                'id' => $id,
                'report' => $report,
                'ttl' => self::PAYLOAD_TTL,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    public function getResult()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('SEO 全站审计需要有效的 Weline Panel Token。', [], 403);
            }

            $id = \trim((string)$this->request->getGet('id', ''));
            if ($id !== '' && !\preg_match('/^[a-zA-Z0-9_.:-]{8,96}$/', $id)) {
                return $this->error('无效的审计结果 ID。', [], 400);
            }

            $key = $id !== '' ? 'crawl:' . $id : 'latest';
            $report = $this->payloadStore()->get(self::PAYLOAD_TYPE, $key);
            if (!\is_array($report)) {
                return $this->error('SEO 全站审计结果不存在或已过期，请重新扫描。', [
                    'id' => $id,
                    'ttl' => self::PAYLOAD_TTL,
                ], 404);
            }

            return $this->success('success', [
                'id' => (string)($report['crawl']['id'] ?? $id),
                'report' => $report,
                'ttl' => self::PAYLOAD_TTL,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(): array
    {
        $body = $this->request->getBodyParams(true);
        if (\is_array($body)) {
            return $body;
        }

        $raw = $this->request->getBodyParams(false);
        if (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function isAllowed(): bool
    {
        return (new PanelAccessService())->canAccessApi($this->request);
    }

    private function crawler(): SiteCrawlerAuditInterface
    {
        if (!\interface_exists(SiteCrawlerAuditInterface::class) && !\class_exists(SiteCrawlerAuditInterface::class)) {
            throw new \RuntimeException('SEO 全站审计服务不可用，请确认 Weline_Seo 模块已启用。');
        }

        return ObjectManager::getInstance(SiteCrawlerAuditInterface::class);
    }

    private function payloadStore(): DevToolPayloadStore
    {
        if ($this->payloadStore === null) {
            $this->payloadStore = ObjectManager::getInstance(DevToolPayloadStore::class);
        }

        return $this->payloadStore;
    }

    private function newId(): string
    {
        try {
            return 'seo-crawl-' . \gmdate('YmdHis') . '-' . \bin2hex(\random_bytes(4));
        } catch (\Throwable) {
            return 'seo-crawl-' . \str_replace('.', '', \uniqid('', true));
        }
    }

    private function currentRequestUrl(): string
    {
        $candidate = (string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? '');
        if ($candidate !== '' && \preg_match('/^https?:\/\//i', $candidate)) {
            return $candidate;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && \strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');

        return $host !== '' ? $scheme . '://' . $host . $uri : '';
    }
}
