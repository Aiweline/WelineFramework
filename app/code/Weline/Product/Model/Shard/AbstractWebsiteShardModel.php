<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaDiffExcludedModelInterface;
use Weline\Product\Model\ProductShardKey;

/**
 * Website-scoped shard model. Physical table = product_ws_{websiteId}_{entity}.
 * DDL is owned by ProductShardSchemaProvider — excluded from Model SchemaDiff.
 *
 * Query builders use getOriginTableName()/processTable(), so both must honor forWebsite().
 */
abstract class AbstractWebsiteShardModel extends Model implements SchemaDiffExcludedModelInterface
{
    /** Placeholder for SchemaParser; runtime table comes from forWebsite(). */
    public const schema_table = 'product_ws_placeholder';

    protected int $websiteId = 0;
    private bool $websiteBound = false;

    abstract public static function entityCode(): string;

    public function forWebsite(int $websiteId): static
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数：%{1}', [$websiteId]));
        }
        $this->websiteId = $websiteId;
        $this->websiteBound = true;
        $this->origin_table_name = '';
        $this->table = '';
        return $this;
    }

    public function websiteId(): int
    {
        return $this->websiteId;
    }

    protected function processTable(): string
    {
        if ($this->websiteBound) {
            $logical = ProductShardKey::tableName(
                ProductShardKey::fromWebsiteId($this->websiteId),
                static::entityCode(),
            );
            $this->table = $logical;
            $this->origin_table_name = $this->_suffix . $logical;
            return $this->table;
        }
        return parent::processTable();
    }

    public function getOriginTableName(): string
    {
        if ($this->websiteBound) {
            $this->processTable();
            return $this->origin_table_name;
        }
        return parent::getOriginTableName();
    }

    public function getTable(string $table = ''): string
    {
        if ($table !== '') {
            return parent::getTable($table);
        }
        if ($this->websiteBound) {
            $this->processTable();
        }
        return parent::getTable();
    }
}
