<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Model\Html;

use Weline\Backend\Model\Config;
use Weline\Framework\App\Exception;
use Weline\Framework\View\Data\HtmlInterface;
use Weline\Framework\View\Template;

class Header implements HtmlInterface
{
    use ConfigHtml;
    public const key = 'header';
    public const module = 'Weline_Backend';
}
