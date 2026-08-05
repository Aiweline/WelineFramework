<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async\Admin;

use Weline\Framework\Event\Async\AsyncErrorRedactor;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

final class DeliveryAccessPolicy
{
    public const ACL_VIEW = 'Weline_Framework::event_delivery_view';
    public const ACL_REPLAY = 'Weline_Framework::event_delivery_replay';
    public const ACL_ALL_WEBSITES = 'Weline_Framework::event_delivery_all_websites';

    public function __construct(
        private readonly AsyncErrorRedactor $errorRedactor,
    ) {
    }

    /** @return array{user_id:int,role_id:int,username:string} */
    public function requireActor(): array
    {
        $session = SessionFactory::getInstance()->createBackendSession();
        if (!$session->isLoggedIn()) {
            throw new \RuntimeException((string)__('请先登录后台'));
        }
        $userId = (int)($session->getUserId() ?? 0);
        if ($userId < 1) {
            throw new \RuntimeException((string)__('当前后台用户身份无效'));
        }

        $roleId = (int)($session->getSession()->get('backend_acl_role_id') ?? 0);
        if ($roleId < 1) {
            $user = $session->getUser();
            if ($user !== null && method_exists($user, 'getRole')) {
                $role = $user->getRole();
                if (is_object($role) && method_exists($role, 'getRoleId')) {
                    $roleId = (int)($role->getRoleId() ?: 0);
                } elseif (is_object($role) && method_exists($role, 'getId')) {
                    $roleId = (int)($role->getId() ?: 0);
                }
            }
        }
        if ($userId === 1 && $roleId < 1) {
            $roleId = 1;
        }
        if ($roleId < 1) {
            throw new \RuntimeException((string)__('当前后台用户未分配有效角色'));
        }

        return [
            'user_id' => $userId,
            'role_id' => $roleId,
            'username' => (string)($session->getUsername() ?? ''),
        ];
    }

    /** @return array{user_id:int,role_id:int,username:string} */
    public function requirePermission(string $sourceId): array
    {
        $actor = $this->requireActor();
        if (!$this->isAllowed($actor['role_id'], $sourceId)) {
            throw new \RuntimeException((string)__('你无权执行当前异步事件运维操作'));
        }
        return $actor;
    }

    /** @return array{view:bool,replay:bool,all_websites:bool,current_website_id:?int} */
    public function permissions(): array
    {
        $actor = $this->requireActor();
        return [
            'view' => $this->isAllowed($actor['role_id'], self::ACL_VIEW),
            'replay' => $this->isAllowed($actor['role_id'], self::ACL_REPLAY),
            'all_websites' => $this->isAllowed($actor['role_id'], self::ACL_ALL_WEBSITES),
            'current_website_id' => w_env_website_id(),
        ];
    }

    /**
     * null means the explicitly authorized all-websites scope.
     *
     * @param array<string,mixed> $params
     */
    public function resolveListWebsite(array $params): ?int
    {
        $this->requirePermission(self::ACL_VIEW);
        $scope = strtolower(trim((string)($params['scope'] ?? 'current')));
        if ($scope === 'all') {
            $this->requirePermission(self::ACL_ALL_WEBSITES);
            return null;
        }
        if ($scope !== 'current') {
            throw new \InvalidArgumentException((string)__('scope 只允许 current 或 all'));
        }
        return $this->resolveWebsite($params);
    }

    /** @param array<string,mixed> $params */
    public function resolveWebsite(array $params): int
    {
        $currentWebsiteId = w_env_website_id();
        if (!array_key_exists('website_id', $params) || $params['website_id'] === null || $params['website_id'] === '') {
            if ($currentWebsiteId === null) {
                throw new \RuntimeException((string)__('当前后台上下文无法确定网站范围'));
            }
            return $currentWebsiteId;
        }

        $websiteId = $this->nonNegativeInteger($params['website_id'], 'website_id');
        if ($currentWebsiteId === null || $websiteId !== $currentWebsiteId) {
            $this->requirePermission(self::ACL_ALL_WEBSITES);
        }
        return $websiteId;
    }

