<?php

declare(strict_types=1);

namespace Weline\Framework\Event\ResourceChange;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Async\ContextSnapshot;

final class ResourceChangeFactory
{
    public function __construct(private readonly ContextSnapshot $contextSnapshot)
    {
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed>|null $after
     * @param list<string> $changedFields
     * @param array{namespaces?:list<string>,previous_namespaces?:list<string>,urls?:list<string>,previous_urls?:list<string>} $impact
     * @param array<string,mixed> $origin
     */
    public function create(
        string $resourceType,
        string|int $resourceId,
        string $action,
        int $revision,
        int $websiteId,
        string $websiteCode,
        array $before,
        ?array $after,
        array $changedFields,
        array $impact,
        array $origin = [],
        ?string $previousWebsiteCode = null,
        int $siteId = 0,
    ): ResourceChange {
        $websiteCode = trim($websiteCode);
        $previousWebsiteCode = $previousWebsiteCode === null
            ? null
            : trim($previousWebsiteCode);
        $context = $this->contextSnapshot->capture($websiteId, $websiteCode);
        $triggerId = WelineEnv::get('user.id', null);
        $origin += [
            'area' => WelineEnv::getArea(),
            'entry' => '',
            'request_id' => (string)WelineEnv::get('request.id', ''),
            'instance' => (string)WelineEnv::get('wls.instance_name', ''),
            'trigger_by' => [
                'type' => WelineEnv::getArea() === 'backend' ? 'admin' : 'system',
                'id' => $triggerId === null ? null : (int)$triggerId,
            ],
        ];
        $changedFields = $this->stringList($changedFields);
        sort($changedFields, SORT_STRING);
        $normalizedImpact = [];
        foreach (['namespaces', 'previous_namespaces', 'urls', 'previous_urls'] as $key) {
            $normalizedImpact[$key] = $this->stringList((array)($impact[$key] ?? []));
            sort($normalizedImpact[$key], SORT_STRING);
        }

        return ResourceChange::fromArray([
            'schema_version' => ResourceChange::SCHEMA_VERSION,
            'event_id' => bin2hex(random_bytes(16)),
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => $this->utcMicrotime(),
            'resource' => [
                'type' => strtolower(trim($resourceType)),
                'id' => (string)$resourceId,
                'action' => $action,
                'revision' => $revision,
            ],
            'website' => [
                'id' => $websiteId,
                'code' => $websiteCode,
                'previous_code' => $previousWebsiteCode,
                'site_id' => $siteId,
            ],
            'impact' => $normalizedImpact,
            'changed_fields' => $changedFields,
            'before' => $before,
            'after' => $after,
            'origin' => $origin,
            'context' => $context,
        ]);
    }

    /** @return list<string> */
    private function stringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $result[$value] = $value;
            }
        }
        return array_values($result);
    }

    private function utcMicrotime(): string
    {
        $now = microtime(true);
        $seconds = (int)$now;
        $micros = (int)round(($now - $seconds) * 1_000_000);
        if ($micros >= 1_000_000) {
            $seconds++;
            $micros = 0;
        }
        return gmdate('Y-m-d\TH:i:s', $seconds) . '.' . sprintf('%06d', $micros) . 'Z';
    }
}
