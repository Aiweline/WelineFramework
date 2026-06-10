<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/20
 * 描述：产品布局计划模型 - 支持产品级别的布局计划
 */
namespace WeShop\Product\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '产品布局计划表')]
#[Index(name: 'idx_weshop_product_layout_schedule_entity_type', columns: ['entity_type'], type: 'KEY', comment: '实体类型索引')]
#[Index(name: 'idx_weshop_product_layout_schedule_entity_lookup', columns: ['entity_type', 'product_id', 'layout_type', 'status'], type: 'KEY', comment: '实体布局计划查询索引')]
#[Index(name: 'idx_weshop_product_layout_schedule_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
#[Index(name: 'idx_weshop_product_layout_schedule_layout_type', columns: ['layout_type'], type: 'KEY', comment: '布局类型索引')]
#[Index(name: 'idx_weshop_product_layout_schedule_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
#[Index(name: 'idx_weshop_product_layout_schedule_start_time', columns: ['start_time'], type: 'KEY', comment: '开始时间索引')]
#[Index(name: 'idx_weshop_product_layout_schedule_end_time', columns: ['end_time'], type: 'KEY', comment: '结束时间索引')]
#[Index(name: 'idx_weshop_product_layout_schedule_priority', columns: ['priority'], type: 'KEY', comment: '优先级索引')]
class ProductLayoutSchedule extends Model
{
    public const ENTITY_PRODUCT = ProductLayout::ENTITY_PRODUCT;
    public const ENTITY_CATEGORY = ProductLayout::ENTITY_CATEGORY;
    public const ENTITY_CATEGORY_PRODUCT_DEFAULT = ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT;
    public const schema_table = 'weshop_product_layout_schedule';
    public const schema_primary_key = 'schedule_id';
    public const indexer = 'weshop_product_layout_schedule';
    public array $_unit_primary_keys = ['schedule_id'];
    public array $_index_sort_keys = ['entity_type', 'product_id', 'layout_type', 'status', 'start_time', 'end_time'];
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '计划ID')]
    public const schema_fields_ID = 'schedule_id';
    #[Col(type: 'varchar', length: 32, nullable: true, default: 'product', comment: 'Entity type')]
    public const schema_fields_ENTITY_TYPE = 'entity_type';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '布局类型')]
    public const schema_fields_LAYOUT_TYPE = 'layout_type';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '布局代码')]
    public const schema_fields_LAYOUT_CODE = 'layout_code';
    #[Col(type: 'datetime', nullable: false, comment: '开始时间')]
    public const schema_fields_START_TIME = 'start_time';
    #[Col(type: 'datetime', nullable: true, comment: '结束时间')]
    public const schema_fields_END_TIME = 'end_time';
    #[Col(type: 'int', nullable: true, default: 0, comment: '是否循环执行')]
    public const schema_fields_IS_RECURRING = 'is_recurring';
    #[Col(type: 'varchar', length: 128, nullable: true, default: '', comment: 'Cron表达式（用于循环任务）')]
    public const schema_fields_CRON_EXPRESSION = 'cron_expression';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'pending', comment: '状态：pending/active/completed/cancelled')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: false, default: 0, comment: '计划优先级，数值越大越优先')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '计划时区，空则使用系统时区')]
    public const schema_fields_TIMEZONE = 'timezone';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    // ===== Getters and Setters ===== (unchanged business methods below)
    public function getEntityType(): string { $entityType = trim((string)$this->getData(self::schema_fields_ENTITY_TYPE)); return $entityType !== '' ? $entityType : self::ENTITY_PRODUCT; }
    public function setEntityType(string $entityType): static { $entityType = trim($entityType); return $this->setData(self::schema_fields_ENTITY_TYPE, $entityType !== '' ? $entityType : self::ENTITY_PRODUCT); }
    public function getEntityId(): int { return $this->getProductId(); }
    public function setEntityId(int $entityId): static { return $this->setProductId($entityId); }
    public function getProductId(): int { return (int)$this->getData(self::schema_fields_PRODUCT_ID); }
    public function setProductId(int $productId): static { return $this->setData(self::schema_fields_PRODUCT_ID, $productId); }
    public function getLayoutType(): string { return (string)$this->getData(self::schema_fields_LAYOUT_TYPE); }
    public function setLayoutType(string $layoutType): static { return $this->setData(self::schema_fields_LAYOUT_TYPE, $layoutType); }
    public function getLayoutCode(): string { return (string)$this->getData(self::schema_fields_LAYOUT_CODE); }
    public function setLayoutCode(string $layoutCode): static { return $this->setData(self::schema_fields_LAYOUT_CODE, $layoutCode); }
    public function getStartTime(): string { return (string)$this->getData(self::schema_fields_START_TIME); }
    public function setStartTime(string $startTime): static { return $this->setData(self::schema_fields_START_TIME, $startTime); }
    public function getEndTime(): ?string { $endTime = $this->getData(self::schema_fields_END_TIME); return $endTime ? (string)$endTime : null; }
    public function setEndTime(?string $endTime): static { return $this->setData(self::schema_fields_END_TIME, $endTime); }
    public function isRecurring(): bool { return (bool)$this->getData(self::schema_fields_IS_RECURRING); }
    public function setIsRecurring(bool $isRecurring): static { return $this->setData(self::schema_fields_IS_RECURRING, $isRecurring ? 1 : 0); }
    public function getCronExpression(): string { return (string)$this->getData(self::schema_fields_CRON_EXPRESSION); }
    public function setCronExpression(string $cronExpression): static { return $this->setData(self::schema_fields_CRON_EXPRESSION, $cronExpression); }
    public function getStatus(): string { return (string)$this->getData(self::schema_fields_STATUS); }
    public function setStatus(string $status): static { return $this->setData(self::schema_fields_STATUS, $status); }
    public function getPriority(): int { return (int)$this->getData(self::schema_fields_PRIORITY); }
    public function setPriority(int $priority): static { return $this->setData(self::schema_fields_PRIORITY, $priority); }
    public function getTimezone(): string { return (string)$this->getData(self::schema_fields_TIMEZONE); }
    public function setTimezone(string $timezone): static { return $this->setData(self::schema_fields_TIMEZONE, $timezone); }
    public function getDescription(): string { return (string)$this->getData(self::schema_fields_DESCRIPTION); }
    public function setDescription(string $description): static { return $this->setData(self::schema_fields_DESCRIPTION, $description); }
    public function getCreatedAt(): string { return (string)$this->getData(self::schema_fields_CREATED_AT); }
    public function setCreatedAt(string $createdAt): static { return $this->setData(self::schema_fields_CREATED_AT, $createdAt); }
    public function getUpdatedAt(): string { return (string)$this->getData(self::schema_fields_UPDATED_AT); }
    public function setUpdatedAt(string $updatedAt): static { return $this->setData(self::schema_fields_UPDATED_AT, $updatedAt); }
    public function save_before(): void
    {
        parent::save_before();
        $this->setEntityType($this->getEntityType());
        if ($this->getTimezone() === '') { $this->setTimezone((string)(date_default_timezone_get() ?: 'UTC')); }
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) { $this->setCreatedAt($now); }
        $this->setUpdatedAt($now);
    }
    public function getPendingSchedules(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_PENDING)
            ->where(self::schema_fields_START_TIME, $now, '<=')
            ->order(self::schema_fields_START_TIME, 'ASC')
            ->select()
            ->fetchArray();
    }
    public function getExpiredActiveSchedules(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::schema_fields_END_TIME, $now, '<=')
            ->where(self::schema_fields_END_TIME, '', '!=')
            ->order(self::schema_fields_END_TIME, 'ASC')
            ->select()
            ->fetchArray();
    }
    public function getActiveScheduleByProduct(int $productId, string $layoutType): ?static
    {
        $schedule = $this->getEffectiveScheduleByEntity(self::ENTITY_PRODUCT, $productId, $layoutType);
        if ($schedule) {
            return $schedule;
        }

        $rows = $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->where(self::schema_fields_LAYOUT_TYPE, $layoutType)
            ->order(self::schema_fields_START_TIME, 'DESC')
            ->order(self::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string)($row[self::schema_fields_STATUS] ?? '');
            if (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE], true) || !$this->isScheduleRowEffectiveNow($row)) {
                continue;
            }

            $schedule = clone $this;
            $schedule->clearData()->clearQuery()->setData($row);
            return $schedule->getId() ? $schedule : null;
        }

        return null;
    }
    public function getActiveScheduleByEntity(string $entityType, int $entityId, string $layoutType): ?static
    {
        return $this->getEffectiveScheduleByEntity($entityType, $entityId, $layoutType);
    }

    public function getEffectiveScheduleByEntity(string $entityType, int $entityId, string $layoutType): ?static
    {
        if ($entityId <= 0 || trim($layoutType) === '') {
            return null;
        }

        $rows = $this->getByEntity($entityType, $entityId, $layoutType);
        $effectiveRows = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string)($row[self::schema_fields_STATUS] ?? '');
            if (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE], true)) {
                continue;
            }
            if (!$this->isScheduleRowEffectiveNow($row)) {
                continue;
            }
            $effectiveRows[] = $row;
        }

        if ($effectiveRows === []) {
            return null;
        }

        usort($effectiveRows, static function (array $left, array $right): int {
            $priorityCompare = ((int)($right[self::schema_fields_PRIORITY] ?? 0)) <=> ((int)($left[self::schema_fields_PRIORITY] ?? 0));
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            $startCompare = strcmp((string)($right[self::schema_fields_START_TIME] ?? ''), (string)($left[self::schema_fields_START_TIME] ?? ''));
            if ($startCompare !== 0) {
                return $startCompare;
            }

            return ((int)($right[self::schema_fields_ID] ?? 0)) <=> ((int)($left[self::schema_fields_ID] ?? 0));
        });

        $schedule = clone $this;
        $schedule->clearData()->clearQuery()->setData($effectiveRows[0]);
        return $schedule->getId() ? $schedule : null;
    }
    public function getByProduct(int $productId, ?string $layoutType = null): array
    {
        $rows = $this->getByEntity(self::ENTITY_PRODUCT, $productId, $layoutType);
        if ($rows !== []) {
            return $rows;
        }

        $query = $this->reset()->where(self::schema_fields_PRODUCT_ID, $productId);
        if ($layoutType) { $query->where(self::schema_fields_LAYOUT_TYPE, $layoutType); }
        return $query->order(self::schema_fields_START_TIME, 'ASC')->select()->fetchArray();
    }
    public function getByEntity(string $entityType, int $entityId, ?string $layoutType = null): array
    {
        $query = $this->reset()
            ->where(self::schema_fields_ENTITY_TYPE, $entityType)
            ->where(self::schema_fields_PRODUCT_ID, $entityId);
        if ($layoutType) { $query->where(self::schema_fields_LAYOUT_TYPE, $layoutType); }
        return $query->order(self::schema_fields_START_TIME, 'ASC')->select()->fetchArray();
    }
    public function getProduct(): ?Product
    {
        $productId = $this->getProductId();
        if ($productId <= 0) return null;
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        return $product->getId() ? $product : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isScheduleRowEffectiveNow(array $row): bool
    {
        $timezone = trim((string)($row[self::schema_fields_TIMEZONE] ?? ''));
        try {
            $tz = new \DateTimeZone($timezone !== '' ? $timezone : (string)(date_default_timezone_get() ?: 'UTC'));
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $now = new \DateTimeImmutable('now', $tz);
        $start = $this->parseScheduleTime((string)($row[self::schema_fields_START_TIME] ?? ''), $tz);
        if (!$start || $start > $now) {
            return false;
        }

        $endRaw = trim((string)($row[self::schema_fields_END_TIME] ?? ''));
        if ($endRaw === '') {
            return true;
        }

        $end = $this->parseScheduleTime($endRaw, $tz);
        return $end !== null && $end > $now;
    }

    private function parseScheduleTime(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
