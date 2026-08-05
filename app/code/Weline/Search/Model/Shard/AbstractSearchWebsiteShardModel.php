<?php

declare(strict_types=1);

namespace Weline\Search\Model\Shard;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaDiffExcludedModelInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Runtime table binding for Search website shard models.
 */
abstract class AbstractSearchWebsiteShardModel extends Model implements SchemaDiffExcludedModelInterface
{
    public const schema_table = 'search_ws_placeholder';

    protected int $websiteId = 0;
    private bool $websiteBound = false;

    abstract public static function entityCode(): string;

    public function forWebsite(int $websiteId): static
    {
        SearchShardKey::fromWebsiteId($websiteId);
        $this->websiteId = $websiteId;
        $this->websiteBound = true;
        $this->origin_table_name = '';
        $this->table = '';

        return $this;
    }

    protected function processTable(): string
    {
        if ($this->websiteBound) {
            $logical = SearchShardKey::tableName(
                SearchShardKey::fromWebsiteId($this->websiteId),
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
