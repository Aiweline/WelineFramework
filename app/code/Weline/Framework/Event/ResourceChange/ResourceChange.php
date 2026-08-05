<?php

declare(strict_types=1);

namespace Weline\Framework\Event\ResourceChange;

use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;

final readonly class ResourceChange
{
    public const EVENT_NAME = 'Weline_Framework::resource_changed';
    public const SCHEMA_VERSION = 1;
    private const ACTIONS = ['upsert', 'delete', 'publish', 'unpublish'];

    /** @param array<string,mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        self::validateArray($data);
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function eventId(): string
    {
        return (string)$this->data['event_id'];
    }

    public function resourceType(): string
    {
        return (string)$this->data['resource']['type'];
    }

    public function resourceId(): string
    {
        return (string)$this->data['resource']['id'];
    }

    public function resourceKey(): string
    {
        return hash('sha256', $this->resourceType() . "\0" . $this->resourceId());
    }

    public function revision(): int
    {
        return (int)$this->data['resource']['revision'];
    }

    public function action(): string
    {
        return (string)$this->data['resource']['action'];
    }

    public function websiteId(): int
    {
        return (int)$this->data['website']['id'];
    }

    public function websiteCode(): string
    {
        return (string)$this->data['website']['code'];
    }

    public function coalesceKey(): string
    {
        return $this->resourceType() . ':' . $this->resourceId();
    }

    /** @param array<string,mixed> $data */
    private static function validateArray(array $data): void
    {
        $required = ['schema_version', 'event_id', 'event_name', 'occurred_at', 'resource', 'website', 'impact', 'changed_fields', 'before', 'after', 'origin', 'context'];
        self::assertObjectKeys($data, $required, ['coalesced_event_ids'], 'resource_change');
        if (!is_int($data['schema_version'])
            || $data['schema_version'] !== self::SCHEMA_VERSION
            || !is_string($data['event_name'])
            || $data['event_name'] !== self::EVENT_NAME) {
            throw new AsyncEventValidationException(__('资源变更事件名或 schema 版本无效'));
        }
        if (!is_string($data['event_id']) || !preg_match('/^[a-f0-9]{32}$/D', $data['event_id'])) {
            throw new AsyncEventValidationException(__('资源变更 event_id 必须是 32 位小写十六进制字符'));
        }
        if (!is_string($data['occurred_at'])
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $data['occurred_at']) !== 1
            || \DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $data['occurred_at'],
                new \DateTimeZone('UTC'),
            ) === false) {
            throw new AsyncEventValidationException(__('资源变更 occurred_at 必须是 UTC 微秒时间'));
        }
        if (!is_array($data['resource'])) {
            throw new AsyncEventValidationException(__('资源变更 resource 必须是数组'));
        }
        $resource = $data['resource'];
        self::assertObjectKeys($resource, ['type', 'id', 'action', 'revision'], [], 'resource');
        $type = $resource['type'];
        $id = $resource['id'];
        $action = $resource['action'];
        $revision = $resource['revision'];
        if (!is_string($type) || !is_string($id) || !is_string($action) || !is_int($revision)) {
            throw new AsyncEventValidationException(__('资源变更 resource 字段类型无效'));
        }
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $type) || $id === '' || strlen($id) > 191) {
            throw new AsyncEventValidationException(__('资源变更资源身份无效'));
        }
        if (!in_array($action, self::ACTIONS, true) || $revision < 1) {
            throw new AsyncEventValidationException(__('资源变更 action 或 revision 无效'));
        }
        if ($action === 'delete' && $data['after'] !== null) {
            throw new AsyncEventValidationException(__('删除资源变更的 after 必须为 null'));
        }
        if (!is_array($data['website'])) {
            throw new AsyncEventValidationException(__('资源变更 website 上下文无效'));
        }
        $website = $data['website'];
        self::assertObjectKeys($website, ['id', 'code', 'previous_code', 'site_id'], [], 'website');
        if (!is_int($website['id'])
            || $website['id'] < 0
            || !is_string($website['code'])
            || trim($website['code']) === ''
            || strlen($website['code']) > 64
            || (!is_null($website['previous_code']) && !is_string($website['previous_code']))
            || !is_int($website['site_id'])
            || $website['site_id'] < 0) {
            throw new AsyncEventValidationException(__('资源变更 website 字段类型或值无效'));
        }
        if (!is_array($data['impact'])) {
            throw new AsyncEventValidationException(__('资源变更 impact 必须是数组'));
        }
        self::assertObjectKeys(
            $data['impact'],
            ['namespaces', 'previous_namespaces', 'urls', 'previous_urls'],
            [],
            'impact',
        );
        foreach (['namespaces', 'previous_namespaces', 'urls', 'previous_urls'] as $key) {
            self::assertStringList($data['impact'][$key], 'impact.' . $key);
        }
        self::assertStringList($data['changed_fields'], 'changed_fields');
        if (array_key_exists('coalesced_event_ids', $data)) {
            self::assertStringList($data['coalesced_event_ids'], 'coalesced_event_ids');
            foreach ($data['coalesced_event_ids'] as $eventId) {
                if (preg_match('/^[a-f0-9]{32}$/D', $eventId) !== 1) {
                    throw new AsyncEventValidationException(__('资源变更 coalesced_event_ids 含无效 event_id'));
                }
            }
        }
        if (!is_array($data['before']) || ($data['after'] !== null && !is_array($data['after']))) {
            throw new AsyncEventValidationException(__('资源变更 before/after 快照类型无效'));
        }
        if ($action !== 'delete' && !is_array($data['after'])) {
            throw new AsyncEventValidationException(__('非删除资源变更的 after 必须是数组'));
        }

        if (!is_array($data['origin'])) {
            throw new AsyncEventValidationException(__('资源变更 origin 必须是数组'));
        }
        $origin = $data['origin'];
        self::assertObjectKeys(
            $origin,
            ['area', 'entry', 'request_id', 'instance', 'trigger_by'],
            ['replay'],
            'origin',
        );
        foreach (['area', 'entry', 'request_id', 'instance'] as $key) {
            if (!is_string($origin[$key]) || strlen($origin[$key]) > 512) {
                throw new AsyncEventValidationException(__('资源变更 origin.%{1} 无效', [$key]));
            }
        }
        if (!is_array($origin['trigger_by'])) {
            throw new AsyncEventValidationException(__('资源变更 origin.trigger_by 必须是对象'));
        }
        self::assertObjectKeys($origin['trigger_by'], ['type', 'id'], [], 'origin.trigger_by');
        if (!in_array($origin['trigger_by']['type'], ['admin', 'customer', 'system'], true)
            || (($origin['trigger_by']['id'] ?? null) !== null
                && (!is_int($origin['trigger_by']['id']) || $origin['trigger_by']['id'] < 0))) {
            throw new AsyncEventValidationException(__('资源变更 origin.trigger_by 无效'));
        }
        if (array_key_exists('replay', $origin) && !is_array($origin['replay'])) {
            throw new AsyncEventValidationException(__('资源变更 origin.replay 必须是对象'));
        }

        if (!is_array($data['context'])) {
            throw new AsyncEventValidationException(__('资源变更 context 必须是数组'));
        }
        $context = $data['context'];
        self::assertObjectKeys(
            $context,
            ['website_id', 'website_code', 'lang', 'currency', 'area', 'timezone', 'user'],
            [],
            'context',
        );
        if (!is_int($context['website_id'])
            || !is_string($context['website_code'])
            || $context['website_id'] !== $website['id']
            || $context['website_code'] !== $website['code']) {
            throw new AsyncEventValidationException(__('资源变更 context 与目标 website 不一致'));
        }
        foreach (['lang', 'currency', 'area', 'timezone'] as $key) {
            if (!is_string($context[$key]) || $context[$key] === '' || strlen($context[$key]) > 128) {
                throw new AsyncEventValidationException(__('资源变更 context.%{1} 无效', [$key]));
            }
        }
        if (!is_array($context['user'])) {
            throw new AsyncEventValidationException(__('资源变更 context.user 必须是对象'));
        }
        self::assertObjectKeys($context['user'], ['type', 'id'], [], 'context.user');
        if (!in_array($context['user']['type'], ['admin', 'customer', 'system'], true)
            || (($context['user']['id'] ?? null) !== null
                && (!is_int($context['user']['id']) || $context['user']['id'] < 0))) {
            throw new AsyncEventValidationException(__('资源变更 context.user 无效'));
        }
    }

    /** @param array<string,mixed> $value @param list<string> $required @param list<string> $optional */
    private static function assertObjectKeys(array $value, array $required, array $optional, string $label): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new AsyncEventValidationException(__('%{1} 缺少字段：%{2}', [$label, $key]));
            }
        }
        $unknown = array_diff(array_keys($value), array_merge($required, $optional));
        if ($unknown !== []) {
            throw new AsyncEventValidationException(__('%{1} 包含非白名单字段', [$label]));
        }
    }

    private static function assertStringList(mixed $value, string $label): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new AsyncEventValidationException(__('%{1} 必须是列表', [$label]));
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '' || strlen($item) > 2048) {
                throw new AsyncEventValidationException(__('%{1} 必须只包含非空字符串', [$label]));
            }
        }
    }
}
