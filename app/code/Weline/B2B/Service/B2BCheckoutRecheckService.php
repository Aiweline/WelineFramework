<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Api\B2BCheckoutRecheckInterface;
use Weline\B2B\Model\B2BOrderPriceSnapshot;
use Weline\B2B\Model\B2BQuoteToken;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/** Server-owned quote recheck and atomic immutable Order snapshot boundary. */
final class B2BCheckoutRecheckService implements B2BCheckoutRecheckInterface
{
    public const DEFAULT_QUOTE_TTL_SECONDS = 900;

    public const ERROR_MODE_OFF_QUOTE = 'b2b_mode_off_blocks_b2b_quote';
    public const ERROR_QUOTE_NOT_FOUND = 'b2b_quote_not_found';
    public const ERROR_QUOTE_NOT_OPEN = 'b2b_quote_not_open';
    public const ERROR_QUOTE_EXPIRED = 'b2b_quote_expired';
    public const ERROR_QUOTE_VERSION_CONFLICT = 'b2b_quote_version_conflict';
    public const ERROR_CANDIDATE_REJECTED = 'b2b_submit_candidate_rejected';
    public const ERROR_CONCURRENT_SUBMIT = 'b2b_quote_concurrent_submit';
    public const ERROR_ORDER_REF_INVALID = 'b2b_order_ref_invalid';
    public const ERROR_SNAPSHOT_NOT_FOUND = 'b2b_order_snapshot_not_found';

