<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '平台模块表')]
#[Index(name: 'idx_name', columns: ['name'], type: 'UNIQUE', comment: '模块名唯一索引')]
#[Index(name: 'idx_developer_id', columns: ['developer_id'], type: 'KEY', comment: '开发者ID索引')]
#[Index(name: 'idx_category_id', columns: ['category_id'], type: 'KEY', comment: '分类ID索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
class PlatformModule extends Model
{
    public const schema_table = 'weline_platform_module';
    public const schema_primary_key = 'module_id';

    // 模块状态常量
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    // 定价类型常量
    public const PRICING_FREE = 'free';
    public const PRICING_ONE_TIME = 'one_time';
    public const PRICING_SUBSCRIPTION = 'subscription';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '模块ID')]
    public const schema_fields_ID = 'module_id';

    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模块名 (Vendor_Module)')]
    public const schema_fields_name = 'name';

    #[Col(type: 'varchar', length: 150, nullable: false, comment: '显示名称')]
    public const schema_fields_display_name = 'display_name';

    #[Col(type: 'text', nullable: true, comment: '模块描述')]
    public const schema_fields_description = 'description';

    #[Col(type: 'text', nullable: true, comment: '详细说明')]
    public const schema_fields_detail = 'detail';

    #[Col(type: 'int', nullable: false, comment: '开发者ID')]
    public const schema_fields_developer_id = 'developer_id';

    #[Col(type: 'int', nullable: true, comment: '分类ID')]
    public const schema_fields_category_id = 'category_id';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '模块图标')]
    public const schema_fields_icon = 'icon';

    #[Col(type: 'text', nullable: true, comment: '截图 (JSON数组)')]
    public const schema_fields_images = 'images';

    #[Col(type: 'varchar', length: 20, nullable: false, comment: '当前版本')]
    public const schema_fields_current_version = 'current_version';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::STATUS_DRAFT, comment: '状态')]
    public const schema_fields_status = 'status';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::PRICING_FREE, comment: '定价类型')]
    public const schema_fields_pricing_type = 'pricing_type';

    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0, comment: '价格')]
    public const schema_fields_price = 'price';

    #[Col(type: 'varchar', length: 20, nullable: true, comment: '订阅周期 (monthly/yearly)')]
    public const schema_fields_subscription_cycle = 'subscription_cycle';

    #[Col(type: 'int', nullable: false, default: 0, comment: '下载次数')]
    public const schema_fields_downloads = 'downloads';

    #[Col(type: 'decimal', length: '3,2', nullable: false, default: 0, comment: '评分')]
    public const schema_fields_rating = 'rating';

    #[Col(type: 'int', nullable: false, default: 0, comment: '评分人数')]
    public const schema_fields_rating_count = 'rating_count';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '源码仓库URL')]
    public const schema_fields_repository_url = 'repository_url';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '文档URL')]
    public const schema_fields_documentation_url = 'documentation_url';

    #[Col(type: 'text', nullable: true, comment: '标签 (JSON数组)')]
    public const schema_fields_tags = 'tags';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getModuleId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setModuleId(int $moduleId): static
    {
        $this->setData(self::schema_fields_ID, $moduleId);
        return $this;
    }

    public function getName(): string
    {
        return $this->getData(self::schema_fields_name) ?? '';
    }

    public function setName(string $name): static
    {
        $this->setData(self::schema_fields_name, $name);
        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->getData(self::schema_fields_display_name) ?? '';
    }

    public function setDisplayName(string $displayName): static
    {
        $this->setData(self::schema_fields_display_name, $displayName);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->getData(self::schema_fields_description);
    }

    public function setDescription(?string $description): static
    {
        $this->setData(self::schema_fields_description, $description);
        return $this;
    }

    public function getDetail(): ?string
    {
        return $this->getData(self::schema_fields_detail);
    }

    public function setDetail(?string $detail): static
    {
        $this->setData(self::schema_fields_detail, $detail);
        return $this;
    }

    public function getDeveloperId(): int
    {
        return (int)$this->getData(self::schema_fields_developer_id);
    }

    public function setDeveloperId(int $developerId): static
    {
        $this->setData(self::schema_fields_developer_id, $developerId);
        return $this;
    }

    public function getCategoryId(): ?int
    {
        return $this->getData(self::schema_fields_category_id) ? (int)$this->getData(self::schema_fields_category_id) : null;
    }

    public function setCategoryId(?int $categoryId): static
    {
        $this->setData(self::schema_fields_category_id, $categoryId);
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->getData(self::schema_fields_icon);
    }

    public function setIcon(?string $icon): static
    {
        $this->setData(self::schema_fields_icon, $icon);
        return $this;
    }

    public function getImages(): array
    {
        $images = $this->getData(self::schema_fields_images);
        return $images ? json_decode($images, true) : [];
    }

    public function setImages(array $images): static
    {
        $this->setData(self::schema_fields_images, json_encode($images, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function getCurrentVersion(): string
    {
        return $this->getData(self::schema_fields_current_version) ?? '1.0.0';
    }

    public function setCurrentVersion(string $version): static
    {
        $this->setData(self::schema_fields_current_version, $version);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? self::STATUS_DRAFT;
    }

    public function setStatus(string $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }

    public function getPricingType(): string
    {
        return $this->getData(self::schema_fields_pricing_type) ?? self::PRICING_FREE;
    }

    public function setPricingType(string $pricingType): static
    {
        $this->setData(self::schema_fields_pricing_type, $pricingType);
        return $this;
    }

    public function getPrice(): float
    {
        return (float)$this->getData(self::schema_fields_price);
    }

    public function setPrice(float $price): static
    {
        $this->setData(self::schema_fields_price, $price);
        return $this;
    }

    public function getSubscriptionCycle(): ?string
    {
        return $this->getData(self::schema_fields_subscription_cycle);
    }

    public function setSubscriptionCycle(?string $cycle): static
    {
        $this->setData(self::schema_fields_subscription_cycle, $cycle);
        return $this;
    }

    public function getDownloads(): int
    {
        return (int)$this->getData(self::schema_fields_downloads);
    }

    public function setDownloads(int $downloads): static
    {
        $this->setData(self::schema_fields_downloads, $downloads);
        return $this;
    }

    public function incrementDownloads(): static
    {
        $this->setDownloads($this->getDownloads() + 1);
        return $this;
    }

    public function getRating(): float
    {
        return (float)$this->getData(self::schema_fields_rating);
    }

    public function setRating(float $rating): static
    {
        $this->setData(self::schema_fields_rating, $rating);
        return $this;
    }

    public function getRatingCount(): int
    {
        return (int)$this->getData(self::schema_fields_rating_count);
    }

    public function setRatingCount(int $count): static
    {
        $this->setData(self::schema_fields_rating_count, $count);
        return $this;
    }

    public function getRepositoryUrl(): ?string
    {
        return $this->getData(self::schema_fields_repository_url);
    }

    public function setRepositoryUrl(?string $url): static
    {
        $this->setData(self::schema_fields_repository_url, $url);
        return $this;
    }

    public function getDocumentationUrl(): ?string
    {
        return $this->getData(self::schema_fields_documentation_url);
    }

    public function setDocumentationUrl(?string $url): static
    {
        $this->setData(self::schema_fields_documentation_url, $url);
        return $this;
    }

    public function getTags(): array
    {
        $tags = $this->getData(self::schema_fields_tags);
        return $tags ? json_decode($tags, true) : [];
    }

    public function setTags(array $tags): static
    {
        $this->setData(self::schema_fields_tags, json_encode($tags, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function isFree(): bool
    {
        return $this->getPricingType() === self::PRICING_FREE;
    }

    public function isPublished(): bool
    {
        return $this->getStatus() === self::STATUS_PUBLISHED;
    }
}
