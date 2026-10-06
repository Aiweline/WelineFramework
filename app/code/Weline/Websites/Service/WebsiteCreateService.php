<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 控制中心 / Query 新建站点：与后台 quickSave 同一写入契约。
 */
final class WebsiteCreateService
{
    public function __construct(
        private readonly Website $website,
        private readonly WebsiteDomain $websiteDomain,
        private readonly WebsiteCurrency $websiteCurrency,
        private readonly WebsiteLanguage $websiteLanguage,
        private readonly WebsiteChangeSnapshotFactory $snapshots,
        private readonly WebsiteCacheInvalidationService $cacheInvalidation,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly EventsManager $events,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        $name = \trim((string)($params['name'] ?? ''));
        $code = \trim((string)($params['code'] ?? ''));
        $url = \trim((string)($params['url'] ?? $params['domain'] ?? ''));
        $timezone = \trim((string)($params['default_timezone'] ?? 'Asia/Shanghai')) ?: 'Asia/Shanghai';
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('站点名称不能为空'));
        }
        if ($url === '') {
            throw new \InvalidArgumentException((string)__('请填写网站地址'));
        }

        $address = self::parsePrimaryAddress($url);
        $subPath = WebsiteSubPathValidator::fromLocalizationRegistry()->assertValid($address['sub_path']);
        $address['sub_path'] = $subPath;
        if ($code === '') {
            $code = self::codeFromDomain($address['domain'], $subPath);
        }
        $this->assertCodeAvailable($code);

        $conflict = $this->websiteDomain->findConflict($address['domain'], $subPath, null);
        if ($conflict !== null) {
            $addr = $address['domain'] . $subPath;
            throw new \InvalidArgumentException(
                $subPath === ''
                    ? (string)__('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                    : (string)__('该地址 %{1} 已被站点「%{2}」使用', [$addr, $conflict['website_name']])
            );
        }

        $primaryUrl = 'https://' . $address['domain'] . $subPath;
        $addressList = [$address];
        $connection = $this->website->getConnection();
        $websiteId = $this->transactions->runWrite(
            $connection,
            function () use ($connection, $name, $code, $primaryUrl, $timezone, $addressList, $params): int {
                $this->cacheInvalidation->beginDeferred($connection);
                $newWebsite = ObjectManager::getInstance(Website::class, [], false);
                $newWebsite->setConnection($connection);
                $newWebsite->clearData()
                    ->setData(Website::schema_fields_NAME, $name)
                    ->setData(Website::schema_fields_CODE, $code)
                    ->setData(Website::schema_fields_URL, $primaryUrl)
                    ->setData(Website::schema_fields_DEFAULT_TIMEZONE, $timezone);
                if ($newWebsite->hasData(Website::schema_fields_ID)) {
                    $newWebsite->unsetData(Website::schema_fields_ID);
                }
                $newWebsite->save(true);
                $websiteId = (int)$newWebsite->getId();
                if ($websiteId <= Website::ID_DEFAULT) {
                    throw new \RuntimeException((string)__('网站保存失败，未能获取网站ID'));
                }
                $this->saveWebsiteDomains($connection, $websiteId, $addressList);
                $this->websiteCurrency->setConnection($connection);
                $this->websiteCurrency->setWebsiteCurrencies($websiteId, []);
                $this->websiteLanguage->setConnection($connection);
                $this->websiteLanguage->setWebsiteLanguages($websiteId, []);
                $this->events->dispatch('Weline_Websites::website_save_after', [
                    'website_id' => $websiteId,
                    'website' => $newWebsite->getData(),
                    'post_data' => $params,
                    'address_list' => $addressList,
                    'action' => 'add',
                    'connection' => $connection,
                ]);
                $after = $this->snapshots->capture($websiteId, $connection);
                if ($after === null) {
                    throw new \RuntimeException((string)__('网站保存后快照不存在'));
                }
                $this->publishWebsiteChange($connection, $websiteId, $after);
                return $websiteId;
            },
        );

        $row = $this->website->clearQuery()->clearData()
            ->where(Website::schema_fields_ID, $websiteId)
            ->find()
            ->fetchArray();
        if (!\is_array($row)) {
            throw new \RuntimeException((string)__('网站创建后无法回读'));
        }

        return [
            'success' => true,
            'message' => (string)__('站点创建成功'),
            'website' => [
                'website_id' => $websiteId,
                'name' => (string)($row[Website::schema_fields_NAME] ?? $name),
                'code' => (string)($row[Website::schema_fields_CODE] ?? $code),
                'url' => (string)($row[Website::schema_fields_URL] ?? $primaryUrl),
                'default_currency' => (string)($row[Website::schema_fields_DEFAULT_CURRENCY] ?? ''),
                'default_language' => (string)($row[Website::schema_fields_DEFAULT_LANGUAGE] ?? ''),
                'default_timezone' => (string)($row[Website::schema_fields_DEFAULT_TIMEZONE] ?? $timezone),
                'scope' => (string)($row[Website::schema_fields_SCOPE] ?? ''),
            ],
        ];
    }

    /**
     * @return array{domain:string,sub_path:string,pool_id:int}
     */
    public static function parsePrimaryAddress(string $url): array
    {
        $line = \trim($url);
        $line = (string)\preg_replace('#^https?://#i', '', $line);
        $line = \trim($line, "/ \t");
        if ($line === '') {
            throw new \InvalidArgumentException((string)__('请填写网站地址'));
        }
        $pos = \strpos($line, '/');
        if ($pos === false) {
            $domain = \strtolower($line);
            $subPath = '';
        } else {
            $domain = \strtolower(\substr($line, 0, $pos));
            $subPath = '/' . \trim(\substr($line, $pos), '/');
            if ($subPath === '/') {
                $subPath = '';
            }
        }
        $domain = \preg_replace('/:\d+$/', '', $domain) ?? $domain;
        $loopback = $domain === 'localhost' || $domain === '127.0.0.1';
        if ($domain === '' || (!$loopback && !\str_contains($domain, '.'))) {
            throw new \InvalidArgumentException((string)__('网站地址不是有效域名'));
        }

        return [
            'domain' => $domain,
            'sub_path' => $subPath,
            'pool_id' => 0,
        ];
    }

    public static function codeFromDomain(string $domain, string $subPath = ''): string
    {
        $code = \str_replace('.', '_', \strtolower(\trim($domain)));
        $slug = \trim(\str_replace('/', '_', $subPath), '_');
        if ($slug !== '') {
            $code .= '_' . $slug;
        }
        $code = (string)\preg_replace('/[^a-z0-9_]/', '_', $code);

        return \trim($code, '_') ?: 'site';
    }

    private function assertCodeAvailable(string $code): void
    {
        if ($code === '' || $code === Website::CODE_DEFAULT) {
            throw new \InvalidArgumentException((string)__('站点代码已存在'));
        }
        $existing = clone $this->website;
        $existing->clearQuery()->clearData()
            ->where(Website::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        if ($existing->hasData(Website::schema_fields_ID)) {
            throw new \InvalidArgumentException((string)__('站点代码已存在'));
        }
    }

    /**
     * @param list<array{domain:string,sub_path:string,pool_id?:int}> $addressList
     */
    private function saveWebsiteDomains(ConnectionFactory $connection, int $websiteId, array $addressList): void
    {
        $model = ObjectManager::getInstance(WebsiteDomain::class, [], false);
        $model->setConnection($connection);
        $model->clearQuery()
            ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        $isFirst = true;
        foreach ($addressList as $item) {
            $newDomain = ObjectManager::getInstance(WebsiteDomain::class, [], false);
            $newDomain->setConnection($connection);
            $newDomain->setWebsiteId($websiteId);
            $poolId = (int)($item['pool_id'] ?? 0);
            if ($poolId > 0) {
                $newDomain->setPoolId($poolId);
                $newDomain->syncFromPool();
            } else {
                $newDomain->setDomain($item['domain']);
            }
            $newDomain->setSubPath((string)$item['sub_path']);
            $newDomain->setIsPrimary($isFirst);
            $newDomain->setStatus(WebsiteDomain::STATUS_ACTIVE);
            $newDomain->save();
            $isFirst = false;
        }
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->setConnection($connection);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
    }

    /** @param array<string, mixed> $after */
    private function publishWebsiteChange(ConnectionFactory $connection, int $websiteId, array $after): void
    {
        $websiteCode = \trim((string)($after[Website::schema_fields_CODE] ?? ''));
        if ($websiteCode === '') {
            throw new \LogicException((string)__('网站资源变更缺少 website code'));
        }
        $impact = $this->snapshots->impact(null, $after);
        $revision = ObjectManager::getInstance(ResourceRevisionService::class)->next('website', $websiteId);
        $change = ObjectManager::getInstance(ResourceChangeFactory::class)->create(
            resourceType: 'website',
            resourceId: $websiteId,
            action: 'upsert',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $websiteCode,
            before: [],
            after: $after,
            changedFields: $this->snapshots->changedFields(null, $after),
            impact: $impact,
            origin: ['entry' => 'website.create'],
            previousWebsiteCode: null,
            siteId: $websiteId,
        );
        w_changed($change);
        $this->cacheInvalidation->flushDeferred(
            $connection,
            \array_values(\array_unique(\array_merge(
                $impact['namespaces'],
                $impact['previous_namespaces'],
            ))),
        );
    }
}