    /** @var list<array{order_ref:?string,outcome:string,token_id:?string}> */
    private array $submitAttempts = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly B2BPriceEngine $engine,
        private readonly B2BQuoteTokenStore $quotes,
        private readonly B2BOrderSnapshotStore $snapshots,
        private readonly B2BAclGuard $acl,
        ?callable $clock = null,
        private readonly int $quoteTtlSeconds = self::DEFAULT_QUOTE_TTL_SECONDS,
    ) {
        if ($quoteTtlSeconds < 1 || $quoteTtlSeconds > 86400) {
            throw new \InvalidArgumentException(__('B2B quote TTL 非法'));
        }
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn (): int => time();
    }

    public static function forTesting(
        ?B2BPriceEngine $engine = null,
        ?callable $clock = null,
        int $quoteTtlSeconds = self::DEFAULT_QUOTE_TTL_SECONDS,
    ): self {
        $engine ??= B2BPriceEngine::forTesting();
        return new self(
            $engine,
            B2BQuoteTokenStore::forTesting(),
            B2BOrderSnapshotStore::forTesting(),
            new B2BAclGuard($engine->groups()),
            $clock,
            $quoteTtlSeconds,
        );
    }

    public function engine(): B2BPriceEngine
    {
        return $this->engine;
    }

    public function quotes(): B2BQuoteTokenStore
    {
        return $this->quotes;
    }

    public function snapshots(): B2BOrderSnapshotStore
    {
        return $this->snapshots;
    }

    /** @return list<array{order_ref:?string,outcome:string,token_id:?string}> */
    public function submitAttempts(): array
    {
        return $this->submitAttempts;
    }

    public function acceptedOrderCount(): int
    {
        return $this->snapshots->count();
    }

    /**
     * @param array{
     *   customer_id:string,
     *   website_id:int,
     *   sku:string,
     *   retail_amount_minor:int,
     *   channel_id?:string|null
     * } $request
     * @return array{ok:bool,token:?array<string,mixed>,candidate:array<string,mixed>,error?:string}
     */
    public function issueQuote(array $request): array
    {
        $mode = $this->engine->rollout()->mode(B2BPriceEngine::CAPABILITY);
        $candidate = $this->engine->resolve($request);

        if ($mode === CommerceRolloutGateInterface::MODE_OFF) {
            $token = $this->mintToken($request, $candidate, retailOnly: true);
            return [
                'ok' => true,
                'token' => $token->toArray(),
                'candidate' => $candidate,
                'error' => self::ERROR_MODE_OFF_QUOTE,
            ];
        }

        if (!($candidate['ok'] ?? false)) {
            return [
                'ok' => false,
                'token' => null,
                'candidate' => $candidate,
                'error' => (string)($candidate['error'] ?? self::ERROR_CANDIDATE_REJECTED),
            ];
        }

        if (($candidate['price_list_id'] ?? null) !== null) {
            $this->acl->assertGroupMembership(
                (string)$request['customer_id'],
                (int)$request['website_id'],
                isset($candidate['group_id']) ? (string)$candidate['group_id'] : null,
            );
        }

        $token = $this->mintToken($request, $candidate, retailOnly: false);
        return ['ok' => true, 'token' => $token->toArray(), 'candidate' => $candidate];
    }

    /**
     * @return array{
     *   ok:bool,
     *   order_ref:?string,
     *   snapshot:?array<string,mixed>,
     *   error?:string,
     *   current_version?:int|null,
     *   quoted_version?:int|null
     * }
     */
    public function submit(
        string $tokenId,
        string $customerId,
        int $websiteId,
        string $orderRef,
    ): array {
        $orderRef = trim($orderRef);
        if ($orderRef === '' || strlen($orderRef) > 64) {
            return $this->reject($tokenId, self::ERROR_ORDER_REF_INVALID);
        }

        $token = $this->quotes->get($tokenId);
        if ($token === null) {
            return $this->reject($tokenId, self::ERROR_QUOTE_NOT_FOUND);
        }

        try {
            $this->acl->assertCustomerOwnsQuote($customerId, $token->customerId);
            $this->acl->assertWebsiteOwnsQuote($websiteId, $token->websiteId);
            if ($token->groupId !== null) {
                $this->acl->assertGroupMembership($customerId, $websiteId, $token->groupId);
            }
        } catch (B2BConflictException $exception) {
            return $this->reject(
                $tokenId,
                $exception->errorCode,
                $token->version,
                null,
            );
        }

        if ($token->status() !== B2BQuoteToken::STATUS_OPEN) {
            return $this->reject($tokenId, self::ERROR_QUOTE_NOT_OPEN);
        }
        $nowEpoch = ($this->clock)();
        if ($token->isExpired($nowEpoch)) {
            return $this->reject($tokenId, self::ERROR_QUOTE_EXPIRED);
        }

        $fresh = $this->engine->resolve([
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'channel_id' => $token->channelId,
            'sku' => $token->sku,
            'retail_amount_minor' => $token->retailAmountMinor,
            'claimed_price_list_id' => $token->priceListId,
            'claimed_version' => $token->version,
        ]);

        if (!$this->matches($token, $fresh)) {
            return $this->reject(
                $tokenId,
                self::ERROR_QUOTE_VERSION_CONFLICT,
                $token->version,
                isset($fresh['version']) ? (int)$fresh['version'] : null,
            );
        }

        $snapshot = $this->snapshot($orderRef, $token, $nowEpoch);
        try {
            $result = $this->quotes->consumeWith(
                $tokenId,
                $orderRef,
                $nowEpoch,
                function (B2BQuoteToken $_token) use ($snapshot): void {
                    $this->snapshots->put($snapshot);
                },
            );
        } catch (B2BConflictException $exception) {
            return $this->reject($tokenId, $exception->errorCode);
        }

        if ($result !== B2BQuoteTokenStore::RESULT_CONSUMED) {
            $error = match ($result) {
                B2BQuoteTokenStore::RESULT_NOT_FOUND => self::ERROR_QUOTE_NOT_FOUND,
                B2BQuoteTokenStore::RESULT_EXPIRED => self::ERROR_QUOTE_EXPIRED,
                default => self::ERROR_CONCURRENT_SUBMIT,
            };
            return $this->reject($tokenId, $error);
        }

        $this->submitAttempts[] = [
            'order_ref' => $orderRef,
            'outcome' => 'accepted',
            'token_id' => $tokenId,
        ];
        return [
            'ok' => true,
            'order_ref' => $orderRef,
            'snapshot' => $snapshot->toArray(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function readSnapshot(
        string $orderRef,
        string $customerId,
        int $websiteId,
    ): ?array {
        $snapshot = $this->snapshots->get($orderRef);
        if ($snapshot === null) {
            return null;
        }
        $this->acl->assertCustomerOwnsQuote($customerId, $snapshot->customerId);
        $this->acl->assertWebsiteOwnsQuote($websiteId, $snapshot->websiteId);
        return $snapshot->toArray();
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $candidate
     */
    private function mintToken(array $request, array $candidate, bool $retailOnly): B2BQuoteToken
    {
        $tokenId = 'qt_' . bin2hex(random_bytes(16));
        $groupId = $retailOnly ? null : $this->optionalString($candidate, 'group_id');
        $listId = $retailOnly ? null : $this->optionalString($candidate, 'price_list_id');
        $version = $retailOnly || !isset($candidate['version']) ? null : (int)$candidate['version'];
        $retailAmount = (int)($request['retail_amount_minor'] ?? -1);
        $amount = (int)($candidate['amount_minor'] ?? $retailAmount);
        $source = $retailOnly
            ? B2BPriceEngine::SOURCE_RETAIL
            : (string)($candidate['source'] ?? B2BPriceEngine::SOURCE_RETAIL);
        $channelId = $this->optionalString($request, 'channel_id');
        $stack = is_array($candidate['rule_stack'] ?? null)
            ? array_values($candidate['rule_stack'])
            : [];
        $issuedAt = ($this->clock)();
        $expiresAt = $issuedAt + $this->quoteTtlSeconds;
        $facts = [
            'token_id' => $tokenId,
            'customer_id' => (string)$request['customer_id'],
            'website_id' => (int)($request['website_id'] ?? -1),
            'sku' => (string)$request['sku'],
            'retail_amount_minor' => $retailAmount,
            'amount_minor' => $amount,
            'source' => $source,
            'group_id' => $groupId,
            'price_list_id' => $listId,
            'version' => $version,
            'channel_id' => $channelId,
            'rule_stack' => $stack,
            'issued_at_epoch' => $issuedAt,
            'expires_at_epoch' => $expiresAt,
        ];
        $token = new B2BQuoteToken(
            tokenId: $tokenId,
            customerId: (string)$facts['customer_id'],
            websiteId: (int)$facts['website_id'],
            sku: (string)$facts['sku'],
            retailAmountMinor: $retailAmount,
            amountMinor: $amount,
            source: $source,
            groupId: $groupId,
            priceListId: $listId,
            version: $version,
            channelId: $channelId,
            ruleStack: $stack,
            fingerprint: B2BQuoteToken::calculateFingerprint($facts),
            issuedAtEpoch: $issuedAt,
            expiresAtEpoch: $expiresAt,
        );
        $this->quotes->put($token);
        return $token;
    }

    /** @param array<string,mixed> $fresh */
    private function matches(B2BQuoteToken $token, array $fresh): bool
    {
        if (!($fresh['ok'] ?? false)) {
            return false;
        }
        $freshSource = (string)($fresh['source'] ?? '');
        if ($freshSource === B2BPriceEngine::SOURCE_CLOSED) {
            $freshSource = B2BPriceEngine::SOURCE_RETAIL;
        }
        return $freshSource === $token->source
            && (int)($fresh['amount_minor'] ?? -1) === $token->amountMinor
            && $this->optionalString($fresh, 'price_list_id') === $token->priceListId
            && (isset($fresh['version']) ? (int)$fresh['version'] : null) === $token->version
            && $this->optionalString($fresh, 'group_id') === $token->groupId;
    }

    private function snapshot(
        string $orderRef,
        B2BQuoteToken $token,
        int $createdAtEpoch,
    ): B2BOrderPriceSnapshot {
        $facts = [
            'order_ref' => $orderRef,
            'token_id' => $token->tokenId,
            'customer_id' => $token->customerId,
            'website_id' => $token->websiteId,
            'sku' => $token->sku,
            'retail_amount_minor' => $token->retailAmountMinor,
            'amount_minor' => $token->amountMinor,
            'source' => $token->source,
            'group_id' => $token->groupId,
            'price_list_id' => $token->priceListId,
            'version' => $token->version,
            'channel_id' => $token->channelId,
            'rule_stack' => $token->ruleStack,
            'created_at_epoch' => $createdAtEpoch,
        ];
        return new B2BOrderPriceSnapshot(
            orderRef: $orderRef,
            tokenId: $token->tokenId,
            customerId: $token->customerId,
            websiteId: $token->websiteId,
            sku: $token->sku,
            retailAmountMinor: $token->retailAmountMinor,
            amountMinor: $token->amountMinor,
            source: $token->source,
            groupId: $token->groupId,
            priceListId: $token->priceListId,
            version: $token->version,
            channelId: $token->channelId,
            ruleStack: $token->ruleStack,
            hash: B2BOrderPriceSnapshot::calculateHash($facts),
            createdAtEpoch: $createdAtEpoch,
        );
    }

    /**
     * @return array{
     *   ok:false,order_ref:null,snapshot:null,error:string,
     *   current_version?:int|null,quoted_version?:int|null
     * }
     */
    private function reject(
        ?string $tokenId,
        string $error,
        ?int $quotedVersion = null,
        ?int $currentVersion = null,
    ): array {
        $this->submitAttempts[] = [
            'order_ref' => null,
            'outcome' => 'rejected',
            'token_id' => $tokenId,
        ];
        $result = [
            'ok' => false,
            'order_ref' => null,
            'snapshot' => null,
            'error' => $error,
        ];
        if ($quotedVersion !== null || $currentVersion !== null) {
            $result['current_version'] = $currentVersion;
            $result['quoted_version'] = $quotedVersion;
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    private function optionalString(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;
        return $value !== null && $value !== '' ? (string)$value : null;
    }
}
