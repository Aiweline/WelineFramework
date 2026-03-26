<?php

declare(strict_types=1);

namespace Weline\DataTable\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '测试用户地址表')]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
class TestUserAddress extends Model
{
    public const schema_table = 'datatable_test_user_addresses';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '地址ID')]
    public const schema_fields_ID = 'id';

    #[Col('int', nullable: false, comment: '用户ID')]
    public const schema_fields_user_id = 'user_id';

    #[Col('varchar', 20, default: 'home', comment: '地址类型')]
    public const schema_fields_address_type = 'address_type';

    #[Col('varchar', 50, comment: '省份')]
    public const schema_fields_province = 'province';

    #[Col('varchar', 50, comment: '城市')]
    public const schema_fields_city = 'city';

    #[Col('varchar', 50, comment: '区县')]
    public const schema_fields_district = 'district';

    #[Col('varchar', 200, comment: '街道')]
    public const schema_fields_street = 'street';

    #[Col('varchar', 10, comment: '邮政编码')]
    public const schema_fields_postal_code = 'postal_code';

    #[Col('varchar', 20, comment: '联系电话')]
    public const schema_fields_phone = 'phone';

    #[Col('int', 1, default: 0, comment: '是否默认地址')]
    public const schema_fields_is_default = 'is_default';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getAddressTypeOptions(): array
    {
        return [
            ['value' => 'home', 'label' => '家庭地址'],
            ['value' => 'work', 'label' => '工作地址'],
            ['value' => 'other', 'label' => '其他地址'],
        ];
    }

    public function getTestData(): array
    {
        return [
            [
                'id' => 1,
                'user_id' => 1,
                'address_type' => 'home',
                'province' => 'Beijing',
                'city' => 'Beijing',
                'district' => 'Chaoyang',
                'street' => 'Jianguomen Outer Street 1',
                'postal_code' => '100020',
                'phone' => '13800138001',
                'is_default' => 1,
                'created_at' => '2024-01-01 10:00:00',
                'updated_at' => '2024-01-01 10:00:00',
            ],
            [
                'id' => 2,
                'user_id' => 1,
                'address_type' => 'work',
                'province' => 'Beijing',
                'city' => 'Beijing',
                'district' => 'Haidian',
                'street' => 'Zhongguancun Software Park',
                'postal_code' => '100080',
                'phone' => '13800138001',
                'is_default' => 0,
                'created_at' => '2024-01-01 11:00:00',
                'updated_at' => '2024-01-01 11:00:00',
            ],
            [
                'id' => 3,
                'user_id' => 2,
                'address_type' => 'home',
                'province' => 'Shanghai',
                'city' => 'Shanghai',
                'district' => 'Pudong',
                'street' => 'Lujiazui Ring Road 1000',
                'postal_code' => '200120',
                'phone' => '13800138002',
                'is_default' => 1,
                'created_at' => '2024-01-02 11:30:00',
                'updated_at' => '2024-01-02 11:30:00',
            ],
        ];
    }
}
