<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/6/16
 * 时间：10:26
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Framework\Database\Exception;

use Weline\Framework\Exception\Core;

class LinkException extends Core
{
    public function __construct($message = null, \Exception $cause = null, $code = 0)
    {
        $message = 'DB Error:' . $message;
        parent::__construct($message, $cause, $code);
    }
}
