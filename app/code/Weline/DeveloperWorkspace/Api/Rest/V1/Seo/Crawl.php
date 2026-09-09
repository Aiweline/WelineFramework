<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1\Seo;

use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Api\SiteCrawlerAuditInterface;

class Crawl extends DevToolRestController
{
    private const PAYLOAD_TYPE = 'seo_crawl';
    private const PAYLOAD_TTL = 1800;
    private const BATCH_SIZE = 2;

    private ?DevToolPayloadStore $payloadStore = null;

    public function postStart()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('SEO 全站审计需要有效的 Weline Panel Token。', [], 403);
            }

            $options = $this->requestPayload();
            $options['startUrl'] = (string)($options['startUrl'] ?? $this->currentRequestUrl());
            $crawler = $this->crawler();
            $job = $crawler->begin($options);
            if (($job['status'] ?? '') === 'running') {
                $job = $crawler->advance($job, self::BATCH_SIZE);
            }

            $id = $this->jobId($job);
            $job = $this->withJobId($job, $id);
            $this->storeJob($id, $job);
            $report = $crawler->toReport($job);
            $report['crawl']['id'] = $id;

            return $this->success('success', [
                'id' => $id,
                'status' => (string)($job['status'] ?? 'running'),
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
            $stored = $this->payloadStore()->get(self::PAYLOAD_TYPE, $key);
            if (!\is_array($stored)) {
                return $this->error('SEO 全站审计结果不存在或已过期，请重新扫描。', [
                    'id' => $id,
                    'ttl' => self::PAYLOAD_TTL,
                ], 404);
            }

            if ($this->isLegacyReport($stored)) {
                $resolvedId = (string)($stored['crawl']['id'] ?? $id);

                return $this->success('success', [
                    'id' => $resolvedId,
                    'status' => 'completed',
                    'report' => $stored,
                    'ttl' => self::PAYLOAD_TTL,
                ]);
            }

            $crawler = $this->crawler();
            $job = $this->normalizeStoredJob($stored, $id);
            if (($job['status'] ?? '') === 'running') {
                $job = $crawler->advance($job, self::BATCH_SIZE);
                $resolvedId = $this->jobId($job);
                $job = $this->withJobId($job, $resolvedId);
                $this->storeJob($resolvedId, $job);
            }

            $resolvedId = (string)($job['id'] ?? $id);
            $report = $crawler->toReport($job);
            $report['crawl']['id'] = $resolvedId;

            return $this->success('success', [
                'id' => $resolvedId,
                'status' => (string)($job['status'] ?? 'completed'),
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
            // Crawl jobs must survive worker hops between start and result polls.
            $this->payloadStore = new DevToolPayloadStore(['force_file' => true]);
        }

        return $this->payloadStore;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function storeJob(string $id, array $job): void
    {
        $this->payloadStore()->set(self::PAYLOAD_TYPE, 'crawl:' . $id, $job, self::PAYLOAD_TTL);
        $this->payloadStore()->set(self::PAYLOAD_TYPE, 'latest', $job, self::PAYLOAD_TTL);
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function normalizeStoredJob(array $stored, string $id): array
    {
        if ($this->isJob($stored)) {
            if ($id !== '' && (!isset($stored['id']) || (string)$stored['id'] === '')) {
                $stored['id'] = $id;
            }

            return $stored;
        }

        // Should not reach for legacy reports (handled earlier); treat as completed job shell.
        $legacyId = (string)($stored['crawl']['id'] ?? $id);
        if ($legacyId === '') {
            $legacyId = $this->newId();
        }

        return [
            'status' => 'completed',
            'id' => $legacyId,
            'urls' => [],
            'cursor' => 0,
            'pages' => \is_array($stored['pages'] ?? null) ? $stored['pages'] : [],
            'issues' => \is_array($stored['issues'] ?? null) ? $stored['issues'] : [],
            'failed' => \is_array($stored['failedUrls'] ?? null) ? $stored['failedUrls'] : [],
            'factsByUrl' => [],
            'titleMap' => [],
            'descriptionMap' => [],
            'canonicalMap' => [],
            'resourceHeadBudget' => 0,
            'sitemapMessages' => [],
            'relaxedTlsHosts' => [],
            'resourceHeadCache' => [],
            'sitemapUrl' => (string)($stored['crawl']['sitemapUrl'] ?? ''),
            'origin' => (string)($stored['crawl']['sameOrigin'] ?? ''),
            'limit' => (int)($stored['crawl']['limit'] ?? 100),
            'timeout' => (int)($stored['crawl']['timeoutSeconds'] ?? 6),
            'startedAt' => (string)($stored['crawl']['startedAt'] ?? ($stored['generatedAt'] ?? \gmdate('c'))),
            'finishedAt' => (string)($stored['crawl']['finishedAt'] ?? ($stored['generatedAt'] ?? \gmdate('c'))),
            'health' => \is_array($stored['health'] ?? null) ? $stored['health'] : [],
            'assumptions' => \is_array($stored['assumptions'] ?? null) ? $stored['assumptions'] : [],
            'contractVersion' => (string)($stored['contractVersion'] ?? 'weline-seo-site-crawl/v1'),
            'command' => (string)($stored['command'] ?? 'weline-seo-site-crawl-report'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isJob(array $payload): bool
    {
        $status = (string)($payload['status'] ?? '');

        return ($status === 'running' || $status === 'completed')
            && \array_key_exists('cursor', $payload)
            && \array_key_exists('urls', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isLegacyReport(array $payload): bool
    {
        return !$this->isJob($payload)
            && isset($payload['contractVersion'])
            && isset($payload['crawl'])
            && \is_array($payload['crawl']);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function jobId(array $job): string
    {
        $id = \trim((string)($job['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        return $this->newId();
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function withJobId(array $job, string $id): array
    {
        $job['id'] = $id;

        return $job;
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
