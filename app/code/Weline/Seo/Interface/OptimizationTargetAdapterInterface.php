<?php

declare(strict_types=1);

namespace Weline\Seo\Interface;

/** Cross-module optimization port; content ownership remains in the adapter module. */
interface OptimizationTargetAdapterInterface
{
    public function getCode(): string;
    public function supports(int $websiteId): bool;
    /** @return list<array<string,mixed>> */
    public function targets(int $websiteId): array;
    /** @param array<string,mixed> $target @return array<string,mixed> */
    public function snapshot(int $websiteId, array $target): array;
    /**
     * Applies an exact owner CAS. For block HTML-affecting fields, the adapter
     * must render the complete candidate owner before reporting applied=true.
     * Metadata-only owners may skip rendering. A failed render must not admit publish.
     *
     * @param array<string,mixed> $request @return array<string,mixed>
     */
    public function apply(array $request): array;
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function rollback(array $request): array;
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function finalize(array $request): array;
    /**
     * Admits an idempotent publish and returns published only after its queue is done.
     * Pending, processing and failed receipts remain observable without mutating a
     * different owner.
     *
     * @param array<string,mixed> $request @return array<string,mixed>
     */
    public function admitPublish(array $request): array;
}
