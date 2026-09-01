<?php

declare(strict_types=1);

namespace Weline\Eav\Model\EavAttribute;

use Weline\Framework\Database\Model;

class Placement extends Model
{
    public const schema_table = 'eav_attribute_placement';
    public const schema_primary_key = 'placement_id';

    public const schema_fields_ID = 'placement_id';
    public const schema_fields_placement_id = 'placement_id';
    public const schema_fields_attribute_id = 'attribute_id';
    public const schema_fields_eav_entity_id = 'eav_entity_id';
    public const schema_fields_set_id = 'set_id';
    public const schema_fields_group_id = 'group_id';
}
