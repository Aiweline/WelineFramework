<?php

declare(strict_types=1);

namespace Weline\Captcha\Api;

interface CaptchaManagerInterface
{
    /**
     * Render the active provider challenge for a framework form.
     *
     * @param array<string, mixed> $context
     */
    public function renderChallenge(array $context): string;

    /**
     * Verify a browser submission and consume its one-time proof.
     *
     * @param array<string, mixed> $submission
     */
    public function verifySubmission(array $submission, string $intent, string $hostname, ?string $ip = null): bool;
}
