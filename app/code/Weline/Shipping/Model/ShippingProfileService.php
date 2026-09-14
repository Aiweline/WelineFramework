<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '配送方案与航线关联')]
#[Index(name: 'uk_shipping_profile_service', columns: ['profile_id', 'service_id'], type: 'UNIQUE')]
#[Index(name: 'idx_shipping_profile_service_svc', columns: ['service_id'])]
class ShippingProfileService extends AbstractModel
{
    public const schema_table = 'w_shipping_profile_services';
    public const schema_primary_key = 'id';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', null, nullable: false, comment: 'Profile ID')]
    public const schema_fields_PROFILE_ID = 'profile_id';
    #[Col('int', null, nullable: false, comment: 'ShippingService ID')]
    public const schema_fields_SERVICE_ID = 'service_id';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'profile_id', 'service_id'];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
}
