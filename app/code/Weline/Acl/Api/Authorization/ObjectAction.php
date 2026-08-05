<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

/**
 * 对象 Scope 动作矩阵（P1B-004-ACL / DEC-022）。
 *
 * default-deny：未显式授权的动作一律拒绝。
 * All Sites 是独立授权，且永远只读（仅 list/view/export）。
 */
final class ObjectAction
{
    public const LIST = 'list';
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const PHYSICAL_DELETE = 'physical-delete';
    public const REFUND = 'refund';
    public const FULFILL = 'fulfill';
    public const EXPORT = 'export';
    public const IMPORT = 'import';
    public const UNLOCK = 'unlock';
    public const REPLAY = 'replay';
    public const RECONCILE = 'reconcile';
    public const ALL_SITES = 'all-sites';

    public const ALL = [
        self::LIST,
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
        self::PHYSICAL_DELETE,
        self::REFUND,
        self::FULFILL,
        self::EXPORT,
        self::IMPORT,
        self::UNLOCK,
        self::REPLAY,
        self::RECONCILE,
        self::ALL_SITES,
    ];

    /** All Sites 授权允许的只读动作子集。 */
    public const ALL_SITES_READ_ACTIONS = [
        self::LIST,
        self::VIEW,
        self::EXPORT,
    ];

    public const WRITE_ACTIONS = [
        self::CREATE,
        self::UPDATE,
        self::DELETE,
        self::PHYSICAL_DELETE,
        self::REFUND,
        self::FULFILL,
        self::IMPORT,
        self::UNLOCK,
        self::REPLAY,
        self::RECONCILE,
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $action): bool
    {
        return \in_array($action, self::ALL, true);
    }

    public static function isWrite(string $action): bool
    {
        return \in_array($action, self::WRITE_ACTIONS, true);
    }

    public static function isAllSitesReadable(string $action): bool
    {
        return \in_array($action, self::ALL_SITES_READ_ACTIONS, true);
    }
}
