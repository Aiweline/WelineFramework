<?php
declare(strict_types=1);
namespace WeShop\Product\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '测试表')]
class Test extends Model
{
    public const schema_table = 'gvanda_test';
    public const schema_primary_key = 'test_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '测试ID')]
    public const schema_fields_ID = 'test_id';
    #[Col('varchar', 255, nullable: true, comment: '测试名称')]
    public const schema_fields_name = 'name';

    function getName(): string { return $this->getData(self::schema_fields_name); }
    function setName(string $name): static { $this->setData(self::schema_fields_name, $name); return $this; }
}
