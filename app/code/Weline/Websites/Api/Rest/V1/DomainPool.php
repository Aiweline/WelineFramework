<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool as DomainPoolModel;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\DomainPoolFlowLogService;
use Weline\Websites\Model\DomainPoolFlowLog;

#[Acl('Weline_Websites::rest_v1_domain_pool', '域名池REST接口', 'mdi-api', 'Websites 域名池 REST V1 接口', 'Weline_Websites::domain_service')]
class DomainPool extends BackendRestController
{
    public function __construct(
        private readonly DomainPoolModel $domainPoolModel
    ) {
        parent::__construct();
    }

    #[Acl('Weline_Websites::rest_v1_domain_pool_list', '域名池列表', 'mdi-format-list-bulleted')]
    public function postList(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $search = \trim((string)($params['search'] ?? $this->request->getParam('search', '')));
            $limit = (int)($params['limit'] ?? $this->request->getParam('limit', 50));
            $grouped = $this->toBool($params['grouped'] ?? $this->request->getParam('grouped', true), true);
            $siteReadyOnly = $this->toBool($params['site_ready'] ?? $this->request->getParam('site_ready', true), true);
            $parentDomainId = (int)($params['parent_domain_id'] ?? $this->request->getParam('parent_domain_id', 0));
            $websiteId = (int)($params['website_id'] ?? $this->request->getParam('website_id', 0));
            $poolIdsRaw = (string)($params['pool_ids'] ?? $this->request->getParam('pool_ids', ''));
            $includePoolIds = $this->parsePoolIds($poolIdsRaw, (array)($params['pool_ids'] ?? []));

            $model = clone $this->domainPoolModel;
            $model->clearQuery()->where(DomainPoolModel::schema_fields_STATUS, DomainPoolModel::STATUS_ACTIVE);

            $selectedPoolIds = [];
            if ($websiteId > 0) {
                $selectedRows = ObjectManager::getInstance(WebsiteDomain::class)
                    ->clearQuery()
                    ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(WebsiteDomain::schema_fields_POOL_ID, 0, '>')
                    ->select()
                    ->fetchArray();
                $selectedPoolIds = \array_values(\array_unique(\array_map(
                    static fn(array $row): int => (int)($row[WebsiteDomain::schema_fields_POOL_ID] ?? 0),
                    $selectedRows
                )));
                $selectedPoolIds = \array_values(\array_filter($selectedPoolIds, static fn(int $id): bool => $id > 0));
            }

            $forceIncludePoolIds = \array_values(\array_unique(\array_merge($includePoolIds, $selectedPoolIds)));
            $needPhpFilterSiteReady = false;
            if ($siteReadyOnly) {
                if ($forceIncludePoolIds === []) {
                    $model->where(DomainPoolModel::schema_fields_SITE_READY, 1)
                        ->where(DomainPoolModel::schema_fields_SITE_CREATED, 0);
                } else {
                    $needPhpFilterSiteReady = true;
                }
            }

            if ($parentDomainId > 0) {
                $model->where(DomainPoolModel::schema_fields_PARENT_DOMAIN_ID, $parentDomainId);
            }
            if ($search !== '') {
                $model->where(DomainPoolModel::schema_fields_DOMAIN, '%' . $search . '%', 'LIKE');
            }
            $model->order(DomainPoolModel::schema_fields_ROOT_DOMAIN, 'ASC')
                ->order(DomainPoolModel::schema_fields_DOMAIN, 'ASC');

            if ($limit > 0) {
                $queryLimit = $needPhpFilterSiteReady ? \min(\max($limit * 5, $limit), 5000) : $limit;
                $model->limit($queryLimit);
            }

            $domains = $model->select()->fetchArray();
            if ($needPhpFilterSiteReady) {
                $domains = \array_values(\array_filter($domains, function (array $domain) use ($forceIncludePoolIds): bool {
                    $poolId = (int)($domain[DomainPoolModel::schema_fields_ID] ?? 0);
                    $siteReady = (int)($domain[DomainPoolModel::schema_fields_SITE_READY] ?? 0) === 1;
                    $siteCreated = (int)($domain[DomainPoolModel::schema_fields_SITE_CREATED] ?? 0) === 1;
                    return ($siteReady && !$siteCreated) || \in_array($poolId, $forceIncludePoolIds, true);
                }));
                if ($limit > 0) {
                    $domains = \array_slice($domains, 0, $limit);
                }
            }

            if ($forceIncludePoolIds !== [] && $search === '') {
                $forcedDomains = (clone $this->domainPoolModel)->clearQuery()
                    ->where(DomainPoolModel::schema_fields_STATUS, DomainPoolModel::STATUS_ACTIVE)
                    ->where(DomainPoolModel::schema_fields_ID, $forceIncludePoolIds, 'IN')
                    ->select()
                    ->fetchArray();
                $merged = [];
                foreach (\array_merge($forcedDomains, $domains) as $domain) {
                    $poolId = (int)($domain[DomainPoolModel::schema_fields_ID] ?? 0);
                    if ($poolId > 0) {
                        $merged[$poolId] = $domain;
                    }
                }
                $domains = \array_values($merged);
            }

            \usort($domains, static function (array $a, array $b): int {
                $aRoot = (string)($a[DomainPoolModel::schema_fields_ROOT_DOMAIN] ?? $a[DomainPoolModel::schema_fields_DOMAIN] ?? '');
                $bRoot = (string)($b[DomainPoolModel::schema_fields_ROOT_DOMAIN] ?? $b[DomainPoolModel::schema_fields_DOMAIN] ?? '');
                $rootCmp = \strcasecmp($aRoot, $bRoot);
                if ($rootCmp !== 0) {
                    return $rootCmp;
                }
                return \strcasecmp(
                    (string)($a[DomainPoolModel::schema_fields_DOMAIN] ?? ''),
                    (string)($b[DomainPoolModel::schema_fields_DOMAIN] ?? '')
                );
            });

            $data = $grouped ? $this->groupByRootDomain($domains) : \array_map([$this, 'formatDomainData'], $domains);
            return $this->success('获取域名池列表成功', ['items' => $data, 'total' => \count($domains)]);
        } catch (\Throwable $e) {
            return $this->exception($e, '获取域名池列表失败');
        }
    }

    #[Acl('Weline_Websites::rest_v1_domain_pool_add', '新增域名池', 'mdi-plus')]
    public function postAdd(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $domain = \strtolower(\trim((string)($params['domain'] ?? $this->request->getParam('domain', ''))));
            $parentDomainId = (int)($params['parent_domain_id'] ?? $this->request->getParam('parent_domain_id', 0));
            $description = \trim((string)($params['description'] ?? $this->request->getParam('description', '')));
            if ($domain === '') {
                return $this->error('domain 不能为空', '', 422);
            }

            $existing = (clone $this->domainPoolModel)->clearQuery()
                ->where(DomainPoolModel::schema_fields_DOMAIN, $domain)
                ->find()
                ->fetch();
            if ($existing->getPoolId()) {
                return $this->error(__('域名 %{1} 已存在于域名池中', [$domain]), '', 409);
            }

            $newPool = ObjectManager::getInstance(DomainPoolModel::class, [], false);
            $newPool->setDomain($domain);
            $newPool->setParentDomainId($parentDomainId);
            $newPool->setDescription($description);
            $newPool->setStatus(DomainPoolModel::STATUS_ACTIVE);
            $newPool->setResolveStatus(DomainPoolModel::RESOLVE_STATUS_PENDING);
            $newPool->setHttpsStatus(DomainPoolModel::HTTPS_STATUS_NONE);
            $newPool->setSiteReady(false);
            $newPool->save();

            if ($newPool->getPoolId() > 0) {
                ObjectManager::getInstance(DomainPoolFlowLogService::class)->append(
                    (int)$newPool->getPoolId(),
                    DomainPoolFlowLog::KIND_POOL_CREATED,
                    __('REST 添加：%{1}', [$domain])
                );
            }

            return $this->success('子域名添加成功', $this->formatDomainData($newPool->getData()));
        } catch (\Throwable $e) {
            return $this->exception($e, '添加域名池记录失败');
        }
    }

    #[Acl('Weline_Websites::rest_v1_domain_pool_delete', '删除域名池', 'mdi-delete')]
    public function postDelete(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $poolId = (int)($params['pool_id'] ?? $this->request->getParam('pool_id', 0));
            if ($poolId <= 0) {
                return $this->error('pool_id 不能为空', '', 422);
            }

            $pool = clone $this->domainPoolModel;
            $pool->loadByPoolId($poolId);
            if (!$pool->getPoolId()) {
                return $this->error('域名不存在', '', 404);
            }

            $usage = ObjectManager::getInstance(WebsiteDomain::class)
                ->clearQuery()
                ->where(WebsiteDomain::schema_fields_POOL_ID, $poolId)
                ->find()
                ->fetch();
            if ($usage->getId()) {
                return $this->error('该域名正在被网站使用，无法删除', '', 409);
            }

            $pool->delete()->fetch();
            return $this->success('删除成功', ['pool_id' => $poolId]);
        } catch (\Throwable $e) {
            return $this->exception($e, '删除域名池记录失败');
        }
    }

    private function groupByRootDomain(array $domains): array
    {
        $result = [];
        foreach ($domains as $domain) {
            $rootDomain = (string)($domain[DomainPoolModel::schema_fields_ROOT_DOMAIN] ?: $domain[DomainPoolModel::schema_fields_DOMAIN]);
            if (!isset($result[$rootDomain])) {
                $result[$rootDomain] = ['label' => $rootDomain, 'options' => []];
            }
            $result[$rootDomain]['options'][] = $this->formatDomainData($domain);
        }
        \ksort($result);
        return \array_values($result);
    }

    private function formatDomainData(array $domain): array
    {
        return [
            'pool_id' => (int)($domain[DomainPoolModel::schema_fields_ID] ?? 0),
            'parent_domain_id' => (int)($domain[DomainPoolModel::schema_fields_PARENT_DOMAIN_ID] ?? 0),
            'domain' => (string)($domain[DomainPoolModel::schema_fields_DOMAIN] ?? ''),
            'root_domain' => (string)($domain[DomainPoolModel::schema_fields_ROOT_DOMAIN] ?? ''),
            'status' => (string)($domain[DomainPoolModel::schema_fields_STATUS] ?? ''),
            'resolve_status' => (string)($domain[DomainPoolModel::schema_fields_RESOLVE_STATUS] ?? 'pending'),
            'resolved_ip' => (string)($domain[DomainPoolModel::schema_fields_RESOLVED_IP] ?? ''),
            'is_local_server' => (int)($domain[DomainPoolModel::schema_fields_IS_LOCAL_SERVER] ?? 0),
            'https_status' => (string)($domain[DomainPoolModel::schema_fields_HTTPS_STATUS] ?? 'none'),
            'https_expires_at' => (string)($domain[DomainPoolModel::schema_fields_HTTPS_EXPIRES_AT] ?? ''),
            'site_ready' => (int)($domain[DomainPoolModel::schema_fields_SITE_READY] ?? 0),
            'site_created' => (int)($domain[DomainPoolModel::schema_fields_SITE_CREATED] ?? 0),
            'description' => (string)($domain[DomainPoolModel::schema_fields_DESCRIPTION] ?? ''),
        ];
    }

    private function parsePoolIds(string $poolIdsRaw, array $poolIdsArray): array
    {
        $poolIds = [];
        if ($poolIdsRaw !== '') {
            foreach (\explode(',', $poolIdsRaw) as $id) {
                $poolId = (int)\trim($id);
                if ($poolId > 0) {
                    $poolIds[] = $poolId;
                }
            }
        }
        foreach ($poolIdsArray as $id) {
            $poolId = (int)$id;
            if ($poolId > 0) {
                $poolIds[] = $poolId;
            }
        }

        return \array_values(\array_unique($poolIds));
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        $v = \strtolower(\trim((string)$value));
        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
