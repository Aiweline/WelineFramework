<?php

declare(strict_types=1);

namespace Weline\B2B\Controller\Backend;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * B2B 后台作用范围：URL target_scope + 页头 <w:scope>（网站→店铺→渠道）。
 */
trait B2BBackendScopeTrait
{
    /**
     * @return array{
     *   kind:string,
     *   website_code:string,
     *   store_code:string,
     *   channel_code:string,
     *   storage_scope:string,
     *   filter_website_id:?int,
     *   filter_channel_id:?string,
     *   identity:ScopeIdentity
     * }
     */
    protected function assignB2bWorkScope(bool $fromPost = false): array
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $bag = $fromPost ? (array)$this->request->getPost() : (array)$this->request->getGet();
        $input = [
            'target_scope' => (string)($fromPost ? $this->request->getPost('target_scope', '') : $this->request->getGet('target_scope', '')),
            'scope' => (string)($fromPost ? $this->request->getPost('scope', '') : $this->request->getGet('scope', '')),
            'website_code' => (string)($fromPost ? $this->request->getPost('website_code', '') : $this->request->getGet('website_code', '')),
            'store_code' => (string)($fromPost ? $this->request->getPost('store_code', '') : $this->request->getGet('store_code', '')),
            'channel_code' => (string)($fromPost ? $this->request->getPost('channel_code', '') : $this->request->getGet('channel_code', '')),
            'scope_kind' => (string)($fromPost ? $this->request->getPost('scope_kind', '') : $this->request->getGet('scope_kind', '')),
        ];

        $hasExplicit = trim((string)$input['target_scope']) !== ''
            || trim((string)$input['scope']) !== ''
            || array_key_exists('website_code', $bag)
            || array_key_exists('scope_kind', $bag);

        // 兼容旧身份申请深链 ?website_id=N（无 target_scope 时）。
        $legacyWebsiteRaw = trim((string)($fromPost
            ? $this->request->getPost('website_id', '')
            : $this->request->getGet('website_id', '')));
        $legacyWebsiteId = (!$hasExplicit && $legacyWebsiteRaw !== '' && preg_match('/^\d+$/', $legacyWebsiteRaw) === 1)
            ? (int)$legacyWebsiteRaw
            : null;

        try {
            $resolved = $targetScopeService->resolveFromInput($input, !$hasExplicit && $legacyWebsiteId === null && !$fromPost);
        } catch (\Throwable) {
            $resolved = [
                'kind' => ScopeIdentity::KIND_GLOBAL,
                'website_code' => '',
                'store_code' => '',
                'channel_code' => '',
                'storage_scope' => 'default.default.default',
                'identity' => ScopeIdentity::global(),
            ];
        }

        /** @var ScopeIdentity $identity */
        $identity = $resolved['identity'] ?? ScopeIdentity::global();
        $kind = (string)($resolved['kind'] ?? $identity->scopeKind);
        $channelCode = strtolower(trim((string)($resolved['channel_code'] ?? '')));
        if ($channelCode === 'default') {
            $channelCode = '';
        }

        $filterWebsiteId = $legacyWebsiteId;
        if ($filterWebsiteId === null && $kind !== ScopeIdentity::KIND_GLOBAL && $identity->websiteId !== null) {
            $filterWebsiteId = (int)$identity->websiteId;
        }

        $filterChannelId = ($kind === ScopeIdentity::KIND_CHANNEL && $channelCode !== '')
            ? $channelCode
            : null;

        $storageScope = (string)($resolved['storage_scope'] ?? 'default.default.default');
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($resolved['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($resolved['store_code'] ?? ''));
        $this->assign('scope_channel_code', $channelCode);
        $this->assign('scope_kind', $kind);
        $this->assign('work_scope_id', $filterWebsiteId ?? 0);
        $this->assign('filter_website_id', $filterWebsiteId);
        $this->assign('filter_channel_id', $filterChannelId);

        return [
            'kind' => $kind,
            'website_code' => (string)($resolved['website_code'] ?? ''),
            'store_code' => (string)($resolved['store_code'] ?? ''),
            'channel_code' => $channelCode,
            'storage_scope' => $storageScope,
            'filter_website_id' => $filterWebsiteId,
            'filter_channel_id' => $filterChannelId,
            'identity' => $identity,
        ];
    }

    /**
     * @param array{storage_scope:string,website_code:string,store_code:string,channel_code:string,kind?:string} $target
     * @return array<string, string>
     */
    protected function b2bScopeQuery(array $target): array
    {
        return [
            'target_scope' => (string)$target['storage_scope'],
            'website_code' => (string)$target['website_code'],
            'store_code' => (string)$target['store_code'],
            'channel_code' => (string)$target['channel_code'],
            'scope_kind' => (string)($target['kind'] ?? ''),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    protected function filterRowsByB2bScope(array $rows, ?int $filterWebsiteId, ?string $filterChannelId): array
    {
        if ($filterWebsiteId === null && ($filterChannelId === null || $filterChannelId === '')) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ($filterWebsiteId !== null && \array_key_exists('website_id', $row)
                && (int)$row['website_id'] !== $filterWebsiteId) {
                continue;
            }
            if ($filterChannelId !== null && $filterChannelId !== '' && \array_key_exists('channel_id', $row)) {
                $rowChannel = trim((string)($row['channel_id'] ?? ''));
                // 渠道层：匹配同 channel；website 级空 channel 行仍可见（继承）。
                if ($rowChannel !== '' && strcasecmp($rowChannel, $filterChannelId) !== 0) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }
}
