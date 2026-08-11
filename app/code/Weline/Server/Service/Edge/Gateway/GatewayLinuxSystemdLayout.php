<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Owns the two-name Linux systemd unit layout used by Recovery Guardian.
 *
 * The mutable full unit is a regular file in /etc/weline-gateway.  The
 * systemd search path contains exactly one absolute symlink to it.  Keeping
 * the atomic-write target out of /etc/systemd/system lets the Guardian remain
 * sandboxed with ProtectSystem=strict while retaining a narrowly scoped
 * ReadWritePaths grant for the dedicated directory.
 */
final class GatewayLinuxSystemdLayout
{
    private const MAX_DEFINITION_BYTES = 1_048_576;
    private const MAX_LINK_STAGING_ENTRIES = 8;

    public function __construct(
        private readonly GatewayPaths $paths,
    ) {
    }

    /**
     * Publish a known schema-1 regular unit into the dedicated target, then
     * replace that exact regular unit with the canonical fixed symlink.  The
     * canonical path is preflighted before the target is touched, so a foreign
     * link/file cannot turn a repair into an overwrite primitive.
     */
    public function migrateExactLegacyDefinition(
        string $legacyDefinition,
        string $newDefinition,
    ): void {
        $this->ensureLegacyTargetPublished($legacyDefinition, $newDefinition);
        $this->ensureLegacyFixedLink($legacyDefinition, $newDefinition);
        $this->assertCurrentDefinitionAndFixedLink($newDefinition);
    }

    /**
     * Phase one of a crash-recoverable legacy migration.  A legacy regular
     * unit remains the active canonical name until the new target is durable.
     */
    public function ensureLegacyTargetPublished(
        string $legacyDefinition,
        string $newDefinition,
    ): void {
        $this->paths->ensureSystemdDefinitionDirectory();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->reconcileCanonicalLinkStaging();
        $linkState = $this->fixedLinkState();
        if ($linkState === 'foreign') {
            $this->assertExactLegacyRegular($legacyDefinition);
        } elseif ($linkState !== 'exact') {
            throw new \RuntimeException(
                'WLS Gateway legacy canonical systemd unit is missing or malformed.',
            );
        }
        $this->assertTargetCanContain($newDefinition);
        $this->publishTarget($newDefinition);
    }

    /**
     * Phase two of a crash-recoverable legacy migration.  It is idempotent:
     * a crash after rename finds the exact fixed link and leaves it intact.
     */
    public function ensureLegacyFixedLink(
        string $legacyDefinition,
        string $newDefinition,
    ): void {
        $this->paths->assertSystemdDefinitionDirectoryAuthority();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->reconcileCanonicalLinkStaging();
        $this->assertExactTarget($newDefinition);
        if ($this->fixedLinkState() === 'exact') {
            return;
        }
        $legacyStatus = $this->assertExactLegacyRegular($legacyDefinition);
        $this->replaceExactLegacyRegularWithFixedLink(
            $legacyStatus,
            $legacyDefinition,
        );
        $this->assertCurrentDefinitionAndFixedLink($newDefinition);
    }

