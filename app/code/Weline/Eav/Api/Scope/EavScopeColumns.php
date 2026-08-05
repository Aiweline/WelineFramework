<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Scope;

/**
 * EAV 值表 typed Scope 固定列（P1B-005 / v1）。
 *
 * - scope_kind IS NULL：遗留行（旧读适配只读；null/空不得猜成 cleared）
 * - scope_kind 非空：typed 行，参与四层 fallback + locale 优先
 */
final class EavScopeColumns
{
    public const SCOPE_KIND = 'scope_kind';
    public const WEBSITE_ID = 'website_id';
    public const WEBSITE_CODE = 'website_code';
    public const STORE_CODE = 'store_code';
    public const CHANNEL_CODE = 'channel_code';
    public const IS_CLEARED = 'is_cleared';
    public const LOCALE = 'locale';

    /** @var list<string> */
    public const ALL = [
        self::SCOPE_KIND,
        self::WEBSITE_ID,
        self::WEBSITE_CODE,
        self::STORE_CODE,
        self::CHANNEL_CODE,
        self::IS_CLEARED,
        self::LOCALE,
    ];

    private function __construct()
    {
    }
}
