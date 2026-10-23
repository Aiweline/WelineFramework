<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaCheckpointIdentity;
use Weline\Framework\Database\Schema\TableSchema;

/** 声明式表结构的部署无关指纹。 */
final class SchemaFingerprint
{
    public function __construct(private readonly ConnectionFactory $connectionFactory)
    {
    }

    /** 归一化声明：去掉当前连接注入的运行时限定符，保证指纹跨部署一致。 */
    public function normalizeSchema(TableSchema $schema): TableSchema
    {
        return SchemaCheckpointIdentity::schema(
            $schema,
            SchemaCheckpointIdentity::runtimeQualifiers($this->connectionFactory->getConnector()),
        );
    }

    public function fingerprint(TableSchema $schema): string
    {
        return \hash('sha256', (string)\json_encode(
            self::normalize($this->normalizeSchema($schema)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (\is_object($value)) {
            $value = \get_object_vars($value);
            unset($value['modelClass']);
        }
        if (\is_array($value)) {
            if (!\array_is_list($value)) {
                \ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }
        }
        return $value;
    }
}
