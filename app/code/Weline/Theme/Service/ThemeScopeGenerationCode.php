<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\ThemeScopeVersion;

/**
 * Deterministic storefront generation code for a Scope-published ThemeScopeVersion.
 *
 * Storefront generation === current Scope published theme version. The wire identity is this
 * code (not the numeric PK alone). Format:
 *
 *   t{themeId}.{area}.{storeMode}.{scopeFp12}.v{versionNumber}[.e{tuEpoch}]
 *
 * {@see fromVersion()} returns the version-only base; the runtime resolver appends the
 * Theme Update epoch so promote/rollback changes the FE scope identity even when the
 * version number and deploy build stay the same. Same owner + version_number always yields
 * the same base code (publish rollback to an older sealed row restores that older base).
 * Compile / cache clear only read the code — they must never invent a new published version.
 */
final class ThemeScopeGenerationCode
{
    public const PATTERN = '/^t\d+\.(frontend|backend)\.[A-Za-z0-9._:+-]{1,32}\.[a-f0-9]{12}\.v\d+(?:\.e\d+)?$/D';

    public static function fromVersion(ThemeScopeVersion $version): string
    {
        return self::fromParts(
            $version->getThemeId(),
            $version->getArea(),
            $version->getStoreMode(),
            $version->getScope(),
            $version->getVersionNumber(),
        );
    }

    public static function fromParts(
        int $themeId,
        string $area,
        string $storeMode,
        string $canonicalScope,
        int $versionNumber,
    ): string {
        if ($themeId < 1 || $versionNumber < 1) {
            return '';
        }
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';
        $storeMode = self::sanitizeSegment($storeMode !== '' ? $storeMode : 'normal');
        $scopeFp = \substr(\hash('sha256', \trim($canonicalScope)), 0, 12);

        return 't' . $themeId . '.' . $area . '.' . $storeMode . '.' . $scopeFp . '.v' . $versionNumber;
    }

    /**
     * Legacy FPC / Cookie may still carry numeric version_id strings during cutover.
     */
    public static function legacyNumericId(ThemeScopeVersion $version): string
    {
        $id = $version->getVersionId();

        return $id > 0 ? (string)$id : '';
    }

    private static function sanitizeSegment(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return 'normal';
        }
        $clean = \preg_replace('/[^A-Za-z0-9._:+-]+/', '-', $value) ?? 'normal';
        $clean = \trim($clean, '.-');
        if ($clean === '' || \strlen($clean) > 32) {
            return \substr(\hash('sha256', $value), 0, 12);
        }

        return $clean;
    }
}
