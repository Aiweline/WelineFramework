<?php

declare(strict_types=1);

namespace Weline\Seo\Queue;

use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;
use Weline\Seo\Service\Duplicate\ContentDuplicateScanner;
use Weline\Seo\Service\Duplicate\DuplicateCheckScope;

final class ContentDuplicateScanQueue implements QueueInterface
{
    public function __construct(private readonly ContentDuplicateScanner $scanner)
    {
    }

    public function name(): string
    {
        return (string)__('SEO 站内重复内容扫描队列');
    }

    public function tip(): string
    {
        return (string)__('按范围执行正文近重复扫描并生成可打开的报告地址。');
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $payload = $this->payload($queue);
        $valid = (string)($payload['contract'] ?? '') === 'seo.content_duplicate_scan_queue.v1'
            && isset($payload['website_id'])
            && (int)$payload['website_id'] >= 0;
        if (!$valid) {
            $queue->setResult((string)__('SEO 重复内容扫描队列参数无效。'));
        }

        return $valid;
    }

    public function execute(Queue &$queue): string
    {
        $payload = $this->payload($queue);
        $scope = DuplicateCheckScope::fromArray($payload);
        $result = $this->scanner->scan($scope, isset($payload['backend_base_url']) ? (string)$payload['backend_base_url'] : null);

        return (string)\json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function payload(Queue $queue): array
    {
        $decoded = \json_decode((string)$queue->getContent(), true);

        return \is_array($decoded) && !\array_is_list($decoded) ? $decoded : [];
    }
}
