<?php
declare(strict_types=1);

/**
 * Weline Websites - 子域名生成服务
 *
 * 负责根据根域名自动生成默认子域名并添加到域名池
 * 支持同步根域时自动创建默认子域名（如 @ 和 www）
 */

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;

class SubdomainGeneratorService
{
    private DomainPool $domainPool;

    public function __construct(DomainPool $domainPool)
    {
        $this->domainPool = $domainPool;
    }

    /**
     * 规范化子域前缀，防止 JSON 字符串（如 '["@","www"]'）被错误当作单个前缀导致生成 ["@","www"].example.com
     *
     * @param array $prefixes 可能含 JSON 字符串或嵌套数组的原始输入
     * @return array<string> 扁平化的有效前缀列表
     */
    private function normalizePrefixes(array $prefixes): array
    {
        $result = [];
        foreach ($prefixes as $p) {
            if (\is_array($p)) {
                $result = \array_merge($result, $this->normalizePrefixes($p));
                continue;
            }
            $s = \trim((string) $p);
            if ($s === '') {
                continue;
            }
            if (\str_starts_with($s, '[') && \str_ends_with($s, ']')) {
                $decoded = \json_decode($s, true);
                if (\is_array($decoded)) {
                    $result = \array_merge($result, $this->normalizePrefixes($decoded));
                    continue;
                }
            }
            if ($s === '@' || \preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*$/i', $s)) {
                $result[] = $s === '@' ? '@' : \strtolower($s);
            }
        }
        return \array_values(\array_unique($result));
    }

    /**
     * 为根域名生成默认子域名并添加到域名池
     *
     * @param Domain $rootDomain 根域名模型
     * @param array $prefixes 子域名前缀列表，默认 ['@', 'www']，支持 JSON 字符串会被正确解析
     * @return array{added: int, skipped: int, errors: array}
     */
    public function generateDefaultSubdomains(Domain $rootDomain, array $prefixes = []): array
    {
        $prefixes = $prefixes === [] ? $this->getDefaultPrefixes() : $this->normalizePrefixes($prefixes);
        if ($prefixes === []) {
            $prefixes = $this->getDefaultPrefixes();
        }

        $rootDomainName = $rootDomain->getDomain();
        $parentDomainId = $rootDomain->getDomainId();

        $added = 0;
        $skipped = 0;
        $errors = [];

        foreach ($prefixes as $prefix) {
            $prefix = \trim($prefix);
            if ($prefix === '') {
                continue;
            }

            // 构建完整域名
            if ($prefix === '@' || $prefix === '') {
                $fullDomain = $rootDomainName;
            } else {
                $fullDomain = $prefix . '.' . $rootDomainName;
            }
            $fullDomain = \strtolower($fullDomain);

            // 检查是否已存在
            $existing = ObjectManager::getInstance(DomainPool::class, [], false);
            $existing->loadByDomain($fullDomain);
            
            if ($existing->getPoolId()) {
                $skipped++;
                continue;
            }

            // 创建新的域名池记录
            try {
                $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolDomain->setDomain($fullDomain);
                $poolDomain->setParentDomainId($parentDomainId);
                $poolDomain->setDescription(__('从根域名 %{1} 自动生成', [$rootDomainName]));
                $poolDomain->setStatus(DomainPool::STATUS_ACTIVE);
                $poolDomain->setResolveStatus(DomainPool::RESOLVE_STATUS_PENDING);
                $poolDomain->setDnsStatus(DomainPool::INFRA_STATUS_PENDING);
                $poolDomain->setCdnStatus(DomainPool::INFRA_STATUS_PENDING);
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
                $poolDomain->setSiteReady(false);
                $poolDomain->save();
                $added++;
            } catch (\Throwable $e) {
                $errors[] = $fullDomain . ': ' . $e->getMessage();
            }
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * 为根域名添加单个子域名到域名池
     *
     * @param int $parentDomainId 根域名 ID
     * @param string $subdomain 子域名（前缀或完整域名）
     * @param string $description 描述
     * @return array{success: bool, pool_id: int, message: string}
     */
    public function addSubdomain(int $parentDomainId, string $subdomain, string $description = ''): array
    {
        $subdomain = \strtolower(\trim($subdomain));
        if ($subdomain === '') {
            return [
                'success' => false,
                'pool_id' => 0,
                'message' => __('子域名不能为空'),
            ];
        }

        // 如果只是前缀，需要拼接根域名
        if (\strpos($subdomain, '.') === false) {
            $rootDomain = ObjectManager::getInstance(Domain::class, [], false);
            $rootDomain->load($parentDomainId);
            
            if (!$rootDomain->getDomainId()) {
                return [
                    'success' => false,
                    'pool_id' => 0,
                    'message' => __('找不到根域名'),
                ];
            }
            
            $subdomain = $subdomain . '.' . $rootDomain->getDomain();
        }

        // 检查是否已存在
        $existing = ObjectManager::getInstance(DomainPool::class, [], false);
        $existing->loadByDomain($subdomain);
        
        if ($existing->getPoolId()) {
            return [
                'success' => false,
                'pool_id' => $existing->getPoolId(),
                'message' => __('域名 %{1} 已存在于域名池', [$subdomain]),
            ];
        }

        try {
            $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
            $poolDomain->setDomain($subdomain);
            $poolDomain->setParentDomainId($parentDomainId);
            $poolDomain->setDescription($description);
            $poolDomain->setStatus(DomainPool::STATUS_ACTIVE);
            $poolDomain->setResolveStatus(DomainPool::RESOLVE_STATUS_PENDING);
            $poolDomain->setDnsStatus(DomainPool::INFRA_STATUS_PENDING);
            $poolDomain->setCdnStatus(DomainPool::INFRA_STATUS_PENDING);
            $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
            $poolDomain->setSiteReady(false);
            $poolDomain->save();

            return [
                'success' => true,
                'pool_id' => $poolDomain->getPoolId(),
                'message' => __('子域名 %{1} 已添加到域名池', [$subdomain]),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'pool_id' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 批量为多个根域名生成默认子域名
     *
     * @param array $domainIds 根域名 ID 数组
     * @param array $prefixes 子域名前缀列表
     * @return array{total_added: int, total_skipped: int, details: array}
     */
    public function batchGenerateSubdomains(array $domainIds, array $prefixes = []): array
    {
        $totalAdded = 0;
        $totalSkipped = 0;
        $details = [];

        foreach ($domainIds as $domainId) {
            $rootDomain = ObjectManager::getInstance(Domain::class, [], false);
            $rootDomain->load($domainId);
            
            if (!$rootDomain->getDomainId()) {
                continue;
            }

            $result = $this->generateDefaultSubdomains($rootDomain, $prefixes);
            $totalAdded += $result['added'];
            $totalSkipped += $result['skipped'];
            $details[$rootDomain->getDomain()] = $result;
        }

        return [
            'total_added' => $totalAdded,
            'total_skipped' => $totalSkipped,
            'details' => $details,
        ];
    }

    /**
     * 获取根域名下的所有子域名
     *
     * @param int $parentDomainId 根域名 ID
     * @return array
     */
    public function getSubdomains(int $parentDomainId): array
    {
        return $this->domainPool->getByParentDomainId($parentDomainId);
    }

    /**
     * 获取默认的子域名前缀
     */
    public function getDefaultPrefixes(): array
    {
        return ['@', 'www'];
    }
}
