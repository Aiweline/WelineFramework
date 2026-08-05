<?php

declare(strict_types=1);

namespace Weline\Consent\Api;

interface ConsentRepositoryInterface
{
    /**
     * @return list<array{code:string,name:string,required:bool}>
     */
    public function categories(): array;

    public function grant(int $websiteId, string $visitorKey, string $categoryCode): void;

    public function withdraw(int $websiteId, string $visitorKey, string $categoryCode): bool;

    public function isGranted(int $websiteId, string $visitorKey, string $categoryCode): bool;

    /**
     * @return list<array<string,mixed>>
     */
    public function listForWebsite(int $websiteId): array;

    /**
     * @return list<array<string,mixed>>
     */
    public function auditForWebsite(int $websiteId): array;
}
