<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * 自动预热的站点范围清单：默认站 + 每个有公网 Host 的具名站。
 *
 * 每个 scope 自带 host（loopback 请求用它当 Host: 头，写各自 FPC 叶）与
 * ledger_key（每站独立进度账本）。default 复用旧账本文件，保证续跑不丢进度。
 */
final class StorefrontWarmupScopeCatalog
{
    public const SCOPE_DEFAULT = 'default';
    public const SCOPE_ALL = 'all';

    /**
     * 站点行 → scope 列表。纯函数，供单测与 CLI 直接调用。
     *
     * @param list<array{website_id?:int,name?:string,code?:string,url?:string}> $rows
     * @return list<array{code:string,name:string,host:string,ledger_key:string}>
     */
    public static function fromRows(array $rows, string $defaultHost): array
    {
        $defaultHost = self::normalizeHost($defaultHost);
        $scopes = [[
            'code' => self::SCOPE_DEFAULT,
            'name' => (string)__('默认站'),
            'host' => $defaultHost,
            'ledger_key' => self::SCOPE_DEFAULT,
        ]];
        $seen = [self::SCOPE_DEFAULT => true];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \strtolower(\trim((string)($row['code'] ?? '')));
            if ($code === '' || $code === self::SCOPE_DEFAULT || isset($seen[$code])) {
                continue;
            }
            $host = self::normalizeHost((string)($row['url'] ?? ''));
            if ($host === '' || self::isLoopback($host)) {
                continue;
            }
            $seen[$code] = true;
            $scopes[] = [
                'code' => $code,
                'name' => \trim((string)($row['name'] ?? '')) !== ''
                    ? \trim((string)$row['name'])
                    : $code,
                'host' => $host,
                'ledger_key' => $code,
            ];
        }

        return $scopes;
    }

    /**
     * 运行期站点清单：WebsiteCatalogInterface::all() + 实例 public_host。
     * Websites 模块缺失 / DB 异常时降级为仅默认站（等价于旧单站行为）。
     *
     * @return list<array{code:string,name:string,host:string,ledger_key:string}>
     */
    public function all(): array
    {
        $defaultHost = $this->defaultInstanceHost();
        $rows = [];
        if (\interface_exists(WebsiteCatalogInterface::class)) {
            try {
                $catalog = ObjectManager::getInstance(WebsiteCatalogInterface::class);
                foreach ($catalog->all() as $site) {
                    $rows[] = [
                        'website_id' => (int)($site->id ?? 0),
                        'name' => (string)($site->name ?? ''),
                        'code' => (string)($site->code ?? ''),
                        'url' => (string)($site->url ?? ''),
                    ];
                }
            } catch (\Throwable) {
                $rows = [];
            }
        }

        return self::fromRows($rows, $defaultHost);
    }

    /** @return array{code:string,name:string,host:string,ledger_key:string}|null */
    public function resolve(string $scope): ?array
    {
        $scope = \strtolower(\trim($scope));
        if ($scope === '' || $scope === self::SCOPE_ALL) {
            return null;
        }
        foreach ($this->all() as $item) {
            if ($item['code'] === $scope) {
                return $item;
            }
        }

        return null;
    }

    private function defaultInstanceHost(): string
    {
        $path = (\defined('BP') ? (string)BP : '') . 'var/server/instances/default.json';
        if (!\is_file($path)) {
            return '';
        }
        try {
            $decoded = \json_decode((string)\file_get_contents($path), true);
            if (!\is_array($decoded)) {
                return '';
            }
            foreach (['public_host', 'host'] as $key) {
                $host = self::normalizeHost((string)($decoded[$key] ?? ''));
                if ($host !== '' && !self::isLoopback($host)) {
                    return $host;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /** 绝对/裸 URL → 小写纯 host（去端口、去尾点）；无效返回 ''。 */
    public static function normalizeHost(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '';
        }
        $candidate = \str_contains($raw, '://') ? (string)(\parse_url($raw, \PHP_URL_HOST) ?? '') : $raw;
        if (\str_starts_with($candidate, '[')) {
            $candidate = \trim($candidate, '[]');
        } elseif (\str_contains($candidate, ':') && \substr_count($candidate, ':') === 1) {
            $candidate = (string)\strstr($candidate, ':', true);
        }
        $candidate = \strtolower(\rtrim(\trim($candidate), '.'));

        return \preg_match('/^[a-z0-9.-]+$/', $candidate) === 1 ? $candidate : '';
    }

    private static function isLoopback(string $host): bool
    {
        return $host === '127.0.0.1'
            || $host === 'localhost'
            || $host === '::1'
            || \str_starts_with($host, '127.');
    }
}
