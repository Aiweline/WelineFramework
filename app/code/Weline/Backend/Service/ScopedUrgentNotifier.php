<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Scoped urgent 写入口：始终落审计行；仅向 ACL 授权用户广播。
 */
final class ScopedUrgentNotifier
{
    public function __construct(
        private readonly ScopedNotificationRecipientResolver $recipientResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<int>|null $notifyUsers null=按 ACL 解析；显式数组覆盖（可空=仅审计）
     */
    public function emit(
        string $topic,
        string $title,
        string $content,
        ScopeIdentity $scope,
        string $dedupeKey,
        array $metadata = [],
        ?array $notifyUsers = null,
        string $icon = 'warning',
    ): void {
        if ($scope->isGlobal()) {
            throw new \InvalidArgumentException('Scoped urgent 禁止 global Scope；无 Scope 事件请用普通 w_msg');
        }

        $users = $notifyUsers;
        if ($users === null) {
            $users = $this->recipientResolver->resolveUserIds($scope);
        } else {
            $users = \array_values(\array_unique(\array_filter(
                \array_map(static fn ($id): int => (int)$id, $users),
                static fn (int $id): bool => $id > 0,
            )));
        }

        $scopeHash = ScopedNotificationWriter::hashScopeIdentity($scope);
        $meta = \array_merge($metadata, [
            'scoped' => true,
            'require_authorized_recipients' => true,
            'scope_kind' => $scope->scopeKind,
            'scope_identity' => $scope->toArray(),
            'scope_hash' => $scopeHash,
            'dedupe_key' => $dedupeKey,
        ]);

        if (!\function_exists('w_msg')) {
            return;
        }

        w_msg($topic, 'urgent', $title, $content, [
            'icon' => $icon,
            'notify_users' => $users,
            'scope_hash' => $scopeHash,
            'scope' => $scope->toLegacyScopeString(),
            'dedupe_key' => $dedupeKey,
            'metadata' => $meta,
            'source_module' => (string)($metadata['source_module'] ?? ''),
        ]);
    }

    /** 当前请求 Scope；未冻结时回退默认站 website_id=0。 */
    public static function resolveRequestOrDefaultWebsiteScope(): ScopeIdentity
    {
        $identity = RequestContext::scopeIdentity();
        if ($identity instanceof ScopeIdentity && !$identity->isGlobal()) {
            return $identity;
        }

        // website_id=0 / code=default 是系统默认站，合法非 Global。
        return ScopeIdentity::website(0, 'default');
    }
}
