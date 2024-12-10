<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Console\I18n;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\I18n\Model\I18n;

class Collect implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;
    /**
     * @var \Weline\I18n\Model\I18n
     */
    private I18n $i18n;

    public function __construct(
        I18n     $i18n,
        Printing $printing
    )
    {
        $this->printing = $printing;
        $this->i18n     = $i18n;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 设置语言翻译收集配置
        try {
            $this->i18n->convertToLanguageFile();
            $this->printing->success(__('语言包收集成功！'));
        } catch (Exception $e) {
            $this->printing->error(__('语言包收集失败：%1', $e->getMessage()));
        }
        # 查找所有已激活模块的模板文件，进行模板生成
    }


    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '收集翻译词';
    }
}
