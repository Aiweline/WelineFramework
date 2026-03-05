<?php

namespace Weline\Component\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '组件表')]
class Component extends Model
{

    public const schema_table = 'weline_component';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_COMPONENT_ID = 'id';
    #[Col('varchar', 255, nullable: false, comment: '组件名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', nullable: false, comment: '组件说明')]
    public const schema_fields_DOC = 'doc';
}