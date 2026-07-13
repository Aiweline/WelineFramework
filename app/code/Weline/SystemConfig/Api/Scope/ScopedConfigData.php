<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

/** Stable scalar keys for public scoped-config rows and version payloads. */
final class ScopedConfigData
{
    public const SCOPE_GLOBAL = 'default.default.default';
    public const LOCALE_DEFAULT = 'default';

    public const VALUE = 'v';
    public const VERSION = 'version';

    public const VERSION_ID = 'version_id';
    public const MODULE = 'module';
    public const AREA = 'area';
    public const SCOPE = 'scope';
    public const LOCALE = 'locale';
    public const OPERATION = 'operation';
    public const STATUS = 'status';
    public const CREATED_AT = 'created_at';
    public const ACTOR_ID = 'actor_id';
    public const ACTOR_NAME = 'actor_name';
    public const REASON = 'reason';

    public const STATUS_APPLIED = 'applied';
}
