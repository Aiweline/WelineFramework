<?php

declare(strict_types=1);

namespace Weline\Framework\Database\test\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Test model for Database module. Table created by Setup\Db\CreateTestWelineModelTable.
 */
class WelineModel extends Model
{
    public const schema_table = 'test_weline_model';

    public const schema_fields_ID = 'id';
    public const schema_fields_stores = 'stores';
    public const schema_fields_name = 'name';

    public function setup(ModelSetup $setup, Context $context): void {}

    public function install(ModelSetup $setup, Context $context): void {}
}