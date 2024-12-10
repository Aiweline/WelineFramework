<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Console\I18n;

use Weline\Framework\Output\Cli\Printing;
use Weline\I18n\Model\I18n;

class Locals implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var I18n
     */
    private I18n $i18n;

    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * Locals 初始函数...
     *
     * @param I18n     $i18n
     * @param Printing $printing
     */
    public function __construct(
        I18n     $i18n,
        Printing $printing
    )
    {
        $this->i18n     = $i18n;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printing->printList(['本地语言：' => $this->i18n->getLocals()], '=>', 15);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('查看本地语言码');
    }
}
