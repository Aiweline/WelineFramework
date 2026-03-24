<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;

/**
 * Index definition DTO.
 */
final class IndexDefinition
{
    public readonly string $name;

    /** @var list<string> */
    public readonly array $columns;

    public readonly string $type;

    public readonly string $comment;

    public readonly string $method;

    /** @param list<string> $columns */
    public function __construct(
        string $name,
        array $columns,
        string $type = TableInterface::index_type_DEFAULT,
        string $comment = '',
        string $method = TableInterface::index_method_BTREE,
    ) {
        [$normalizedType, $normalizedMethod] = self::normalizeTypeAndMethod($type, $method);

        $this->name = $name;
        $this->columns = array_values($columns);
        $this->type = $normalizedType;
        $this->comment = $comment;
        $this->method = $normalizedMethod;
    }

    /** @return array{0:string,1:string} */
    private static function normalizeTypeAndMethod(string $type, string $method): array
    {
        $normalizedType = strtoupper(trim($type));
        $normalizedMethod = strtoupper(trim($method));

        if ($normalizedMethod === '') {
            $normalizedMethod = TableInterface::index_method_BTREE;
        }

        // Backward compatibility: older declarations occasionally put the method into `type`.
        if (in_array($normalizedType, [
            TableInterface::index_method_BTREE,
            TableInterface::index_method_HASH,
        ], true)) {
            $normalizedMethod = $normalizedType;
            $normalizedType = TableInterface::index_type_DEFAULT;
        }

        if ($normalizedType === '' || $normalizedType === 'INDEX') {
            $normalizedType = TableInterface::index_type_DEFAULT;
        }

        return [$normalizedType, $normalizedMethod];
    }
}
