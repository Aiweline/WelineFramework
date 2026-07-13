<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Api;

/** Immutable, ORM-free projection of public module metadata. */
final readonly class ModuleCatalogEntry
{
    public const FIELD_ID = 'module_id';
    public const FIELD_NAME = 'name';
    public const FIELD_STATUS = 'status';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_POSITION = 'position';
    public const FIELD_NAMESPACE_PATH = 'namespace_path';
    public const FIELD_BASE_PATH = 'base_path';
    public const FIELD_PATH = 'path';
    public const FIELD_VERSION = 'version';
    public const FIELD_LAST_VERSION = 'last_version';
    public const FIELD_ROUTER = 'router';
    public const FIELD_CREATE_TIME = 'create_time';
    public const FIELD_UPDATE_TIME = 'update_time';

    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = [
            self::FIELD_ID => $data[self::FIELD_ID] ?? null,
            self::FIELD_NAME => $data[self::FIELD_NAME] ?? null,
            self::FIELD_STATUS => $data[self::FIELD_STATUS] ?? null,
            self::FIELD_DESCRIPTION => $data[self::FIELD_DESCRIPTION] ?? null,
            self::FIELD_POSITION => $data[self::FIELD_POSITION] ?? null,
            self::FIELD_NAMESPACE_PATH => $data[self::FIELD_NAMESPACE_PATH] ?? null,
            self::FIELD_BASE_PATH => $data[self::FIELD_BASE_PATH] ?? null,
            self::FIELD_PATH => $data[self::FIELD_PATH] ?? null,
            self::FIELD_VERSION => $data[self::FIELD_VERSION] ?? null,
            self::FIELD_LAST_VERSION => $data[self::FIELD_LAST_VERSION] ?? null,
            self::FIELD_ROUTER => $data[self::FIELD_ROUTER] ?? null,
            self::FIELD_CREATE_TIME => $data[self::FIELD_CREATE_TIME] ?? null,
            self::FIELD_UPDATE_TIME => $data[self::FIELD_UPDATE_TIME] ?? null,
        ];
    }

    public function getId(): int
    {
        return (int) ($this->data[self::FIELD_ID] ?? 0);
    }

    public function getName(): string
    {
        return (string) ($this->data[self::FIELD_NAME] ?? '');
    }

    public function get(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
