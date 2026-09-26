<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Version;

/**
 * Theme-owned publication surface for create/save/seal/publish/selectHistory.
 * Task 1 declares the contract; Task 3 implements transactional ordering.
 *
 * @phpstan-type PublicationResult array<string, mixed>
 */
interface ThemeVersionPublicationInterface
{
    public const CREATION_CONTINUE_CURRENT = 'continue_current';
    public const CREATION_EXPLICIT_HISTORICAL = 'explicit_historical';
    public const CREATION_PACKAGE_DEFAULTS = 'package_defaults';

    public const CREATION_SOURCES = [
        self::CREATION_CONTINUE_CURRENT,
        self::CREATION_EXPLICIT_HISTORICAL,
        self::CREATION_PACKAGE_DEFAULTS,
    ];

    /**
     * @param array<string, mixed> $options
     * @return PublicationResult
     */
    public function createDraft(ThemeVersionIdentity $owner, string $creationSourceKind, array $options = []): array;

    /**
     * @param array<string, mixed> $changes
     * @return PublicationResult
     */
    public function saveDraft(ThemeVersionIdentity $identity, array $changes, int $expectedContentRevision): array;

    /**
     * Seal D as immutable N=D without changing published selection.
     *
     * @param array<string, mixed> $options
     * @return PublicationResult
     */
    public function seal(ThemeVersionIdentity $identity, array $options = []): array;

    /**
     * Publish draft or select an existing sealed history version.
     *
     * @param array<string, mixed> $options
     * @return PublicationResult
     */
    public function publish(ThemeVersionIdentity $identity, array $options = []): array;

    /**
     * Switch formal selection to an existing sealed history version without rewriting it.
     *
     * @param array<string, mixed> $options
     * @return PublicationResult
     */
    public function selectHistory(ThemeVersionIdentity $identity, array $options = []): array;
}
