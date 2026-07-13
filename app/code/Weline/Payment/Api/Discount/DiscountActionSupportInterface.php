<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Discount;

interface DiscountActionSupportInterface
{
    /**
     * @return array<string, array{code:string,name:string,description:string,form_fields:array<array-key, mixed>}>
     */
    public function getAllDiscountActions(): array;

    public function checkSupport(string $paymentMethodCode, string $actionCode): bool;

    /** @return list<string> */
    public function getSupportedActions(string $paymentMethodCode): array;

    /**
     * @param list<string> $actionCodes
     * @return list<string>
     */
    public function validateActions(string $paymentMethodCode, array $actionCodes): array;
}
