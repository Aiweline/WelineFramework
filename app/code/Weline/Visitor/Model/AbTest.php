<?php
namespace Weline\Visitor\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** A/B测试模型 - 管理A/B测试配置和数据 */
#[Table(comment: 'weline A/B测试')]
#[Index(name: 'idx_test_id', columns: ['test_id'], type: 'UNIQUE')]
#[Index(name: 'idx_website_id', columns: ['website_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
class AbTest extends Model
{
    public const schema_table = 'w_ab_test';
    public const schema_primary_key = 'test_id';
    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'test_id';
    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_TEST_ID = 'test_id';
    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 255, nullable: false, comment: '测试名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '测试描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 20, nullable: false, default: 'draft', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '开始时间')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col('datetime', comment: '结束时间')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col('text', comment: '变体A配置JSON')]
    public const schema_fields_VARIANT_A = 'variant_a';
    #[Col('text', comment: '变体B配置JSON')]
    public const schema_fields_VARIANT_B = 'variant_b';
    #[Col('varchar', 255, nullable: false, default: '50:50', comment: '流量分配比例')]
    public const schema_fields_TRAFFIC_SPLIT = 'traffic_split';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const status_ACTIVE = 'active';
    public const status_PAUSED = 'paused';
    public const status_COMPLETED = 'completed';
    public const status_DRAFT = 'draft';
/**
     * 获取测试ID
     */
    public function getTestId(): string
    {
        return (string)$this->getData(self::schema_fields_TEST_ID);
    }
    /**
     * 设置测试ID
     */
    public function setTestId(string $testId): static
    {
        return $this->setData(self::schema_fields_TEST_ID, $testId);
    }
    /**
     * 获取站点ID
     */
    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }
    /**
     * 设置站点ID
     */
    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }
    /**
     * 获取测试名称
     */
    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }
    /**
     * 设置测试名称
     */
    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }
    /**
     * 获取状态
     */
    public function getStatus(): string
    {
        return (string)$this->getData(self::schema_fields_STATUS);
    }
    /**
     * 设置状态
     */
    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }
    /**
     * 获取所有活跃的测试
     */
    public static function getActiveTests(?int $websiteId = null): array
    {
        $model = w_obj(self::class)->reset()
            ->where(self::schema_fields_STATUS, self::status_ACTIVE);
        
        if ($websiteId !== null) {
            $model->where(self::schema_fields_WEBSITE_ID, $websiteId);
        }
        
        return $model->select()->fetchArray();
    }
    /**
     * 根据测试ID获取测试
     */
    public static function getByTestId(string $testId): ?self
    {
        $model = w_obj(self::class)->reset()
            ->where(self::schema_fields_TEST_ID, $testId)
            ->find()
            ->fetch();
        
        return $model->getId() ? $model : null;
    }
}
