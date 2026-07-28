<?php

declare(strict_types=1);

namespace Weline\Captcha\Interface;

interface VerificationProviderInterface
{
    public function code(): string;

    /** @param array<string, mixed> $context */
    public function render(array $context): string;

    /** @param array<string, mixed> $submission */
    public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool;
}
