<?php

declare(strict_types=1);

namespace Weline\Seo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Seo\Service\UrlSubmitService;

/** Critical DB-only SEO registration for URL-bearing ResourceChange v1. */
final class ResourceChanged implements ObserverInterface
{
    public function __construct(private readonly UrlSubmitService $urlSubmitService)
    {
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new \InvalidArgumentException(__('SEO ResourceChange Observer 只接受 v1 契约'));
        }
        if (!in_array($change->resourceType(), ['website', 'cms_page', 'url_rewrite'], true)) {
            return;
        }

        $payload = $change->toArray();
        $impact = is_array($payload['impact'] ?? null) ? $payload['impact'] : [];
        $current = $this->targets($impact['urls'] ?? [], $change->websiteId());
        $previous = $this->targets($impact['previous_urls'] ?? [], $change->websiteId());

        if ($current !== []) {
            $this->enqueue($current, $change, $change->action());
        }
        if ($previous !== []) {
            $this->enqueue($previous, $change, 'delete');
        }
    }

    /** @param mixed $urls @return list<array{website_id:int,url:string}> */
    private function targets(mixed $urls, int $websiteId): array
    {
        if (!is_array($urls)) {
            return [];
        }
        $targets = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $targets[$url] = ['website_id' => $websiteId, 'url' => $url];
            }
        }
        return array_values($targets);
    }

    /** @param list<array{website_id:int,url:string}> $targets */
    private function enqueue(array $targets, ResourceChange $change, string $action): void
    {
        $resourceType = $change->resourceType();
        $result = $this->urlSubmitService->enqueueTargets($targets, $resourceType, [
            'module' => match ($resourceType) {
                'cms_page' => 'Weline_Cms',
                'url_rewrite' => 'Weline_UrlManager',
                default => 'Weline_Websites',
            },
            'subject_type' => $resourceType,
            'subject_id' => $resourceType === 'website'
                ? $change->websiteId()
                : $change->resourceId(),
            'action' => $action,
            'resource_revision' => $change->revision(),
            'resource_event_id' => $change->eventId(),
        ]);
        if ((int)($result['errors'] ?? 0) > 0) {
            throw new \RuntimeException(__('SEO 资源变更任务登记失败'));
        }
    }
}
