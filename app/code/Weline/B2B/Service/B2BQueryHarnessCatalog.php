<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * E2E / DEV B2B 候选状态叠加（var 文件，避 CLI/Worker 缓存隔离）。
 *
 * @phpstan-type HarnessState array{
 *   groups: list<array{group_id:string,website_id:int,code:string,status:string}>,
 *   membership: array<string, string>,
 *   price_lists: list<array{
 *     list_id:string,
 *     group_id:string,
 *     website_id:int,
 *     version:int,
 *     sku_amounts:array<string, int>,
 *     channel_id:?string,
 *     active:bool
 *   }>,
 *   rollout_mode: string,
 *   rollout_allowlist: list<string>
 * }
 */
final class B2BQueryHarnessCatalog
{
    private const FILE = 'state.json';

    /**
     * @param HarnessState $state
     */
    public static function put(array $state): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('unable to create b2b_query_harness dir');
        }
        $payload = [
            'groups' => array_values(is_array($state['groups'] ?? null) ? $state['groups'] : []),
            'membership' => is_array($state['membership'] ?? null) ? $state['membership'] : [],
            'price_lists' => array_values(is_array($state['price_lists'] ?? null) ? $state['price_lists'] : []),
            'rollout_mode' => (string)($state['rollout_mode'] ?? CommerceRolloutGateInterface::MODE_OFF),
            'rollout_allowlist' => array_values(is_array($state['rollout_allowlist'] ?? null) ? $state['rollout_allowlist'] : []),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents(self::path(), $json) === false) {
            throw new \RuntimeException('unable to write b2b_query_harness');
        }
    }

    /**
     * @return HarnessState|null
     */
    public static function load(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'groups' => array_values(is_array($decoded['groups'] ?? null) ? $decoded['groups'] : []),
            'membership' => is_array($decoded['membership'] ?? null) ? $decoded['membership'] : [],
            'price_lists' => array_values(is_array($decoded['price_lists'] ?? null) ? $decoded['price_lists'] : []),
            'rollout_mode' => (string)($decoded['rollout_mode'] ?? CommerceRolloutGateInterface::MODE_OFF),
            'rollout_allowlist' => array_values(is_array($decoded['rollout_allowlist'] ?? null) ? $decoded['rollout_allowlist'] : []),
        ];
    }

    public static function isActive(): bool
    {
        return is_file(self::path());
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function buildService(): B2BService
    {
        $state = self::load();
        if ($state === null) {
            throw new B2BConflictException(
                'b2b_test_harness_inactive',
                __('B2B 浏览器测试夹具未启用'),
            );
        }

        $svc = B2BService::forTesting();
        foreach ($state['groups'] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $svc->seedGroup(
                (string)($group['group_id'] ?? ''),
                (int)($group['website_id'] ?? 0),
                (string)($group['code'] ?? ''),
                (string)($group['status'] ?? CustomerGroup::STATUS_ACTIVE),
            );
        }
        foreach ($state['membership'] as $customerId => $groupId) {
            $svc->assignCustomer((string)$customerId, (string)$groupId);
        }
        foreach ($state['price_lists'] as $list) {
            if (!is_array($list)) {
                continue;
            }
            $skuAmounts = [];
            foreach (is_array($list['sku_amounts'] ?? null) ? $list['sku_amounts'] : [] as $sku => $amount) {
                $skuAmounts[(string)$sku] = (int)$amount;
            }
            $channel = $list['channel_id'] ?? null;
            $svc->seedPriceList(
                (string)($list['list_id'] ?? ''),
                (string)($list['group_id'] ?? ''),
                (int)($list['website_id'] ?? 0),
                (int)($list['version'] ?? 1),
                $skuAmounts,
                $channel !== null && $channel !== '' ? (string)$channel : null,
                (bool)($list['active'] ?? false),
            );
        }

        $mode = trim((string)$state['rollout_mode']);
        if ($mode === CommerceRolloutGateInterface::MODE_SHADOW) {
            $svc->enableShadow();
        } elseif ($mode === CommerceRolloutGateInterface::MODE_ALLOWLIST) {
            $svc->enableAllowlist($state['rollout_allowlist'] !== [] ? $state['rollout_allowlist'] : ['website:0']);
        } else {
            $svc->modeOff();
        }

        return $svc;
    }

    private static function dir(): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'b2b_query_harness';
    }

    private static function path(): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . self::FILE;
    }
}
