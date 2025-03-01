<?php

namespace WeShop\Store\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Currency extends Model
{

    public const fields_ID = 'currency_id';
    public const fields_Code = 'code';
    public const fields_Name = 'name';
    public const fields_Rate = 'rate';
    public const fields_IsActive = 'is_active';
    public const fields_IsDefault = 'is_default';
    public const fields_Position = 'position';


    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement setup() method.
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement install() method.
    }
}