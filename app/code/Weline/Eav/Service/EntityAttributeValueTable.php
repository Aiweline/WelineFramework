<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaDiffExcludedModelInterface;

/**
 * Internal dynamic-table query model. The logical table is selected only by
 * EntityAttributeStore from validated entity and type codes.
 */
final class EntityAttributeValueTable extends Model implements SchemaDiffExcludedModelInterface
{
    public const schema_table = '';
    public const schema_primary_keys = ['attribute_id', 'entity_id'];
    public const schema_fields_ID = 'value_id';

    public array $_unit_primary_keys = ['attribute_id', 'entity_id'];
    public array $_index_sort_keys = ['attribute_id', 'entity_id'];

    public function useLogicalTable(string $table): static
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $table)) {
            throw new \InvalidArgumentException('eav_value_table_invalid:' . $table);
        }

        $this->table = $table;
        $this->origin_table_name = $table;

        return $this;
    }
}
