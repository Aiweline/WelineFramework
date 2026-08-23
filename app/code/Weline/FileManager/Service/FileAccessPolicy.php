<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Exception\FileAccessDeniedException;
use Weline\FileManager\Api\FileAccessPolicyInterface;
use Weline\FileManager\Model\FileAsset;

final class FileAccessPolicy implements FileAccessPolicyInterface
{
    private const MAX_POLICY_LIST_ITEMS = 256;

    public function assertCanRead(FileAsset $asset, FileAccessContext $context): void
    {
        if ($asset->isDeleted()) {
            throw new \RuntimeException((string)__('文件资源不可用。'));
        }
        if (!$asset->isReady() && !in_array($context->purpose, ['media_manager', 'metadata_edit'], true)) {
            throw new \RuntimeException((string)__('文件资源尚未完成元数据审核。'));
        }
        if ($asset->getVisibility() !== FileAsset::VISIBILITY_PRIVATE) {
            return;
        }
        $this->assertPrivateAccess($asset, $context);
    }

    public function assertCanManage(FileAsset $asset, FileAccessContext $context): void
    {
        if (!in_array($context->purpose, ['media_manager', 'metadata_edit'], true)) {
            throw new FileAccessDeniedException((string)__('当前访问上下文不允许管理私有文件资源。'));
        }
        if ($asset->isDeleted() && $asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
            $this->assertPrivateAccess($asset, $context);
            return;
        }
        $this->assertCanRead($asset, $context);
    }

    private function assertPrivateAccess(FileAsset $asset, FileAccessContext $context): void
    {
        if (($context->actorId ?? 0) < 1) {
            throw new FileAccessDeniedException((string)__('当前上下文无权读取私有文件资源。'));
        }

        $policy = $this->privatePolicy($asset);
        $requiredRevision = max(1, (int)($policy['policy_revision'] ?? 1));
        if ($context->policyRevision < $requiredRevision) {
            throw new FileAccessDeniedException((string)__('文件访问策略版本已更新，请刷新访问上下文。'));
        }

        $ownerActorId = (int)($policy['owner_actor_id'] ?? 0);
        $allowedActors = $this->positiveIntegers($policy['allowed_actor_ids'] ?? []);
        if (($ownerActorId > 0 || $allowedActors !== [])
            && $ownerActorId !== $context->actorId
            && !in_array($context->actorId, $allowedActors, true)
        ) {
            throw new FileAccessDeniedException((string)__('当前上下文无权读取私有文件资源。'));
        }
        $allowedScopes = $this->strings($policy['allowed_scope_keys'] ?? []);
        if ($allowedScopes !== [] && !in_array($context->scope->canonicalKey(), $allowedScopes, true)) {
            throw new FileAccessDeniedException((string)__('当前 Scope 无权读取私有文件资源。'));
        }
        $allowedRoles = $this->strings($policy['allowed_roles'] ?? []);
        if ($allowedRoles !== [] && array_intersect($allowedRoles, $this->strings($context->roles)) === []) {
            throw new FileAccessDeniedException((string)__('当前角色无权读取私有文件资源。'));
        }
    }

    /** @return array<string,mixed> */
    private function privatePolicy(FileAsset $asset): array
    {
        $raw = trim((string)$asset->getData(FileAsset::schema_fields_METADATA));
        if ($raw === '') {
            throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
        }
        try {
            $metadata = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
        }
        if (!is_array($metadata)) {
            throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
        }
        $policy = $metadata['access_policy'] ?? $metadata;
        if (!is_array($policy)) {
            throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
        }
        foreach (['allowed_actor_ids', 'allowed_scope_keys', 'allowed_roles'] as $listKey) {
            if (array_key_exists($listKey, $policy)
                && (!is_array($policy[$listKey]) || !array_is_list($policy[$listKey]))
            ) {
                throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
            }
            if (count($policy[$listKey] ?? []) > self::MAX_POLICY_LIST_ITEMS) {
                throw new FileAccessDeniedException((string)__('私有文件访问策略列表超过限制。'));
            }
        }
        if (array_key_exists('owner_actor_id', $policy)
            && !$this->isPositiveInteger($policy['owner_actor_id'])
        ) {
            throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
        }
        if (array_key_exists('policy_revision', $policy)
            && !$this->isPositiveInteger($policy['policy_revision'])
        ) {
            throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
        }
        foreach ($policy['allowed_actor_ids'] ?? [] as $actorId) {
            if (!$this->isPositiveInteger($actorId)) {
                throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
            }
        }
        foreach ($policy['allowed_scope_keys'] ?? [] as $scopeKey) {
            if (!$this->isPolicyString($scopeKey, 512)) {
                throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
            }
        }
        foreach ($policy['allowed_roles'] ?? [] as $role) {
            if (!$this->isPolicyString($role, 128)) {
                throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
            }
        }
        if (
            (int)($policy['owner_actor_id'] ?? 0) < 1
            && $this->positiveIntegers($policy['allowed_actor_ids'] ?? []) === []
            && $this->strings($policy['allowed_scope_keys'] ?? []) === []
            && $this->strings($policy['allowed_roles'] ?? []) === []
        ) {
            throw new FileAccessDeniedException((string)__('私有文件访问策略无效。'));
        }
        return $policy;
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '' && strlen($value) <= 512 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                $normalized[$value] = true;
            }
        }
        return array_keys($normalized);
    }

    /** @return list<int> */
    private function positiveIntegers(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $normalized = [];
        foreach ($values as $value) {
            $id = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : 0;
            if ($id > 0) {
                $normalized[$id] = true;
            }
        }
        return array_map('intval', array_keys($normalized));
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int)$value > 0;
    }

    private function isPolicyString(mixed $value, int $maxBytes): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && strlen($value) <= $maxBytes
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
