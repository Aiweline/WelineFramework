<?php

declare(strict_types=1);

namespace Weline\Theme\Api;

use Weline\Framework\Session\Auth\AuthenticableInterface;

/**
 * Optional account-module bridge used by authenticated theme previews.
 *
 * Theme owns the preview intent and credentials, while the installed account
 * module owns account persistence and login metadata.
 */
interface PreviewAccountProviderInterface
{
    public function ensurePreviewAccount(
        string $username,
        string $email,
        string $plainPassword,
    ): ?AuthenticableInterface;

    public function findPreviewAccountId(string $username): int|string|null;

    public function recordPreviewLogin(
        AuthenticableInterface $account,
        string $sessionId,
        string $remoteAddress,
    ): void;
}
