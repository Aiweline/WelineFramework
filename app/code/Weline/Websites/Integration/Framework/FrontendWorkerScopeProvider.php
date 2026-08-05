<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\Framework;

use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeRolloutDecision;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ScopeKernelRolloutPolicy;
use Weline\Websites\Service\ScopeTokenService;
use Weline\Websites\Service\Value\ScopeTokenVerification;

/**
 * Websites-owned bridge between verified Scope Tokens and Framework Workers.
 */
final class FrontendWorkerScopeProvider implements FrontendWorkerScopeProviderInterface
{
    private const CURRENT_CONTEXT_VERSION = 'v1';

    public function __construct(
        private readonly ScopeKernelRolloutPolicy $rolloutPolicy,
        private readonly ScopeTokenService $tokenService,
        private readonly StoreCatalogInterface $storeCatalog,
        private readonly SalesChannelCatalogInterface $channelCatalog,
        private readonly Website $website,
    ) {
    }

    public function requiresBinding(string $requestScheme): bool
    {
        return $this->rolloutPolicy->requiresBinding($requestScheme);
    }

    public function rollout(
        ScopeIdentity $scope,
        string $requestScheme,
    ): FrontendWorkerScopeRolloutDecision {
        $off = $this->rolloutPolicy->offDecisionOrNull();
        if ($off instanceof FrontendWorkerScopeRolloutDecision) {
            return $off;
        }

        $resolved = $this->resolveTrustedScope($scope);
        return $this->rolloutPolicy->decide(
            $resolved['scope']->websiteId,
            $resolved['store']->id,
            $resolved['channel']->id,
            $resolved['store']->storeMode,
            $requestScheme,
        );
    }

