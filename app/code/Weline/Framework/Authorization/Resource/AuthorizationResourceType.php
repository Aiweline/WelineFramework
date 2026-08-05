<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

/** Canonical ACL resource_type values (orthogonal to backend_acl.kind). */
final class AuthorizationResourceType
{
    public const MENU = 'menu';
    public const HTTP = 'http';
    public const REST = 'rest';
    public const QUERY = 'query';
    public const RESUMABLE_TASK = 'resumable_task';
    public const OPERATION = 'operation';

    /** Storage `type` column values (historical + new). */
    public const STORAGE_MENUS = 'menus';
    public const STORAGE_PC = 'pc';
    public const STORAGE_API = 'api';
    public const STORAGE_QUERY = 'query';
    public const STORAGE_TASK = 'task';
    public const STORAGE_OPERATION = 'operation';

    /** @var array<string,string> */
    private const TO_STORAGE = [
        self::MENU => self::STORAGE_MENUS,
        self::HTTP => self::STORAGE_PC,
        self::REST => self::STORAGE_API,
        self::QUERY => self::STORAGE_QUERY,
        self::RESUMABLE_TASK => self::STORAGE_TASK,
        self::OPERATION => self::STORAGE_OPERATION,
    ];

    /** @var array<string,string> */
    private const FROM_STORAGE = [
        self::STORAGE_MENUS => self::MENU,
        self::STORAGE_PC => self::HTTP,
        self::STORAGE_API => self::REST,
        self::STORAGE_QUERY => self::QUERY,
        self::STORAGE_TASK => self::RESUMABLE_TASK,
        self::STORAGE_OPERATION => self::OPERATION,
    ];

    public static function toStorage(string $resourceType): string
    {
        if (!isset(self::TO_STORAGE[$resourceType])) {
            throw new \InvalidArgumentException('Unknown ACL resource_type: ' . $resourceType);
        }
        return self::TO_STORAGE[$resourceType];
    }

    public static function fromStorage(string $storageType): string
    {
        if (!isset(self::FROM_STORAGE[$storageType])) {
            throw new \InvalidArgumentException('Unknown ACL storage type: ' . $storageType);
        }
        return self::FROM_STORAGE[$storageType];
    }

    public static function isKnownStorage(string $storageType): bool
    {
        return isset(self::FROM_STORAGE[$storageType]);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return \array_keys(self::TO_STORAGE);
    }
}
