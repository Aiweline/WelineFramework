<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * Dual-track timestamp origin markers (R-C C2).
 *
 * Contract vocabulary — do not treat {@see self::FRAMEWORK_BASE} as undeclared pollution.
 *
 * @see TimestampColumnOriginAuditor
 */
final class TimestampColumnOriginMarkers
{
    /** Declared via AbstractModel #[Col], inherited into SchemaDiff / checkpoint. */
    public const FRAMEWORK_BASE = 'framework_base_timestamps';

    /** Injected by legacy Create::create() / create(true) when missing from the field map. */
    public const LEGACY_IMPLICIT = 'legacy_implicit_timestamps';

    /** Mislabel forbidden for documented dual-track origins (negative contract lock). */
    public const UNDECLARED_POLLUTION = 'undeclared_pollution';

    public const CREATE_TIME = 'create_time';

    public const UPDATE_TIME = 'update_time';

    /**
     * @return list<string>
     */
    public static function documentedOrigins(): array
    {
        return [self::FRAMEWORK_BASE, self::LEGACY_IMPLICIT];
    }

    public static function isDocumentedOrigin(string $marker): bool
    {
        return \in_array($marker, self::documentedOrigins(), true);
    }
}
