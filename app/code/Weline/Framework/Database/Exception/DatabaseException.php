<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Exception;

use Weline\Framework\App\Exception;

/**
 * 数据库模块统一异常基类
 * 所有 Database 相关异常（DbException、LinkException、QueryException 等）均继承此类
 */
class DatabaseException extends Exception
{
}