    /** @param array<string,mixed> $payload */
    public function websiteFromPayload(array $payload): int
    {
        $website = $payload['website'] ?? null;
        if (!is_array($website) || !array_key_exists('id', $website)) {
            throw new \RuntimeException((string)__('Delivery 载荷缺少网站范围'));
        }
        return $this->nonNegativeInteger($website['id'], 'payload.website.id');
    }

    /** @param array<string,mixed> $payload */
    public function assertPayloadWebsite(array $payload, int $expectedWebsiteId): void
    {
        if ($this->websiteFromPayload($payload) !== $expectedWebsiteId) {
            throw new \RuntimeException((string)__('Delivery 不存在或不属于当前网站范围'));
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function redactedPayload(array $payload): array
    {
        $result = [];
        foreach (['schema_version', 'event_id', 'event_name', 'occurred_at'] as $key) {
            if (array_key_exists($key, $payload)) {
                $result[$key] = $this->redactValue($payload[$key], $key, 0);
            }
        }
        foreach (['resource', 'website', 'impact', 'changed_fields', 'before', 'after'] as $key) {
            if (array_key_exists($key, $payload)) {
                $result[$key] = $this->redactValue($payload[$key], $key, 0);
            }
        }
        if (is_array($payload['origin'] ?? null)) {
            $origin = $payload['origin'];
            $result['origin'] = [];
            foreach (['area', 'entry', 'request_id', 'instance', 'trigger_by', 'replay'] as $key) {
                if (array_key_exists($key, $origin)) {
                    $result['origin'][$key] = $this->redactValue($origin[$key], 'origin.' . $key, 0);
                }
            }
        }
        return $result;
    }

    public function escapedError(mixed $error): string
    {
        return htmlspecialchars(
            $this->errorRedactor->redact((string)$error, 8192),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }

    private function isAllowed(int $roleId, string $sourceId): bool
    {
        if ($roleId === 1) {
            return true;
        }
        $class = 'Weline\\Acl\\Service\\AclService';
        if (!class_exists($class)) {
            throw new \RuntimeException((string)__('ACL 服务当前不可用'));
        }
        $service = ObjectManager::getInstance($class);
        if (!is_object($service) || !method_exists($service, 'getRoleAclEntries')) {
            throw new \RuntimeException((string)__('ACL 服务契约无效'));
        }
        foreach ((array)$service->getRoleAclEntries($roleId) as $entry) {
            if (is_array($entry) && (string)($entry['source_id'] ?? '') === $sourceId) {
                return true;
            }
            if (is_object($entry) && method_exists($entry, 'getData')
                && (string)$entry->getData('source_id') === $sourceId) {
                return true;
            }
        }
        return false;
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
            $integer = (int)$value;
        } else {
            throw new \InvalidArgumentException((string)__('%{1} 必须是非负整数', [$label]));
        }
        if ($integer < 0) {
            throw new \InvalidArgumentException((string)__('%{1} 必须是非负整数', [$label]));
        }
        return $integer;
    }

    private function redactValue(mixed $value, string $path, int $depth): mixed
    {
        if ($depth > 8) {
            return '[redacted-depth]';
        }
        if (is_string($value)) {
            $value = substr($value, 0, 2048);
            return str_contains(strtolower($path), 'url') ? $this->stripUrlSecrets($value) : $value;
        }
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : '[redacted-type]';
        }

        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 100) {
                $result['_truncated'] = true;
                break;
            }
            $keyString = (string)$key;
            if (preg_match('/(?:password|passwd|secret|token|cookie|session|csrf|authorization|credential|private[_-]?key|api[_-]?key|access[_-]?key|signature)/i', $keyString)) {
                $result[$key] = '[redacted]';
                continue;
            }
            $result[$key] = $this->redactValue($item, $path . '.' . $keyString, $depth + 1);
        }
        return $result;
    }

    private function stripUrlSecrets(string $url): string
    {
        $withoutFragment = explode('#', $url, 2)[0];
        $withoutQuery = explode('?', $withoutFragment, 2)[0];
        $parts = parse_url($withoutQuery);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $withoutQuery;
        }
        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) . '://' : '//';
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . $host . $port . (string)($parts['path'] ?? '');
    }
}
