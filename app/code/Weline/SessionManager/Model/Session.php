<?php

declare(strict_types=1);

namespace Weline\SessionManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'Session 表')]
class Session extends Model
{

    public const schema_table = 'weline_session_manager_session';
    public const schema_primary_key = 'sess_id';
    #[Col('varchar', 128, primaryKey: true, nullable: false, comment: 'Session ID')]
    public const schema_fields_ID = 'sess_id';
    #[Col('text', comment: 'Session鏁版嵁')]
    public const schema_fields_SESSION_DATA = 'sess_data';
}

