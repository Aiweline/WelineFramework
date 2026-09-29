<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Extends\Module\Weline_Cdn\ProductHeatUrls;

/**
 * 预热收集编排：按 Provider 分批投递，Domain 范围在本层过滤。
 */
class WarmupCollectService
{
    public const PER_PROVIDER_CAP = 3000;

    public function __construct(
        private readonly WarmupProviderScanner $scanner,
        private readonly UrlSiteResolver $urlSiteResolver,
        private readonly EventsManager $eventsManager,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return list<array{fqcn:string,module:string,label:string,hint:string,short_name:string,top_n?:int}>
     */
    public function listProvidersMeta(): array
    {
        $items = [];
        foreach ($this->scanner->scanProviders() as $fqcn) {
            $items[] = $this->metaForFqcn($fqcn);
        }

        return $items;
    }

    /**
     * @return array{fqcn:string,module:string,label:string,hint:string,short_name:string,top_n?:int}
     */
    public function metaForFqcn(string $fqcn): array
    {
        $short = $fqcn;
        if (str_contains($fqcn, '\\')) {
            $parts = explode('\\', $fqcn);
            $short = (string)end($parts);
        }
        $module = $this->sourceModuleForFqcn($fqcn);
        $label = $short;
        $hint = '';
        $topN = null;
        if ($fqcn === FpcExtraDeclaredUrls::class) {
            $label = FpcExtraDeclaredUrls::UI_LABEL;
            $hint = FpcExtraDeclaredUrls::UI_HINT;
        } elseif ($fqcn === ProductHeatUrls::class || str_ends_with($fqcn, '\\ProductHeatUrls')) {
            $label = ProductHeatUrls::UI_LABEL;
            $hint = ProductHeatUrls::UI_HINT;
            $module = ProductHeatUrls::SOURCE_MODULE;
            $topN = ProductHeatUrls::TOP_N;
        }

        $meta = [
            'fqcn' => $fqcn,
            'module' => $module,
            'label' => $label,
            'hint' => $hint,
            'short_name' => $short,
        ];
        if ($topN !== null) {
            $meta['top_n'] = $topN;
        }

        return $meta;
    }

    public function sourceModuleForFqcn(string $fqcn): string
    {
        if ($fqcn === FpcExtraDeclaredUrls::class || str_starts_with($fqcn, 'Weline\\Cdn\\WarmupProvider\\')) {
            return FpcExtraDeclaredUrls::SOURCE_MODULE;
        }
        if (preg_match('#^Weline\\\\([^\\\\]+)\\\\#', $fqcn, $m)) {
            return 'Weline_' . $m[1];
        }
        if (str_contains($fqcn, '\\Extends\\Module\\Weline_Cdn\\') || str_contains($fqcn, '\\Extends\\Weline_Cdn\\')) {
            $before = preg_split('/\\\\Extends\\\\(?:Module\\\\)?Weline_Cdn\\\\/', $fqcn)[0] ?? '';
            $parts = array_values(array_filter(explode('\\', $before)));
            if (count($parts) >= 2) {
                return $parts[0] . '_' . $parts[1];
            }
        }

        return 'Weline_Cdn';
    }

    /**
     * @return array{inserted_count:int,updated_count:int,filtered_count:int,skipped_dedupe_hint:int,provider:string,accepted_count:int}
     */
    public function collectProvider(string $providerFqcn, ?int $domainId = null, ?int $siteIdFilter = null): array
    {
        if ($providerFqcn === '' || !class_exists($providerFqcn)) {
            throw new \InvalidArgumentException((string)__('WarmupProvider 不存在'));
        }
        $reflection = new \ReflectionClass($providerFqcn);
        if (!$reflection->implementsInterface(WarmupProviderInterface::class)) {
            throw new \InvalidArgumentException((string)__('不是有效的 WarmupProvider'));
        }

        $raw = call_user_func([$providerFqcn, 'execute']);
        if (!is_array($raw)) {
            $raw = [];
        }
        if (count($raw) > self::PER_PROVIDER_CAP) {
            $raw = array_slice($raw, 0, self::PER_PROVIDER_CAP);
        }

        $module = $this->sourceModuleForFqcn($providerFqcn);
        $accepted = [];
        $filtered = 0;
        foreach ($raw as $item) {
            $url = '';
            $siteId = null;
            if (is_string($item)) {
                $url = trim($item);
            } elseif (is_array($item)) {
                $url = trim((string)($item['url'] ?? ''));
                if (isset($item['site_id'])) {
                    $siteId = (int)$item['site_id'];
                }
            }
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
                $filtered++;
                continue;
            }
            $domain = $this->urlSiteResolver->resolveDomainByUrl($url);
            $resolvedDomainId = $domain ? (int)$domain->getData(Domain::schema_fields_DOMAIN_ID) : null;
            if ($domainId !== null && $domainId > 0) {
                if ($resolvedDomainId !== $domainId) {
                    $filtered++;
                    continue;
                }
            }
            $rowSiteId = $siteId;
            if ($rowSiteId === null && $domain) {
                $rowSiteId = (int)$domain->getData(Domain::schema_fields_SITE_ID);
            }
            // site_id=0 合法；仅 null 表示不按网站过滤
            if ($siteIdFilter !== null) {
                if ($rowSiteId === null || (int)$rowSiteId !== $siteIdFilter) {
                    $filtered++;
                    continue;
                }
            }
            $row = ['url' => $url];
            if ($rowSiteId !== null) {
                $row['site_id'] = (int)$rowSiteId;
            }
            if ($resolvedDomainId) {
                $row['domain_id'] = $resolvedDomainId;
            }
            $accepted[] = $row;
        }

        $inserted = 0;
        $updated = 0;
        if ($accepted !== []) {
            // EventsManager::dispatch 对非 array 引用会在结束后把 $data 回写成内层 data，
            // 禁止传入 Event 对象再点号取 result——调用方变量会变成 array 导致收集必败。
            $eventData = [
                'module' => $module,
                'provider' => $providerFqcn,
                'urls' => $accepted,
                'dedupe' => true,
            ];
            $this->eventsManager->dispatch('Weline_Cdn::send_warmup', $eventData);
            $result = $eventData['result'] ?? null;
            if (is_array($result)) {
                $inserted = (int)($result['inserted_count'] ?? 0);
                $updated = (int)($result['updated_count'] ?? 0);
            }
        }

        return [
            'inserted_count' => $inserted,
            'updated_count' => $updated,
            'filtered_count' => $filtered,
            'skipped_dedupe_hint' => $updated,
            'provider' => $providerFqcn,
            'accepted_count' => count($accepted),
        ];
    }

    /**
     * Cron：全 Provider 分批收集。
     *
     * @return array{providers:int,inserted:int,updated:int,filtered:int}
     */
    public function collectAllProviders(): array
    {
        $inserted = 0;
        $updated = 0;
        $filtered = 0;
        $providers = $this->scanner->scanProviders();
        foreach ($providers as $fqcn) {
            try {
                $r = $this->collectProvider($fqcn, null);
                $inserted += $r['inserted_count'];
                $updated += $r['updated_count'];
                $filtered += $r['filtered_count'];
            } catch (\Throwable $e) {
                w_log_error('WarmupCollectService collectAll failed: ' . $fqcn . ' ' . $e->getMessage());
            }
        }

        return [
            'providers' => count($providers),
            'inserted' => $inserted,
            'updated' => $updated,
            'filtered' => $filtered,
        ];
    }
}
