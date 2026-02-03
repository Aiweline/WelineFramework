<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池模型
 * 
 * 全局域名池，用于建站时选择域名
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 域名池模型
 * 
 * 全局可选的域名主数据，建站时从池中选择
 */
class DomainPool extends Model
{
    public const fields_ID = 'pool_id';
    public const fields_DOMAIN = 'domain';              // 完整域名（如 www.example.com）
    public const fields_ROOT_DOMAIN = 'root_domain';    // 根域名（如 example.com）
    public const fields_DESCRIPTION = 'description';    // 域名描述/备注
    public const fields_STATUS = 'status';              // 状态：active/disabled
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('域名池表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '域名池ID')
            ->addColumn(self::fields_DOMAIN, TableInterface::column_type_VARCHAR, 255, 'not null', '完整域名')
            ->addColumn(self::fields_ROOT_DOMAIN, TableInterface::column_type_VARCHAR, 255, "default ''", '根域名')
            ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_VARCHAR, 500, "default ''", '域名描述')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, '', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_domain', self::fields_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_root_domain', self::fields_ROOT_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->create();
    }
    
    /**
     * 保存前自动更新时间戳并解析根域名
     */
    public function save_before(): void
    {
        parent::save_before();
        
        $now = \date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);
        
        if (!$this->getData(self::fields_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        
        // 域名转小写并解析根域
        $domain = $this->getData(self::fields_DOMAIN);
        if ($domain) {
            $domain = \strtolower(\trim($domain));
            $this->setData(self::fields_DOMAIN, $domain);
            
            // 使用 PSL 库解析根域名
            $rootDomain = $this->parseRootDomain($domain);
            $this->setData(self::fields_ROOT_DOMAIN, $rootDomain);
        }
    }
    
    /**
     * 使用 DomainParserService 解析根域名
     */
    protected function parseRootDomain(string $domain): string
    {
        try {
            /** @var \Weline\Websites\Service\DomainParserService $parser */
            $parser = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Websites\Service\DomainParserService::class
            );
            return $parser->parseRootDomain($domain);
        } catch (\Throwable $e) {
            // 回退到简单解析
            $parts = \explode('.', $domain);
            if (\count($parts) >= 2) {
                return $parts[\count($parts) - 2] . '.' . $parts[\count($parts) - 1];
            }
            return $domain;
        }
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getPoolId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }
    
    public function setDomain(string $domain): self
    {
        $this->setData(self::fields_DOMAIN, \strtolower(\trim($domain)));
        return $this;
    }
    
    public function getDomain(): string
    {
        return (string) $this->getData(self::fields_DOMAIN);
    }
    
    public function getRootDomain(): string
    {
        return (string) $this->getData(self::fields_ROOT_DOMAIN);
    }
    
    public function setDescription(string $description): self
    {
        $this->setData(self::fields_DESCRIPTION, $description);
        return $this;
    }
    
    public function getDescription(): string
    {
        return (string) $this->getData(self::fields_DESCRIPTION);
    }
    
    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }
    
    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }
    
    // =============== 业务方法 ===============
    
    /**
     * 获取按根域分组的所有可选域名
     * 
     * @return array<string, array> 以根域为键的分组数据
     */
    public function getDomainsGroupedByRoot(): array
    {
        $domains = $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_ROOT_DOMAIN, 'ASC')
            ->order(self::fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
        
        $grouped = [];
        foreach ($domains as $domain) {
            $rootDomain = $domain[self::fields_ROOT_DOMAIN] ?: $domain[self::fields_DOMAIN];
            if (!isset($grouped[$rootDomain])) {
                $grouped[$rootDomain] = [];
            }
            $grouped[$rootDomain][] = $domain;
        }
        
        return $grouped;
    }
    
    /**
     * 根据域名查找记录
     */
    public function loadByDomain(string $domain): self
    {
        $this->clearQuery()
            ->where(self::fields_DOMAIN, \strtolower(\trim($domain)))
            ->find()
            ->fetch();
        return $this;
    }
    
    /**
     * 获取所有活跃域名列表
     */
    public function getActiveDomains(): array
    {
        return $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_ROOT_DOMAIN, 'ASC')
            ->order(self::fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 根据根域获取所有子域名
     */
    public function getDomainsByRoot(string $rootDomain): array
    {
        return $this->clearQuery()
            ->where(self::fields_ROOT_DOMAIN, \strtolower(\trim($rootDomain)))
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 批量添加域名到池
     * 
     * @param array $domains 域名数组
     * @return int 成功添加的数量
     */
    public function addDomainsToPool(array $domains): int
    {
        $added = 0;
        foreach ($domains as $domain) {
            $domainStr = \is_array($domain) ? ($domain['domain'] ?? '') : $domain;
            $description = \is_array($domain) ? ($domain['description'] ?? '') : '';
            
            if (empty($domainStr)) {
                continue;
            }
            
            // 检查是否已存在
            $existing = $this->clearQuery()->loadByDomain($domainStr);
            if ($existing->getPoolId()) {
                continue;
            }
            
            // 添加新域名
            $newModel = clone $this;
            $newModel->clearData();
            $newModel->setDomain($domainStr);
            $newModel->setDescription($description);
            $newModel->setStatus(self::STATUS_ACTIVE);
            $newModel->save();
            $added++;
        }
        
        return $added;
    }
    
    /**
     * 获取域名选择器数据（用于 UI 选择组件）
     * 
     * 返回格式适合前端下拉/标签选择器使用
     * 
     * @return array
     */
    public function getSelectOptions(): array
    {
        $grouped = $this->getDomainsGroupedByRoot();
        $options = [];
        
        foreach ($grouped as $rootDomain => $domains) {
            $group = [
                'label' => $rootDomain,
                'options' => []
            ];
            
            foreach ($domains as $domain) {
                $group['options'][] = [
                    'value' => $domain[self::fields_DOMAIN],
                    'label' => $domain[self::fields_DOMAIN],
                    'description' => $domain[self::fields_DESCRIPTION] ?? ''
                ];
            }
            
            $options[] = $group;
        }
        
        return $options;
    }
}
