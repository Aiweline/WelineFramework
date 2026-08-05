<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

final class AuthorizationResourceOrigin
{
    public const MENU_XML = 'menu_xml';
    public const CONTROLLER_ATTRIBUTE = 'controller_attribute';
    public const QUERY_PROVIDER = 'query_provider';
    public const RESUMABLE_TASK = 'resumable_task';
    public const SYSTEM_OPERATION = 'system_operation';
    public const USER = 'user';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MENU_XML,
            self::CONTROLLER_ATTRIBUTE,
            self::QUERY_PROVIDER,
            self::RESUMABLE_TASK,
            self::SYSTEM_OPERATION,
            self::USER,
        ];
    }

    public static function isKnown(string $origin): bool
    {
        return \in_array($origin, self::all(), true);
    }
}
