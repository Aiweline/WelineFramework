<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Phrase\Console\Translate\Model;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Set implements CommandInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * @var System
     */
    private System $system;

    /**
     * Set 初始函数...
     *
     * @param Printing $printing
     * @param System   $system
     */
    public function __construct(
        Printing $printing,
        System   $system
    )
    {
        $this->printing = $printing;
        $this->system   = $system;
    }

    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);
        $param = array_shift($args);
        switch ($param) {
            case 'online':
                $input = 'y';
                if ('prod' === Env::getInstance()->getConfig('deploy')) {
                    $this->printing->setup(__('当前生产环境：确认切换实时翻译模式么？Y/n'));
                    $input = $this->system->input();
                }
                if (strtolower($input) === 'y') {
                    Env::getInstance()->setConfig('translate_mode', $param);
                }

                break;
            case 'default':
                Env::getInstance()->setConfig('translate_mode', $param);

                break;
            default:
                $this->printing->error(' ╮(๑•́ ₃•̀๑)╭  ：错误的翻译模式：' . $param);
                $this->printing->note('(￢_￢) ->：允许的部署模式：default/online');
                return;
        }
    }

    public function tip(): string
    {
        return '设置翻译模式：online,实时翻译;default,缓存翻译。';
    }
}
