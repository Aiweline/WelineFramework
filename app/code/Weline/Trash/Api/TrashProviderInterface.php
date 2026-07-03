<?php
declare(strict_types=1);

namespace Weline\Trash\Api;

interface TrashProviderInterface
{
    public static function code(): string;

    public static function label(): string;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function trash(array $data, array $context = []): array;

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function restore(array $item, array $context = []): array;

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function purge(array $item, array $context = []): array;
}
