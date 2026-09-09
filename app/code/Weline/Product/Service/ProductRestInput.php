<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Data\ProductAdminCommand;

/** 将外部 JSON 请求转换为产品已有的命令契约。 */
final class ProductRestInput
{
    /** @return array<string,mixed> */
    public static function decode(string $json): array
    {
        try {
            $body = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('product_api_json_object_required');
        }
        if (!is_array($body) || !str_starts_with(ltrim($json), '{')) {
            throw new \InvalidArgumentException('product_api_json_object_required');
        }
        return $body;
    }

    public static function nonNegativeInt(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value)
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new \InvalidArgumentException('product_api_' . $field . '_invalid');
        }
        return (int)$value;
    }

    /** @param array<string,mixed> $body */
    public static function command(string $action, array $body, int $installationId, ?string $idempotencyScope = null): ProductAdminCommand
    {
        $websiteId = self::nonNegativeInt($body['website_id'] ?? null, 'website_id');
        $payload = $body['payload'] ?? null;
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new \InvalidArgumentException('product_admin_payload_invalid');
        }
        $uuid = null;
        if (in_array($action, [ProductAdminCommand::ACTION_SAVE, ProductAdminCommand::ACTION_PUBLISH], true)) {
            $uuid = $body['global_product_uuid'] ?? null;
            if (!is_string($uuid) || trim($uuid) === '') {
                throw new \InvalidArgumentException('product_admin_product_uuid_required');
            }
            if (!array_key_exists('local_version', $payload) || $payload['local_version'] === null) {
                throw new \InvalidArgumentException('product_admin_local_version_required');
            }
            $payload['local_version'] = self::nonNegativeInt($payload['local_version'], 'local_version');
        }
        $expectedVersion = null;
        if ($action === ProductAdminCommand::ACTION_PUBLISH) {
            if (!array_key_exists('expected_version', $body) || $body['expected_version'] === null) {
                throw new \InvalidArgumentException('product_admin_expected_version_required');
            }
            $expectedVersion = self::nonNegativeInt($body['expected_version'], 'expected_version');
        }
        $requestHash = $body['request_hash'] ?? bin2hex(random_bytes(32));
        if (!is_string($requestHash) || !preg_match('/^[a-f0-9]{64}$/iD', $requestHash)) {
            throw new \InvalidArgumentException('product_admin_request_hash_invalid');
        }

        // API 用户和外部应用都不冒充后台管理员；原应用幂等键保持兼容。
        $principal = $idempotencyScope ?? (string)$installationId;
        return new ProductAdminCommand(
            action: $action,
            websiteId: $websiteId,
            globalProductUuid: $uuid,
            expectedVersion: $expectedVersion,
            requestHash: hash('sha256', 'product-rest:' . $principal . ':' . $websiteId . ':' . $action . ':' . strtolower($requestHash)),
            actorId: 0,
            payload: $payload,
        );
    }
}
