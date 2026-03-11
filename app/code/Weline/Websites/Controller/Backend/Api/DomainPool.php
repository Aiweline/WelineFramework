<?php

declare(strict_types=1);

/**
 * Weline Websites - 域名池 API 接口
 * 
 * 提供域名池数据查询接口，用于域名选择器组件
 * 查询的是 DomainPool 模型（可建站的具体域名），而非 Domain 模型（根域名）
 */

namespace Weline\Websites\Controller\Backend\Api;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Websites\Model\DomainPool as DomainPoolModel;

#[Acl('Weline_Websites::domain_pool_api', '域名池API', 'mdi-api', '域名池数据查询接口', 'Weline_Websites::domain_service')]
class DomainPool extends BaseController
{
    private DomainPoolModel $domainPoolModel;
    
    public function __construct(DomainPoolModel $domainPoolModel)
    {
        $this->domainPoolModel = $domainPoolModel;
    }
    
    /**
     * 获取域名池列表
     * 
     * GET /websites/backend/api/domain-pool
     * 
     * 参数：
     * - search: 搜索关键词
     * - limit: 返回数量限制（默认 50）
     * - grouped: 是否按根域名分组（默认 true）
     * - site_ready: 是否只返回可建站域名（默认 true）
     * - parent_domain_id: 按根域名ID筛选
     * 
     * @return string JSON 响应
     */
    #[Acl('Weline_Websites::domain_pool_api_list', '获取域名池列表', 'mdi-format-list-bulleted', '获取域名池数据列表')]
    public function index(): string
    {
        try {
            $search = $this->request->getGet('search', '');
            $limit = (int) $this->request->getGet('limit', 50);
            $grouped = $this->request->getGet('grouped', 'true') === 'true';
            $siteReadyOnly = $this->request->getGet('site_ready', 'true') === 'true';
            $parentDomainId = (int) $this->request->getGet('parent_domain_id', 0);
            
            $model = clone $this->domainPoolModel;
            $model->clearQuery()
                ->where(DomainPoolModel::schema_fields_STATUS, DomainPoolModel::STATUS_ACTIVE);
            
            // 只返回可建站且未已建站的域名（创建站点时选择）
            if ($siteReadyOnly) {
                $model->where(DomainPoolModel::schema_fields_SITE_READY, 1);
                $model->whereRaw(
                    '(' . DomainPoolModel::schema_fields_SITE_CREATED . ' IS NULL OR ' . DomainPoolModel::schema_fields_SITE_CREATED . ' = 0)',
                    'AND'
                );
            }
            
            // 按根域名ID筛选
            if ($parentDomainId > 0) {
                $model->where(DomainPoolModel::schema_fields_PARENT_DOMAIN_ID, $parentDomainId);
            }
            
            // 搜索过滤
            if ($search) {
                $searchPattern = '%' . $search . '%';
                $model->where(DomainPoolModel::schema_fields_DOMAIN, $searchPattern, 'LIKE');
            }
            
            // 排序
            $model->order(DomainPoolModel::schema_fields_ROOT_DOMAIN, 'ASC')
                ->order(DomainPoolModel::schema_fields_DOMAIN, 'ASC');
            
            // 限制数量
            if ($limit > 0) {
                $model->limit($limit);
            }
            
            $domains = $model->select()->fetchArray();
            
            // 是否按根域名分组
            if ($grouped) {
                $result = [];
                foreach ($domains as $domain) {
                    $rootDomain = $domain[DomainPoolModel::schema_fields_ROOT_DOMAIN] ?: $domain[DomainPoolModel::schema_fields_DOMAIN];
                    
                    if (!isset($result[$rootDomain])) {
                        $result[$rootDomain] = [
                            'label' => $rootDomain,
                            'options' => []
                        ];
                    }
                    $result[$rootDomain]['options'][] = $this->formatDomainData($domain);
                }
                // 按根域名排序
                ksort($result);
                $data = array_values($result);
            } else {
                $data = array_map([$this, 'formatDomainData'], $domains);
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
                'total' => count($domains),
            ]);
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (DEV) {
                $errorMsg .= "\n[File] " . $e->getFile() . ':' . $e->getLine();
            }
            return $this->fetchJson([
                'success' => false,
                'message' => $errorMsg,
                'data' => [],
            ]);
        }
    }
    
    /**
     * 格式化域名数据
     */
    private function formatDomainData(array $domain): array
    {
        return [
            'pool_id' => $domain[DomainPoolModel::schema_fields_ID] ?? 0,
            'parent_domain_id' => $domain[DomainPoolModel::schema_fields_PARENT_DOMAIN_ID] ?? 0,
            'domain' => $domain[DomainPoolModel::schema_fields_DOMAIN] ?? '',
            'root_domain' => $domain[DomainPoolModel::schema_fields_ROOT_DOMAIN] ?? '',
            'status' => $domain[DomainPoolModel::schema_fields_STATUS] ?? '',
            'resolve_status' => $domain[DomainPoolModel::schema_fields_RESOLVE_STATUS] ?? 'pending',
            'resolved_ip' => $domain[DomainPoolModel::schema_fields_RESOLVED_IP] ?? '',
            'is_local_server' => (int) ($domain[DomainPoolModel::schema_fields_IS_LOCAL_SERVER] ?? 0),
            'https_status' => $domain[DomainPoolModel::schema_fields_HTTPS_STATUS] ?? 'none',
            'https_expires_at' => $domain[DomainPoolModel::schema_fields_HTTPS_EXPIRES_AT] ?? '',
            'site_ready' => (int) ($domain[DomainPoolModel::schema_fields_SITE_READY] ?? 0),
            'site_created' => (int) ($domain[DomainPoolModel::schema_fields_SITE_CREATED] ?? 0),
            'description' => $domain[DomainPoolModel::schema_fields_DESCRIPTION] ?? '',
        ];
    }
    
    /**
     * 添加子域名到域名池
     * 
     * POST /websites/backend/api/domain-pool/add
     * 
     * @return string JSON 响应
     */
    #[Acl('Weline_Websites::domain_pool_api_add', '添加子域名', 'mdi-plus', '添加子域名到域名池')]
    public function postAdd(): string
    {
        try {
            $domain = trim($this->request->getPost('domain', ''));
            $parentDomainId = (int) $this->request->getPost('parent_domain_id', 0);
            $description = trim($this->request->getPost('description', ''));
            
            if (empty($domain)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('域名不能为空'),
                ]);
            }
            
            // 检查是否已存在
            $existing = clone $this->domainPoolModel;
            $existing->clearQuery()
                ->where(DomainPoolModel::schema_fields_DOMAIN, strtolower($domain))
                ->find()
                ->fetch();
            
            if ($existing->getPoolId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('域名 %{1} 已存在于域名池中', [$domain]),
                ]);
            }
            
            // 自动推断 root_domain
            $parts = explode('.', strtolower($domain));
            $rootDomain = count($parts) >= 2 
                ? implode('.', array_slice($parts, -2)) 
                : $domain;
            
            // 创建新记录
            $newPool = \Weline\Framework\Manager\ObjectManager::getInstance(DomainPoolModel::class, [], false);
            $newPool->setDomain(strtolower($domain));
            $newPool->setRootDomain($rootDomain);
            $newPool->setParentDomainId($parentDomainId);
            $newPool->setDescription($description);
            $newPool->setStatus(DomainPoolModel::STATUS_ACTIVE);
            $newPool->setResolveStatus(DomainPoolModel::RESOLVE_STATUS_PENDING);
            $newPool->setHttpsStatus(DomainPoolModel::HTTPS_STATUS_NONE);
            $newPool->setSiteReady(false);
            $newPool->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('子域名添加成功'),
                'data' => $this->formatDomainData($newPool->getData()),
            ]);
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (DEV) {
                $errorMsg .= "\n[File] " . $e->getFile() . ':' . $e->getLine();
            }
            return $this->fetchJson([
                'success' => false,
                'message' => __('添加失败：%{1}', [$errorMsg]),
            ]);
        }
    }
    
    /**
     * 删除域名池中的子域名
     * 
     * POST /websites/backend/api/domain-pool/delete
     * 
     * @return string JSON 响应
     */
    #[Acl('Weline_Websites::domain_pool_api_delete', '删除子域名', 'mdi-delete', '从域名池删除子域名')]
    public function postDelete(): string
    {
        try {
            $poolId = (int) $this->request->getPost('pool_id', 0);
            
            if ($poolId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('pool_id 不能为空'),
                ]);
            }
            
            $pool = clone $this->domainPoolModel;
            $pool->loadByPoolId($poolId);
            
            if (!$pool->getPoolId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('域名不存在'),
                ]);
            }
            
            // 检查是否有网站正在使用
            $websiteDomainModel = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Websites\Model\WebsiteDomain::class
            );
            $usage = $websiteDomainModel->clearQuery()
                ->where(\Weline\Websites\Model\WebsiteDomain::schema_fields_POOL_ID, $poolId)
                ->find()
                ->fetch();
            
            if ($usage->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('该域名正在被网站使用，无法删除'),
                ]);
            }
            
            $pool->delete()->fetch();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('删除成功'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
    
    /**
     * 触发域名池解析检测
     * 
     * POST /websites/backend/api/domain-pool/check-resolve
     * 
     * @return string JSON 响应
     */
    #[Acl('Weline_Websites::domain_pool_api_check', '检测解析', 'mdi-refresh', '检测域名池解析状态')]
    public function postCheckResolve(): string
    {
        try {
            $poolId = (int) $this->request->getPost('pool_id', 0);
            
            if ($poolId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('pool_id 不能为空'),
                ]);
            }
            
            $pool = clone $this->domainPoolModel;
            $pool->loadByPoolId($poolId);
            
            if (!$pool->getPoolId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('域名不存在'),
                ]);
            }
            
            // 调用解析检测服务
            $resolveService = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Websites\Service\DomainPoolResolveService::class
            );
            
            $result = $resolveService->checkResolve($pool);
            
            return $this->fetchJson([
                'success' => true,
                'message' => $result['resolved'] 
                    ? __('解析正常，IP: %{1}', [$result['ipv4'] ?: $result['ipv6'] ?? '']) 
                    : __('解析异常：%{1}', [$result['error'] ?? __('未知错误')]),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('检测失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
    
    /**
     * 根据根域名 ID 获取子域名列表
     * 
     * GET /websites/backend/api/domain-pool/by-parent
     * 
     * @return string JSON 响应
     */
    #[Acl('Weline_Websites::domain_pool_api_by_parent', '按根域名查询', 'mdi-tree', '按根域名ID获取子域名')]
    public function byParent(): string
    {
        try {
            $parentDomainId = (int) $this->request->getGet('parent_domain_id', 0);
            
            if ($parentDomainId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('parent_domain_id 不能为空'),
                ]);
            }
            
            $domains = $this->domainPoolModel->getByParentDomainId($parentDomainId);
            $data = array_map([$this, 'formatDomainData'], $domains);
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
                'total' => count($data),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
