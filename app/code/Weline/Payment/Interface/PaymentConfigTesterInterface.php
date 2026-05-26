<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

interface PaymentConfigTesterInterface
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     * @return array{success: bool, message?: string, details?: array<string, mixed>}
     */
    public function testConfig(array $config, array $context = []): array;
}
