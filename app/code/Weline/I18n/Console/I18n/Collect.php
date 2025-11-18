<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Console\I18n;

use Weline\Framework\Console\CommandInterface;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\I18n\Model\I18n;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Cache\CacheInterface;
use Weline\I18n\Cache\I18nCache;
use Weline\Framework\Phrase\Cache\PhraseCache;

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
        $this->i18n = $i18n;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 检查是否指定了模块名
        $moduleName = $args['module'] ?? $args['m'] ?? $args[1] ?? null;
        
        if ($moduleName) {
            // 收集指定模块
            $moduleName = trim($moduleName);
            $this->printing->note(__('正在收集模块：%{1}', [$moduleName]));
            try {
                $this->i18n->convertToLanguageFile(false, $moduleName);
                $this->printing->success(__('模块 %{1} 语言包收集成功！', [$moduleName]));
            } catch (Exception $e) {
                $this->printing->error(__('模块 %{1} 语言包收集失败：%{2}', [$moduleName, $e->getMessage()]));
                return;
            }
        } else {
            // 收集所有模块
            $this->printing->note(__('正在收集所有模块的翻译词...'));
            try {
                $this->i18n->convertToLanguageFile(false);
                $this->printing->success(__('语言包收集成功！'));
            } catch (Exception $e) {
                $this->printing->error(__('语言包收集失败：%{1}', [$e->getMessage()]));
                return;
            }
        }
        
        // 收集完成后，清理翻译缓存，确保新翻译生效
        $this->printing->note(__('正在清理翻译缓存...'));
        try {
            // 清理i18n缓存
            /**@var CacheInterface $i18nCache */
            $i18nCache = ObjectManager::getInstance(I18nCache::class . 'Factory');
            $i18nCache->clear();
            
            // 清理phrase缓存
            /**@var CacheInterface $phraseCache */
            $phraseCache = ObjectManager::getInstance(PhraseCache::class . 'Factory');
            $phraseCache->clear();
            
            $this->printing->success(__('翻译缓存清理成功！'));
        } catch (Exception $e) {
            $this->printing->warning(__('翻译缓存清理失败：%{1}，但翻译收集已完成', [$e->getMessage()]));
        }
    }


    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '收集翻译词';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
