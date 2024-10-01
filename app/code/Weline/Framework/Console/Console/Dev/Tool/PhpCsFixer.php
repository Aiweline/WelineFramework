<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Dev\Tool;

use Weline\Framework\Output\Cli\Printing;

class PhpCsFixer implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;

    public function __construct(
        Printing $printing
    )
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);
        $show = true;
        /*剥离参数选项*/
        if (in_array('no-show', $args)) {
            foreach ($args as $key => $arg) {
                if ('no-show' === $arg) {
                    unset($args[$key]);
                }
            }
            $show = false;
        }
        $args = implode(' ', $args);
        // 运行代码标准程序 php-cs-fixer
        if (PHP_CS) {
            if ($show) {
                $this->printing->note(__('正在美化代码...'));
            }
            exec('php ' . VENDOR_PATH . 'friendsofphp' . DS . 'php-cs-fixer' . DS . 'php-cs-fixer fix ' . $args, $out);
            if ($out) {
                $this->printing->error(implode('', $out));
            }
            if ($show) {
                $this->printing->success(__('代码美化完成...'));
            }
        } else {
            throw new \Weline\Framework\App\Exception(__('未开启代码美化工具。'));
        }
    }


    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '代码美化工具';
    }
}
