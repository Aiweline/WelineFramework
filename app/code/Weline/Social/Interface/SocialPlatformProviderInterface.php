<?php

declare(strict_types=1);

namespace Weline\Social\Interface;

interface SocialPlatformProviderInterface
{
    public function getPlatformCode(): string;

    /**
     * @return array<string, mixed>
     */
    public function getDefinition(): array;

    /**
     * @param array<string, mixed> $accountContext
     */
    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string;

    /**
     * @param array<string, mixed> $callbackData
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handleAuthorizationCallback(array $callbackData, array $context = []): array;

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $account
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function publish(array $draft, array $account, array $context = []): array;

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function queryPublishStatus(string $remoteId, array $account, array $context = []): array;

    /**
     * Browser CSP sources this platform needs (OAuth popup / JS SDK / embed).
     * Collected by Social Extends into Framework app defaults.
     *
     * @return array<string, list<string>> directive => absolute https hosts / keywords
     */
    public function cspDirectives(): array;
}

