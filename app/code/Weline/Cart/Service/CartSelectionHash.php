<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

/**
 * Server-authoritative selection hash（client hash is never trusted）.
 * sha256(global_offer_uuid + "\\n" + selection_schema_version + "\\n" + canonical_sorted_json)
 */
final class CartSelectionHash
{
    public const ERROR_INVALID_SELECTION = 'cart_selection_invalid';
    public const ERROR_HASH_MISMATCH = 'cart_selection_hash_mismatch';

    /**
     * @param array<string, scalar|null> $selection
     */
    public static function compute(string $globalOfferUuid, string $selectionSchemaVersion, array $selection): string
    {
        $canonical = self::canonicalJson($selection);
        return hash(
            'sha256',
            trim($globalOfferUuid) . "\n" . trim($selectionSchemaVersion) . "\n" . $canonical
        );
    }

    /**
     * @param array<string, scalar|null> $selection
     * @return array<string, scalar|null>
     */
    public static function normalizeSelection(array $selection): array
    {
        $safe = [];
        foreach ($selection as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new CartV2ConflictException(
                    self::ERROR_INVALID_SELECTION,
                    __('非法 selection 值类型：%{1}', [(string)$key]),
                );
            }
            $k = trim((string)$key);
            if ($k === '') {
                continue;
            }
            $safe[$k] = is_string($value) ? trim($value) : $value;
            if (count($safe) >= 50) {
                break;
            }
        }
        ksort($safe);
        return $safe;
    }

    /**
     * @param array<string, scalar|null> $selection
     */
    public static function canonicalJson(array $selection): string
    {
        $normalized = self::normalizeSelection($selection);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new CartV2ConflictException(
                self::ERROR_INVALID_SELECTION,
                __('selection JSON 编码失败'),
            );
        }
        return $json;
    }

    /**
     * @param array<string, scalar|null> $selection
     */
    public static function assertClientHashOrIgnore(
        ?string $clientHash,
        string $serverHash,
    ): void {
        $clientHash = $clientHash === null ? '' : trim($clientHash);
        if ($clientHash === '') {
            return; // client may omit; server is authority
        }
        if (!hash_equals($serverHash, $clientHash)) {
            throw new CartV2ConflictException(
                self::ERROR_HASH_MISMATCH,
                __('客户端 selection_hash 与服务端不一致（已忽略客户端，请使用服务端值）'),
                ['server_selection_hash' => $serverHash],
            );
        }
    }
}
