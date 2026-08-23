<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Model\SystemNotification;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * P1B-004-NOTIFY：规范化 Scope topic / dedupe，并做库级 occurrence upsert。
 */
final class ScopedNotificationWriter
{
    public function __construct(
        private readonly SystemNotification $notificationModel,
    ) {
    }

    /**
     * @param array<string, mixed> $data w_msg event data
     * @return array{
     *   notification: SystemNotification,
     *   created: bool,
     *   require_explicit_recipients: bool,
     *   notify_users: list<int>,
     *   suppress_broadcast: bool
     * }
     */
    public function write(array $data): array
    {
        $topic = \trim((string)($data['topic'] ?? 'system_info'));
        $type = \trim((string)($data['type'] ?? 'info'));
        $title = \trim((string)($data['title'] ?? ''));
        $content = (string)($data['content'] ?? '');
        $metadata = \is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $scopeHash = $this->resolveScopeHash($data, $metadata);
        $scopeString = $this->resolveScopeString($data, $metadata, $scopeHash);
        $dedupeKey = $this->resolveDedupeKey($data, $metadata);
        $notifyUsers = $this->normalizeUserIds($data['notify_users'] ?? []);
        $requireExplicit = $this->requiresExplicitRecipients($type, $scopeHash, $metadata);
        $suppressBroadcast = $requireExplicit && $notifyUsers === [];

        $now = \date('Y-m-d H:i:s');
        $existing = null;
        if ($dedupeKey !== '') {
            $existing = $this->findExisting($topic, $scopeHash, $dedupeKey);
        }

        if ($existing instanceof SystemNotification && (int)$existing->getId() > 0) {
            $count = $existing->getOccurrenceCount() + 1;
            $meta = $existing->getMetadata();
            $meta['last_title'] = $title;
            $meta['last_content_excerpt'] = \mb_substr(\strip_tags($content), 0, 240);
            $meta['occurrence_count'] = $count;
            $existing->setTitle($title)
                ->setContent($content)
                ->setType($type)
                ->setPriority((int)($data['priority'] ?? $existing->getPriority()))
                ->setMetadata($meta)
                ->setOccurrenceCount($count)
                ->setLastOccurrenceAt($now)
                ->save();

            return [
                'notification' => $existing,
                'created' => false,
                'require_explicit_recipients' => $requireExplicit,
                'notify_users' => $notifyUsers,
                'suppress_broadcast' => $suppressBroadcast,
            ];
        }

        $notification = clone $this->notificationModel;
        $notification->clearQuery()
            ->setTopicCode($topic)
            ->setType($type)
            ->setTitle($title)
            ->setContent($content)
            ->setPriority((int)($data['priority'] ?? 5))
            ->setSourceModule((string)($data['source_module'] ?? ''))
            ->setMetadata($metadata)
            ->setIsIcon((bool)($data['is_icon'] ?? true))
            ->setIsImg((bool)($data['is_img'] ?? false))
            ->setAvatar((string)($data['avatar'] ?? 'bell'))
            ->setExternalNotified(false)
            ->setExternalChannels([])
            ->setScope($scopeString)
            ->setScopeHash($scopeHash)
            ->setDedupeKey($dedupeKey === '' ? null : $dedupeKey)
            ->setOccurrenceCount(1)
            ->setLastOccurrenceAt($now);
        $notification->save();

        return [
            'notification' => $notification,
            'created' => true,
            'require_explicit_recipients' => $requireExplicit,
            'notify_users' => $notifyUsers,
            'suppress_broadcast' => $suppressBroadcast,
        ];
    }

    public static function hashScopeIdentity(ScopeIdentity $identity): string
    {
        return \hash('sha256', $identity->canonicalKey());
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    public function requiresExplicitRecipients(string $type, string $scopeHash, array $metadata): bool
    {
        if (!empty($metadata['require_authorized_recipients']) || !empty($metadata['scoped'])) {
            return true;
        }
        if ($type === 'urgent' && $scopeHash !== SystemNotification::SCOPE_HASH_GLOBAL) {
            return true;
        }

        return $type === 'urgent' && !empty($metadata['scope_kind']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    private function resolveScopeHash(array $data, array $metadata): string
    {
        $explicit = \trim((string)($data['scope_hash'] ?? $metadata['scope_hash'] ?? ''));
        if ($explicit !== '') {
            return \strlen($explicit) > 64 ? \substr($explicit, 0, 64) : $explicit;
        }
        if (isset($metadata['scope_identity']) && \is_array($metadata['scope_identity'])) {
            try {
                return self::hashScopeIdentity(ScopeIdentity::fromArray($metadata['scope_identity']));
            } catch (\Throwable) {
                return SystemNotification::SCOPE_HASH_GLOBAL;
            }
        }

        return SystemNotification::SCOPE_HASH_GLOBAL;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    private function resolveScopeString(array $data, array $metadata, string $scopeHash): string
    {
        $explicit = \trim((string)($data['scope'] ?? $metadata['scope'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return $scopeHash === SystemNotification::SCOPE_HASH_GLOBAL ? '' : $scopeHash;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    private function resolveDedupeKey(array $data, array $metadata): string
    {
        $key = \trim((string)($data['dedupe_key'] ?? $metadata['dedupe_key'] ?? ''));
        if ($key === '') {
            return '';
        }

        return \strlen($key) > 191 ? \substr($key, 0, 191) : $key;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function normalizeUserIds(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $userId = (int)$id;
            if ($userId > 0) {
                $out[] = $userId;
            }
        }

        return \array_values(\array_unique($out));
    }

    private function findExisting(string $topic, string $scopeHash, string $dedupeKey): ?SystemNotification
    {
        $row = clone $this->notificationModel;
        $row->clearData()->reset()
            ->where(SystemNotification::schema_fields_topic_code, $topic)
            ->where(SystemNotification::schema_fields_SCOPE_HASH, $scopeHash)
            ->where(SystemNotification::schema_fields_dedupe_key, $dedupeKey)
            ->find()
            ->fetch();
        if ((int)$row->getId() <= 0) {
            return null;
        }

        return $row;
    }
}
