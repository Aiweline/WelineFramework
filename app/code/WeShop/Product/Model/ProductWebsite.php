<?php
declare(strict_types=1);
namespace WeShop\Product\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 产品-站点关联模型
 * 支持产品分配到多个站点，每个站点独立配置 handle（SEO URL）
 */
#[Table(comment: '产品-站点关联表')]
#[Index(name: 'UNQ_PRODUCT_WEBSITE', columns: ['product_id', 'website_id'], type: 'UNIQUE', comment: '产品-站点唯一')]
#[Index(name: 'UNQ_WEBSITE_HANDLE', columns: ['website_id', 'handle'], type: 'UNIQUE', comment: '站点-Handle唯一')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
#[Index(name: 'idx_website_id', columns: ['website_id'], type: 'KEY', comment: '站点ID索引')]
#[Index(name: 'idx_handle', columns: ['handle'], type: 'KEY', comment: 'Handle索引')]
class ProductWebsite extends Model
{
    public const schema_table = 'weshop_product_website';
    public const schema_primary_key = 'product_website_id';
    public string $indexer = 'product_website_indexer';
    public array $_unit_primary_keys = ['product_website_id'];
    public array $_index_sort_keys = ['product_id', 'website_id', 'handle', 'is_active', 'sort_order'];
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '关联ID')]
    public const schema_fields_ID = 'product_website_id';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID（0表示默认/全局）')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'SEO 友好 URL 标识')]
    public const schema_fields_HANDLE = 'handle';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '站点特定的 Meta 标题')]
    public const schema_fields_META_TITLE = 'meta_title';
    #[Col(type: 'text', nullable: true, comment: '站点特定的 Meta 描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
    // ==================== Getters & Setters ====================
    public function getProductWebsiteId(): int { return (int)$this->getData(self::schema_fields_ID); }
    public function getProductId(): int { return (int)$this->getData(self::schema_fields_PRODUCT_ID); }
    public function setProductId(int $productId): self { return $this->setData(self::schema_fields_PRODUCT_ID, $productId); }
    public function getWebsiteId(): int { return (int)$this->getData(self::schema_fields_WEBSITE_ID); }
    public function setWebsiteId(int $websiteId): self { return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId); }
    public function getHandle(): string { return (string)$this->getData(self::schema_fields_HANDLE); }
    public function setHandle(string $handle): self { return $this->setData(self::schema_fields_HANDLE, $handle); }
    public function getIsActive(): bool { return (bool)$this->getData(self::schema_fields_IS_ACTIVE); }
    public function setIsActive(bool $isActive): self { return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0); }
    public function getSortOrder(): int { return (int)$this->getData(self::schema_fields_SORT_ORDER); }
    public function setSortOrder(int $sortOrder): self { return $this->setData(self::schema_fields_SORT_ORDER, $sortOrder); }
    public function getMetaTitle(): ?string { return $this->getData(self::schema_fields_META_TITLE); }
    public function setMetaTitle(?string $metaTitle): self { return $this->setData(self::schema_fields_META_TITLE, $metaTitle); }
    public function getMetaDescription(): ?string { return $this->getData(self::schema_fields_META_DESCRIPTION); }
    public function setMetaDescription(?string $metaDescription): self { return $this->setData(self::schema_fields_META_DESCRIPTION, $metaDescription); }
    // ==================== 业务方法 ====================
    public function getProductIdByWebsiteAndHandle(int $websiteId, string $handle): ?int
    {
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_HANDLE, $handle)
            ->where(self::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        return $this->getProductId() ?: null;
    }
    public function getByProductAndWebsite(int $productId, int $websiteId): ?self
    {
        $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        return $this->getProductWebsiteId() ? $this : null;
    }
    public function getWebsitesByProduct(int $productId): array
    {
        return $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->order(self::schema_fields_SORT_ORDER)
            ->select()
            ->fetchArray();
    }
    public function isHandleAvailable(int $websiteId, string $handle, ?int $excludeProductId = null): bool
    {
        $query = $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_HANDLE, $handle);
        if ($excludeProductId) {
            $query->where(self::schema_fields_PRODUCT_ID, ['<>', $excludeProductId]);
        }
        $query->find()->fetch();
        return !$this->getProductWebsiteId();
    }
    public function saveProductWebsite(int $productId, int $websiteId, string $handle, array $additionalData = []): self
    {
        $existing = $this->getByProductAndWebsite($productId, $websiteId);
        if ($existing) {
            $this->reset()->load($existing->getProductWebsiteId());
        } else {
            $this->reset()->setProductId($productId)->setWebsiteId($websiteId);
        }
        $this->setHandle($handle);
        if (isset($additionalData['is_active'])) { $this->setIsActive((bool)$additionalData['is_active']); }
        if (isset($additionalData['sort_order'])) { $this->setSortOrder((int)$additionalData['sort_order']); }
        if (array_key_exists('meta_title', $additionalData)) { $this->setMetaTitle($additionalData['meta_title']); }
        if (array_key_exists('meta_description', $additionalData)) { $this->setMetaDescription($additionalData['meta_description']); }
        $this->save();
        return $this;
    }
    public function deleteProductWebsite(int $productId, int $websiteId): bool
    {
        $existing = $this->getByProductAndWebsite($productId, $websiteId);
        if ($existing) {
            $this->reset()->load($existing->getProductWebsiteId());
            $this->delete();
            return true;
        }
        return false;
    }
    public function deleteAllByProduct(int $productId): int
    {
        $websites = $this->getWebsitesByProduct($productId);
        $count = 0;
        foreach ($websites as $website) {
            $this->reset()->load($website[self::schema_fields_ID] ?? 0);
            if ($this->getProductWebsiteId()) {
                $this->delete();
                $count++;
            }
        }
        return $count;
    }
}
