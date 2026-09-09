<?php

declare(strict_types=1);

namespace Weline\B2B\Api;

/** Internal Cart/Checkout port for server-owned B2B quote revalidation. */
interface B2BCheckoutRecheckInterface
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function issueQuote(array $request): array;

    /**
     * Issue one quote token per cart line; returns quote_set_id + tokens.
     *
     * @param list<array<string,mixed>> $lineRequests
     * @return array<string,mixed>
     */
    public function issueQuoteSet(array $lineRequests): array;

    /** @return array<string,mixed> */
    public function submit(
        string $tokenId,
        string $customerId,
        int $websiteId,
        string $orderRef,
    ): array;

    /**
     * Atomically recheck and consume all tokens in a set; any drift fails the whole order.
     *
     * @param list<string> $tokenIds
     * @return array<string,mixed>
     */
    public function submitQuoteSet(
        array $tokenIds,
        string $customerId,
        int $websiteId,
        string $orderRef,
    ): array;

    /** @return array<string,mixed>|null */
    public function readSnapshot(
        string $orderRef,
        string $customerId,
        int $websiteId,
    ): ?array;
}