    /**
     * Publish the target first and then create the canonical link.  This is
     * used for fresh installation and recovery of a transaction that crashed
     * after the target became durable but before metadata publication.
     */
    public function publishNewDefinitionAndFixedLink(string $definition): void
    {
        $this->paths->ensureSystemdDefinitionDirectory();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->reconcileCanonicalLinkStaging();
        $link = $this->paths->systemdServiceLinkFile();
        $linkState = $this->fixedLinkState();
        if ($linkState === 'foreign') {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link is foreign or malformed.',
            );
        }
        if ($linkState === 'missing'
            && (\file_exists($link) || \is_link($link))
        ) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link is indeterminate.',
            );
        }
        $this->assertTargetCanContain($definition);
        $this->publishTarget($definition);
        if ($linkState === 'missing') {
            $this->publishFixedLinkAfterTarget();
        }
        $this->assertCurrentDefinitionAndFixedLink($definition);
    }

    /**
     * Preflight used by the generic definition transaction before it writes
     * the mutable target.  A fresh transaction must never turn a foreign
     * canonical unit into a WLS link after it has already changed the target.
     */
    public function assertCanonicalLinkAvailableForDefinitionPublication(): void
    {
        $this->paths->assertSystemdDefinitionDirectoryAuthority();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->reconcileCanonicalLinkStaging();
        $link = $this->paths->systemdServiceLinkFile();
        $state = $this->fixedLinkState();
        if ($state === 'foreign'
            || ($state === 'missing'
                && (\file_exists($link) || \is_link($link)))
        ) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link is foreign or malformed.',
            );
        }
    }

    public function assertCurrentDefinitionAndFixedLink(
        string $definition,
    ): void {
        $this->paths->assertSystemdDefinitionDirectoryAuthority();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->assertExactTarget($definition);
        if ($this->fixedLinkState() !== 'exact') {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link does not point to its dedicated target.',
            );
        }
    }

    /**
     * The stop/disable boundary is owned by GatewayPlatformServiceInstaller.
     * Once it has proved that systemd no longer owns the unit, remove the
     * canonical link first, re-prove absence, then remove the mutable target.
     */
    public function removeCurrentDefinitionAndFixedLink(
        string $definition,
    ): void {
        $this->removeCurrentCanonicalFixedLink($definition);
        $this->removeCurrentTargetAfterFixedLink($definition);
        $this->assertCurrentDefinitionRemoved();
    }

    /**
     * First idempotent removal phase.  A durable platform-removal fence owned
     * by the caller is the authority for accepting an already absent link.
     */
    public function removeCurrentCanonicalFixedLink(string $definition): void
    {
        $this->paths->assertSystemdDefinitionDirectoryAuthority();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->reconcileCanonicalLinkStaging();
        $link = $this->paths->systemdServiceLinkFile();
        $state = $this->fixedLinkState();
        if ($state === 'foreign') {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link is foreign during removal.',
            );
        }
        $target = $this->paths->systemdServiceDefinitionFile();
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            $this->assertExactTarget($definition);
        } elseif (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'WLS Gateway dedicated systemd definition is indeterminate during removal.',
            );
        }
        if ($state === 'exact') {
            if (!\is_array($targetStatus)) {
                throw new \RuntimeException(
                    'WLS Gateway canonical link cannot outlive its exact removal target.',
                );
            }
            $linkStatus = $this->assertExactFixedLink();
            $beforeUnlink = @\lstat($link);
            if (!\is_array($beforeUnlink)
                || !$this->sameIdentity($linkStatus, $beforeUnlink)
                || !@\unlink($link)
            ) {
                throw new \RuntimeException(
                    'Unable to remove the exact WLS Gateway canonical systemd link.',
                );
            }
            GatewayProjectStateFilesystem::syncDirectory(\dirname($link));
        }
        $this->assertPathAbsent(
            $link,
            'WLS Gateway canonical systemd link remained after removal.',
        );
    }

    /** Second idempotent removal phase, valid only after the fixed link is gone. */
    public function removeCurrentTargetAfterFixedLink(string $definition): void
    {
        $this->paths->assertSystemdDefinitionDirectoryAuthority();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $link = $this->paths->systemdServiceLinkFile();
        if ($this->fixedLinkState() !== 'missing') {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link must be absent before target removal.',
            );
        }
        $this->assertPathAbsent(
            $link,
            'WLS Gateway canonical systemd link is indeterminate during target removal.',
        );
        $target = $this->paths->systemdServiceDefinitionFile();
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            $targetStatus = $this->assertExactTarget($definition);
            if (!GatewayProjectStateFilesystem::removeRegular(
                $target,
                'WLS Gateway dedicated systemd definition',
                $targetStatus,
            )) {
                throw new \RuntimeException(
                    'Unable to remove the WLS Gateway dedicated systemd definition.',
                );
            }
        } elseif (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'WLS Gateway dedicated systemd definition is indeterminate during removal.',
            );
        }
        $this->assertPathAbsent(
            $target,
            'WLS Gateway dedicated systemd definition remained after removal.',
        );
    }

    public function assertCurrentDefinitionRemoved(): void
    {
        $this->paths->assertSystemdDefinitionDirectoryAuthority();
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->assertPathAbsent(
            $this->paths->systemdServiceLinkFile(),
            'WLS Gateway canonical systemd link is not absent.',
        );
        $this->assertPathAbsent(
            $this->paths->systemdServiceDefinitionFile(),
            'WLS Gateway dedicated systemd definition is not absent.',
        );
    }

    /**
     * Removal compatibility for a verified schema-1 layout.  The caller has
     * already disabled/stopped systemd; only the exact WLS regular unit can
     * be removed, never a foreign canonical unit or symlink.
     */
    public function removeExactLegacyDefinition(string $definition): void
    {
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->reconcileCanonicalLinkStaging();
        $path = $this->paths->legacySystemdServiceDefinitionFile();
        $status = @\lstat($path);
        if (\is_array($status)) {
            $status = $this->assertExactLegacyRegular($definition);
            if (!GatewayProjectStateFilesystem::removeRegular(
                $path,
                'WLS Gateway legacy canonical systemd unit',
                $status,
            )) {
                throw new \RuntimeException(
                    'Unable to remove the WLS Gateway legacy canonical systemd unit.',
                );
            }
        } elseif (\file_exists($path) || \is_link($path)) {
            throw new \RuntimeException(
                'WLS Gateway legacy canonical systemd unit is indeterminate during removal.',
            );
        }
        $this->assertPathAbsent(
            $path,
            'WLS Gateway legacy canonical systemd unit remained after removal.',
        );
        $this->assertPathAbsent(
            $this->paths->systemdServiceDefinitionFile(),
            'WLS Gateway legacy removal found a dedicated target.',
        );
    }

    /**
     * Replay proof after the exact legacy definition and its metadata have
     * already been removed.  No content comparison is possible at this
     * terminal phase, so the durable caller-owned removal fence is the sole
     * authority for accepting the two-name negative state.
     */
    public function assertLegacyDefinitionRemoved(): void
    {
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->assertPathAbsent(
            $this->paths->legacySystemdServiceDefinitionFile(),
            'WLS Gateway legacy canonical systemd unit is not absent.',
        );
        $this->assertPathAbsent(
            $this->paths->systemdServiceDefinitionFile(),
            'WLS Gateway legacy removal found a dedicated target.',
        );
    }

    /**
     * Read-only proof used by status/remove compatibility before an explicit
     * schema-1 migration.  The legacy regular file is never treated as a
     * generic service definition merely because it has the expected name.
     */
    public function assertExactLegacyDefinition(string $definition): void
    {
        $this->paths->assertSystemdUnitLinkDirectoryAuthority();
        $this->assertExactLegacyRegular($definition);
    }

    /** @return array<string|int,mixed> */
    private function assertExactLegacyRegular(string $definition): array
    {
        $path = $this->paths->legacySystemdServiceDefinitionFile();
        $status = $this->assertExactRegular(
            $path,
            $definition,
            $this->definitionMode(),
            'WLS Gateway legacy canonical systemd unit',
        );
        if ($this->fixedLinkState() !== 'foreign') {
            throw new \RuntimeException(
                'WLS Gateway legacy canonical systemd unit is unexpectedly linked or absent.',
            );
        }
        return $status;
    }

    private function assertTargetCanContain(string $definition): void
    {
        $target = $this->paths->systemdServiceDefinitionFile();
        $status = @\lstat($target);
        if (!\is_array($status)) {
            if (\file_exists($target) || \is_link($target)) {
                throw new \RuntimeException(
                    'WLS Gateway dedicated systemd target is indeterminate.',
                );
            }
            return;
        }
        $this->assertExactRegular(
            $target,
            $definition,
            $this->definitionMode(),
            'WLS Gateway dedicated systemd target',
        );
    }

    private function publishTarget(string $definition): void
    {
        $target = $this->paths->systemdServiceDefinitionFile();
        $this->reconcileTargetAtomicRecoveryArtifacts($definition);
        $status = @\lstat($target);
        if (\is_array($status)) {
            $this->assertExactRegular(
                $target,
                $definition,
                $this->definitionMode(),
                'WLS Gateway dedicated systemd target',
            );
            return;
        }
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'WLS Gateway dedicated systemd target is indeterminate.',
            );
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $target,
            $definition,
            $this->definitionMode(),
        );
        $this->assertExactTarget($definition);
    }

    /**
     * The target's same-directory atomic write can die before rename.  This
     * is a first-publication boundary: an absent target permits discard of
     * only exact staging leaves and a committed target permits cleanup only
     * after it is re-proved as this definition.  No cross-filesystem rename
     * is involved.
     */
    private function reconcileTargetAtomicRecoveryArtifacts(string $definition): void
    {
        $target = $this->paths->systemdServiceDefinitionFile();
        $status = @\lstat($target);
        if (\is_array($status)) {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $target,
                self::MAX_DEFINITION_BYTES,
                'WLS Gateway dedicated systemd target',
                function (string $raw) use ($definition): void {
                    if (!\hash_equals($definition, $raw)) {
                        throw new \RuntimeException(
                            'WLS Gateway dedicated systemd target recovery image is foreign.',
                        );
                    }
                    $this->assertExactTarget($definition);
                },
            );
            return;
        }
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'WLS Gateway dedicated systemd target is indeterminate.',
            );
        }
        GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
            $target,
            self::MAX_DEFINITION_BYTES,
            'WLS Gateway dedicated systemd target',
        );
    }

    /** @param array<string|int,mixed> $legacyStatus */
    private function replaceExactLegacyRegularWithFixedLink(
        array $legacyStatus,
        string $legacyDefinition,
    ): void {
        $link = $this->paths->legacySystemdServiceDefinitionFile();
        $current = $this->assertExactRegular(
            $link,
            $legacyDefinition,
            $this->definitionMode(),
            'WLS Gateway legacy canonical systemd unit before link switch',
        );
        if (!$this->sameIdentity($legacyStatus, $current)) {
            throw new \RuntimeException(
                'WLS Gateway legacy canonical systemd unit changed before link switch.',
            );
        }
        $this->publishFixedLinkAfterTarget(
            $current,
            $legacyDefinition,
        );
    }

    /**
     * @param array<string|int,mixed>|null $expectedLegacyStatus
     */
    private function publishFixedLinkAfterTarget(
        ?array $expectedLegacyStatus = null,
        ?string $expectedLegacyDefinition = null,
    ): void
    {
        $link = $this->paths->systemdServiceLinkFile();
        $state = $this->fixedLinkState();
        if ($state === 'exact') {
            if ($expectedLegacyStatus !== null) {
                throw new \RuntimeException(
                    'WLS Gateway legacy canonical systemd unit changed before link publication.',
                );
            }
            return;
        }
        if ($state === 'foreign') {
            if ($expectedLegacyStatus === null
                || !\is_string($expectedLegacyDefinition)
            ) {
                throw new \RuntimeException(
                    'WLS Gateway canonical systemd link is foreign or malformed.',
                );
            }
            $currentLegacy = $this->assertExactRegular(
                $link,
                $expectedLegacyDefinition,
                $this->definitionMode(),
                'WLS Gateway legacy canonical systemd unit during link publication',
            );
            if (!$this->sameIdentity($expectedLegacyStatus, $currentLegacy)) {
                throw new \RuntimeException(
                    'WLS Gateway legacy canonical systemd unit changed during link publication.',
                );
            }
        } elseif ($state !== 'missing') {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link is foreign or malformed.',
            );
        }
        $temporary = $link . '.tmp-' . \bin2hex(\random_bytes(12));
        if (@\lstat($temporary) !== false
            || \file_exists($temporary)
            || \is_link($temporary)
            || !@\symlink($this->paths->systemdServiceDefinitionFile(), $temporary)
        ) {
            throw new \RuntimeException(
                'Unable to stage the WLS Gateway canonical systemd link.',
            );
        }
        try {
            $temporaryStatus = $this->assertExactLinkAt(
                $temporary,
                'WLS Gateway staged canonical systemd link',
            );
            if (!@\rename($temporary, $link)) {
                throw new \RuntimeException(
                    'Unable to atomically publish the WLS Gateway canonical systemd link.',
                );
            }
            if (@\lstat($temporary) !== false
                || \file_exists($temporary)
                || \is_link($temporary)
            ) {
                throw new \RuntimeException(
                    'WLS Gateway staged canonical systemd link remained after publication.',
                );
            }
            $published = $this->assertExactFixedLink();
            if (!$this->sameIdentity($temporaryStatus, $published)) {
                throw new \RuntimeException(
                    'WLS Gateway canonical systemd link identity changed during publication.',
                );
            }
            GatewayProjectStateFilesystem::syncDirectory(\dirname($link));
        } catch (\Throwable $throwable) {
            $status = @\lstat($temporary);
            if (\is_array($status) && \is_link($temporary)) {
                @\unlink($temporary);
            }
            throw $throwable;
        }
    }

    /**
     * A hard crash can leave the installer-owned symlink staging leaf behind
     * after its target is durable.  Unlike the mutable target's regular-file
     * staging, it cannot use GatewayProjectStateFilesystem: that helper quite
     * intentionally rejects symlinks.  Enumerate only this exact reserved
     * namespace, reject aliases/special leaves before the first unlink, then
     * remove only an exact symlink to the dedicated target.
     */
    private function reconcileCanonicalLinkStaging(): void
    {
        $directory = \dirname($this->paths->systemdServiceLinkFile());
        $before = @\lstat($directory);
        if (!\is_array($before)) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link directory is unavailable.',
            );
        }
        $artifacts = $this->canonicalLinkStagingInventory($directory);
        if ($artifacts === []) {
            return;
        }
        $after = @\lstat($directory);
        if (!\is_array($after) || !$this->sameIdentity($before, $after)) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link directory changed during staging inspection.',
            );
        }
        $rechecked = $this->canonicalLinkStagingInventory($directory);
        if (\array_keys($artifacts) !== \array_keys($rechecked)) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd link staging set changed before cleanup.',
            );
        }
        foreach ($artifacts as $path => $status) {
            $current = $rechecked[$path] ?? null;
            if (!\is_array($current) || !$this->sameIdentity($status, $current)) {
                throw new \RuntimeException(
                    'WLS Gateway canonical systemd link staging identity changed before cleanup.',
                );
            }
        }
        foreach ($rechecked as $path => $status) {
            $current = $this->assertExactLinkAt(
                $path,
                'WLS Gateway staged canonical systemd link during cleanup',
            );
            if (!$this->sameIdentity($status, $current) || !@\unlink($path)) {
                throw new \RuntimeException(
                    'Unable to collect an interrupted WLS Gateway canonical systemd link staging leaf.',
                );
            }
        }
        GatewayProjectStateFilesystem::syncDirectory($directory);
    }

    /**
     * @return array<string,array<string|int,mixed>>
     */
    private function canonicalLinkStagingInventory(string $directory): array
    {
        $linkLeaf = \basename(\str_replace(
            '\\',
            '/',
            $this->paths->systemdServiceLinkFile(),
        ));
        $prefix = $linkLeaf . '.tmp-';
        $pattern = '/\\A' . \preg_quote($prefix, '/') . '[a-f0-9]{24}\\z/Du';
        $foldedPrefix = \strtolower($prefix);
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the WLS Gateway canonical systemd link directory.',
            );
        }
        $artifacts = [];
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (!\str_starts_with(\strtolower($leaf), $foldedPrefix)) {
                    continue;
                }
                if (!\str_starts_with($leaf, $prefix)
                    || \preg_match($pattern, $leaf) !== 1
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway canonical systemd link directory contains a malformed reserved staging leaf.',
                    );
                }
                if (\count($artifacts) >= self::MAX_LINK_STAGING_ENTRIES) {
                    throw new \RuntimeException(
                        'WLS Gateway canonical systemd link staging quota is exhausted.',
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                $artifacts[$path] = $this->assertExactLinkAt(
                    $path,
                    'WLS Gateway staged canonical systemd link',
                );
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($artifacts, SORT_STRING);
        return $artifacts;
    }

    /** @return 'exact'|'missing'|'foreign' */
    private function fixedLinkState(): string
    {
        $link = $this->paths->systemdServiceLinkFile();
        $status = @\lstat($link);
        if (!\is_array($status)) {
            return (!\file_exists($link) && !\is_link($link))
                ? 'missing'
                : 'foreign';
        }
        if (!\is_link($link)) {
            return 'foreign';
        }
        try {
            $this->assertExactLinkAt($link, 'WLS Gateway canonical systemd link');
        } catch (\Throwable) {
            return 'foreign';
        }
        return 'exact';
    }

    /** @return array<string|int,mixed> */
    private function assertExactFixedLink(): array
    {
        return $this->assertExactLinkAt(
            $this->paths->systemdServiceLinkFile(),
            'WLS Gateway canonical systemd link',
        );
    }

    /** @return array<string|int,mixed> */
    private function assertExactLinkAt(string $path, string $label): array
    {
        $before = @\lstat($path);
        if (!\is_array($before)
            || !\is_link($path)
            || (int)($before['uid'] ?? -1) !== $this->expectedUid()
            || (int)($before['gid'] ?? -1) !== $this->expectedGid()
        ) {
            throw new \RuntimeException($label . ' is not a trusted symlink.');
        }
        $target = @\readlink($path);
        $after = @\lstat($path);
        if (!\is_string($target)
            || !\hash_equals($this->paths->systemdServiceDefinitionFile(), $target)
            || !\is_array($after)
            || !$this->sameIdentity($before, $after)
            || !\is_link($path)
        ) {
            throw new \RuntimeException($label . ' target is invalid.');
        }
        return $after;
    }

    /** @return array<string|int,mixed> */
    private function assertExactTarget(string $definition): array
    {
        return $this->assertExactRegular(
            $this->paths->systemdServiceDefinitionFile(),
            $definition,
            $this->definitionMode(),
            'WLS Gateway dedicated systemd target',
        );
    }

    /** @return array<string|int,mixed> */
    private function assertExactRegular(
        string $path,
        string $expected,
        int $mode,
        string $label,
    ): array {
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['uid'] ?? -1) !== $this->expectedUid()
            || (int)($before['gid'] ?? -1) !== $this->expectedGid()
            || (((int)($before['mode'] ?? 0)) & 0777) !== $mode
        ) {
            throw new \RuntimeException($label . ' is not an exact regular file.');
        }
        $raw = GatewayProjectStateFilesystem::read(
            $path,
            self::MAX_DEFINITION_BYTES,
            $label,
        );
        $after = @\lstat($path);
        if (!\hash_equals($expected, $raw)
            || !\is_array($after)
            || !$this->sameIdentity($before, $after)
        ) {
            throw new \RuntimeException($label . ' content or identity changed.');
        }
        return $after;
    }

    private function definitionMode(): int
    {
        return $this->paths->isTestMode() ? 0600 : 0644;
    }

    private function expectedUid(): int
    {
        if (!$this->paths->isTestMode()) {
            return 0;
        }
        $home = @\lstat($this->paths->home());
        if (!\is_array($home)) {
            throw new \RuntimeException('WLS Gateway test home authority is unavailable.');
        }
        return (int)($home['uid'] ?? -1);
    }

    private function expectedGid(): int
    {
        if (!$this->paths->isTestMode()) {
            return 0;
        }
        $home = @\lstat($this->paths->home());
        if (!\is_array($home)) {
            throw new \RuntimeException('WLS Gateway test home authority is unavailable.');
        }
        return (int)($home['gid'] ?? -1);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function sameIdentity(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    private function assertPathAbsent(string $path, string $message): void
    {
        if (@\lstat($path) !== false || \file_exists($path) || \is_link($path)) {
            throw new \RuntimeException($message);
        }
    }
}
