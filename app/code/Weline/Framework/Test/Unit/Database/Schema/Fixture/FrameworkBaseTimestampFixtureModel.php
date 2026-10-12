<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Schema\Fixture;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;

/**
 * C-RC-2 fixture: owns #[Col] declarations but does not redeclare create_time/update_time.
 * Timestamp columns therefore come only from AbstractModel base #[Col].
 */
final class FrameworkBaseTimestampFixtureModel extends AbstractModel
{
    public const schema_table = 'w_rc_c2_framework_base_fixture';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: 'UC code')]
    public const schema_fields_CODE = 'code';
}