    public function issueToken(
        ScopeIdentity $trustedScope,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?string {
        $off = $this->rolloutPolicy->offDecisionOrNull();
        if ($off instanceof FrontendWorkerScopeRolloutDecision) {
            // Deliberately return before ScopeTokenService touches its keyring.
            return null;
        }

        $resolved = $this->resolveTrustedScope($trustedScope);
        $decision = $this->rolloutPolicy->decide(
            $resolved['scope']->websiteId,
            $resolved['store']->id,
            $resolved['channel']->id,
            $resolved['store']->storeMode,
            $requestScheme,
        );
        if (!$decision->tokenEnabled) {
            return null;
        }

        try {
            return $this->tokenService->issue($resolved['scope'], $authorityHost, $now);
        } catch (FrontendWorkerScopeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FrontendWorkerScopeException(
                'scope_token_issue_unavailable',
                503,
                (string)__('Scope Token 暂时无法签发'),
                $exception,
            );
        }
    }

    public function verifyToken(
        string $token,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?FrontendWorkerScopeBinding {
        $off = $this->rolloutPolicy->offDecisionOrNull();
        if ($off instanceof FrontendWorkerScopeRolloutDecision) {
            // Off must not read, stat, decode, or otherwise touch the keyring.
            return null;
        }

        $verification = $this->tokenService->verifyCandidate($token, $authorityHost, $now);
        if (!$verification->isValid()
            || !$verification->scope instanceof ScopeIdentity
            || $verification->host === null
            || $verification->issuedAt === null
            || $verification->expiresAt === null) {
            throw $this->verificationFailure($verification);
        }

        $resolved = $this->resolveTrustedScope($verification->scope);
        $decision = $this->rolloutPolicy->decide(
            $resolved['scope']->websiteId,
            $resolved['store']->id,
            $resolved['channel']->id,
            $resolved['store']->storeMode,
            $requestScheme,
        );
        if (!$decision->tokenEnabled) {
            return null;
        }

        return new FrontendWorkerScopeBinding(
            $resolved['scope'],
            $verification->host,
            \hash('sha256', $token),
            $verification->issuedAt,
            $verification->expiresAt,
            $decision->isAuthoritative(),
        );
    }

    public function restoreBinding(
        ?FrontendWorkerScopeBinding $binding,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?ScopeIdentity {
        if (!$binding instanceof FrontendWorkerScopeBinding) {
            if ($this->rolloutPolicy->requiresBinding($requestScheme)) {
                throw new FrontendWorkerScopeException(
                    'scope_binding_required',
                    401,
                    (string)__('当前前台操作必须使用绑定商城 Scope 的 Worker Session'),
                );
            }
            return null;
        }

        $off = $this->rolloutPolicy->offDecisionOrNull();
        if ($off instanceof FrontendWorkerScopeRolloutDecision) {
            return null;
        }

        $now ??= \time();
        if ($now < 1
            || $binding->tokenIssuedAt > $now + ScopeTokenService::CLOCK_SKEW_SECONDS
            || $binding->tokenExpiresAt <= $now
            || $binding->tokenExpiresAt - $binding->tokenIssuedAt !== ScopeTokenService::TTL_SECONDS) {
            throw new FrontendWorkerScopeException(
                'worker_scope_binding_expired',
                401,
                (string)__('Worker Scope 绑定已过期或时间窗口无效'),
            );
        }
        $this->assertBindingAuthority($binding, $authorityHost);

        $resolved = $this->resolveTrustedScope($binding->scope);
        $decision = $this->rolloutPolicy->decide(
            $resolved['scope']->websiteId,
            $resolved['store']->id,
            $resolved['channel']->id,
            $resolved['store']->storeMode,
            $requestScheme,
        );
        if (!$decision->isAuthoritative()) {
            if ($decision->mode === FrontendWorkerScopeRolloutDecision::MODE_ALLOWLIST
                && $binding->authoritativeAtIssue) {
                throw new FrontendWorkerScopeException(
                    'scope_authority_revoked',
                    409,
                    (string)__('当前商城 Scope 的切流授权已撤销，请重新加载页面'),
                );
            }
            // Shadow compares the binding but never makes it the request fact.
            return null;
        }

        $this->installTrustedScope(
            $resolved['scope'],
            $resolved['store'],
            $resolved['channel'],
            $resolved['website'],
            $binding,
        );
        return $resolved['scope'];
    }

    /**
     * @return array{
     *     scope:ScopeIdentity,
     *     store:StoreSummary,
     *     channel:SalesChannelSummary,
     *     website:Website
     * }
     */
    private function resolveTrustedScope(ScopeIdentity $candidate): array
    {
        if ($candidate->scopeKind !== ScopeIdentity::KIND_CHANNEL
            || $candidate->websiteId === null
            || $candidate->websiteCode === null
            || $candidate->storeCode === null
            || $candidate->channelCode === null
            || $candidate->storeMode === null) {
            throw new FrontendWorkerScopeException(
                'scope_identity_incomplete',
                409,
                (string)__('Worker Scope 必须是完整 Channel Scope'),
            );
        }

        $website = $this->loadWebsite($candidate->websiteId, $candidate->websiteCode);
        try {
            $store = $this->storeCatalog->byCode($candidate->websiteId, $candidate->storeCode);
        } catch (\Throwable $exception) {
            throw $this->catalogUnavailable($exception);
        }
        if (!$store instanceof StoreSummary
            || $store->websiteId !== $candidate->websiteId
            || !\hash_equals($store->code, $candidate->storeCode)
            || !\hash_equals($store->storeMode, $candidate->storeMode)) {
            throw $this->catalogConflict('store_catalog_conflict');
        }
        if ($store->lifecycleStatus === Store::LIFECYCLE_TOMBSTONE) {
            throw new FrontendWorkerScopeException(
                'store_tombstoned',
                410,
                (string)__('Worker Scope 指向的店铺已进入墓碑生命周期'),
            );
        }
        if (!$store->enabled
            || $store->lifecycleStatus !== Store::LIFECYCLE_ACTIVE
            || $store->tombstonedAt !== null) {
            throw new FrontendWorkerScopeException(
                'store_unavailable',
                503,
                (string)__('Worker Scope 指向的店铺当前不可用'),
            );
        }

        try {
            $channel = $this->channelCatalog->byCode($store->id, $candidate->channelCode);
        } catch (\Throwable $exception) {
            throw $this->catalogUnavailable($exception);
        }
        if (!$channel instanceof SalesChannelSummary
            || $channel->websiteId !== $candidate->websiteId
            || $channel->storeId !== $store->id
            || !\hash_equals($channel->code, $candidate->channelCode)) {
            throw $this->catalogConflict('channel_catalog_conflict');
        }
        if (!$channel->enabled
            || !$channel->effectiveEnabled
            || $channel->parentStoreLifecycleStatus !== Store::LIFECYCLE_ACTIVE) {
            throw new FrontendWorkerScopeException(
                'sales_channel_unavailable',
                503,
                (string)__('Worker Scope 指向的销售渠道当前不可用'),
            );
        }

        $trusted = ScopeIdentity::channel(
            $candidate->websiteId,
            (string)$website->getData(Website::schema_fields_CODE),
            $store->code,
            $channel->code,
            $store->storeMode,
            self::CURRENT_CONTEXT_VERSION,
        );
        if (!$trusted->equals($candidate)) {
            throw $this->catalogConflict('scope_context_version_conflict');
        }

        return [
            'scope' => $trusted,
            'store' => $store,
            'channel' => $channel,
            'website' => $website,
        ];
    }

    private function loadWebsite(int $websiteId, string $websiteCode): Website
    {
        try {
            $website = clone $this->website;
            $rows = $website->clearQuery()
                ->clearData()
                ->where(Website::schema_fields_ID, $websiteId)
                ->select()
                ->fetchArray();
        } catch (\Throwable $exception) {
            throw $this->catalogUnavailable($exception);
        }
        if (!\is_array($rows) || \count($rows) !== 1 || !\is_array($rows[0])) {
            throw $this->catalogConflict('website_catalog_conflict');
        }

        $row = $rows[0];
        $actualId = $row[Website::schema_fields_ID] ?? null;
        if (\is_string($actualId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $actualId) === 1) {
            $actualId = (int)$actualId;
        }
        $actualCode = $row[Website::schema_fields_CODE] ?? null;
        if (!\is_int($actualId)
            || $actualId !== $websiteId
            || !\is_string($actualCode)
            || !\hash_equals($actualCode, $websiteCode)) {
            throw $this->catalogConflict('website_catalog_conflict');
        }

        return $website->clearQuery()->clearData()->setData($row);
    }

    private function assertBindingAuthority(
        FrontendWorkerScopeBinding $binding,
        string $authorityHost,
    ): void {
        $candidate = \strtolower(\trim($authorityHost));
        try {
            // Reuse the binding's canonical authority invariant without adding
            // a second Host parser in Websites.
            $probe = new FrontendWorkerScopeBinding(
                $binding->scope,
                $candidate,
                $binding->tokenFingerprint,
                $binding->tokenIssuedAt,
                $binding->tokenExpiresAt,
                $binding->authoritativeAtIssue,
            );
        } catch (\Throwable $exception) {
            throw new FrontendWorkerScopeException(
                'worker_scope_authority_invalid',
                400,
                (string)__('Worker Scope 请求 Host 无效'),
                $exception,
            );
        }
        if (!\hash_equals($binding->authorityHost, $probe->authorityHost)) {
            throw new FrontendWorkerScopeException(
                'worker_scope_authority_conflict',
                409,
                (string)__('Worker Scope 绑定与当前 Host 不一致'),
            );
        }
    }

    private function installTrustedScope(
        ScopeIdentity $scope,
        StoreSummary $store,
        SalesChannelSummary $channel,
        Website $website,
        FrontendWorkerScopeBinding $binding,
    ): void {
        $existing = RequestContext::scopeIdentity();
        if ($existing instanceof ScopeIdentity && !$existing->equals($scope)) {
            try {
                RequestContext::replaceScopeIdentityForTrustedWorker(
                    $binding,
                    $store->id,
                    $channel->id,
                );
            } catch (\Throwable $exception) {
                throw new FrontendWorkerScopeException(
                    'request_scope_already_conflicts',
                    409,
                    (string)__('当前请求已冻结为其他商城 Scope'),
                    $exception,
                );
            }
        }

        try {
            RequestContext::setWelineStoreId($store->id);
            RequestContext::setWelineChannelId($channel->id);
            RequestContext::setWelineWebsiteUrl((string)$website->getData(Website::schema_fields_URL));
            RequestContext::installScopeIdentity($scope);
            ScopeContext::setScope($scope->toLegacyScopeString());
            WebsiteData::setWebsite($website);
        } catch (FrontendWorkerScopeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FrontendWorkerScopeException(
                'request_scope_install_failed',
                500,
                (string)__('Worker Scope 无法安装到当前请求'),
                $exception,
            );
        }
    }

    private function verificationFailure(
        ScopeTokenVerification $verification,
    ): FrontendWorkerScopeException {
        return match ($verification->status) {
            ScopeTokenVerification::STATUS_EXPIRED => new FrontendWorkerScopeException(
                'scope_token_expired',
                401,
                (string)__('Scope Token 已过期，请重新加载页面'),
            ),
            ScopeTokenVerification::STATUS_CONTEXT_CONFLICT => new FrontendWorkerScopeException(
                'scope_token_context_conflict',
                409,
                (string)__('Scope Token 与当前请求上下文冲突'),
            ),
            ScopeTokenVerification::STATUS_SERVICE_UNAVAILABLE => new FrontendWorkerScopeException(
                'scope_token_service_unavailable',
                503,
                (string)__('Scope Token 验证服务暂不可用'),
            ),
            default => new FrontendWorkerScopeException(
                'scope_token_invalid',
                401,
                (string)__('Scope Token 无效'),
            ),
        };
    }

    private function catalogConflict(string $reason): FrontendWorkerScopeException
    {
        return new FrontendWorkerScopeException(
            $reason,
            409,
            (string)__('Scope Token 与当前 Website/Store/Channel 事实不一致'),
        );
    }

    private function catalogUnavailable(\Throwable $previous): FrontendWorkerScopeException
    {
        return new FrontendWorkerScopeException(
            'scope_catalog_unavailable',
            503,
            (string)__('商城 Scope 目录暂不可用'),
            $previous,
        );
    }
}
