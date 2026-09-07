<?php
declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoTask;

/** Local durable refresh; business saves never publish files or call search engines. */
class SitemapRefreshService
{
    public function __construct(
        private readonly SeoTask $tasks,
        private readonly SitemapUrlSyncService $sync,
        private readonly SitemapRegistryService $registry,
        private readonly WebSitemapData $sitemaps,
    ) {}

    public function enqueue(int $websiteId, string $module, string $eventId = ''): int
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('Invalid sitemap website ID');
        }
        $existing = (clone $this->tasks)->reset()
            ->where(SeoTask::schema_fields_TASK_TYPE, SeoTask::TASK_TYPE_SITEMAP_REFRESH)
            ->where(SeoTask::schema_fields_SUBJECT_ID, $websiteId)
            ->where(SeoTask::schema_fields_MODULE, $module)
            ->where(SeoTask::schema_fields_STATUS, SeoTask::STATUS_PENDING)
            ->find()->getId();
        if ($existing) {
            return (int)$existing;
        }
        $task = (clone $this->tasks)->reset()
            ->setTaskType(SeoTask::TASK_TYPE_SITEMAP_REFRESH)
            ->setSubjectType('website')->setSubjectId($websiteId)
            ->setData(SeoTask::schema_fields_MODULE, $module)
            ->setData(SeoTask::schema_fields_SCOPE, 'sitemap')
            ->setStatus(SeoTask::STATUS_PENDING)->setPriority(SeoTask::PRIORITY_HIGH)
            ->setMaxAttempts(3)
            ->setPayloadArray(['website_id' => $websiteId, 'module' => $module, 'resource_event_id' => $eventId]);
        $task->save();
        return (int)$task->getId();
    }

    public function refresh(int $websiteId, string $module = ''): array
    {
        $errors = [];
        foreach ($this->registry->getUrlProviders(true) as $provider) {
            if ($module !== '' && $provider->getModule() !== $module) {
                continue;
            }
            $ids = array_map('intval', $provider->getWebsiteIds());
            if ($ids !== [] && !in_array($websiteId, $ids, true)) {
                continue;
            }
            $stats = $this->sync->syncProviderWebsite($provider, $websiteId);
            if ((int)($stats['errors'] ?? 0) > 0) {
                $errors = array_merge($errors, $stats['error_messages'] ?? ['Sitemap provider sync failed']);
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'error' => true, 'message' => implode('; ', $errors)];
        }
        return $this->sitemaps->generateSitemapFiles($websiteId);
    }

    /** Website IDs whose provider data could not be refreshed. */
    public static function failedWebsiteIds(array $stats): array
    {
        $ids = [];
        foreach ($stats['providers'] ?? [] as $provider) {
            foreach ($provider['websites'] ?? [] as $website) {
                if (!empty($website['errors']) && isset($website['website_id'])) {
                    $ids[] = (int)$website['website_id'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    public function process(SeoTask $task): bool
    {
        try {
            $payload = $task->getPayloadArray();
            $result = $this->refresh((int)($payload['website_id'] ?? -1), (string)($payload['module'] ?? ''));
            if (empty($result['success'])) {
                $task->markError((string)($result['message'] ?? 'Sitemap generation failed'));
                return false;
            }
            $task->markDone(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return true;
        } catch (\Throwable $exception) {
            $task->markError($exception->getMessage());
            return false;
        }
    }
}
