<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Debug\Printing;
use Weline\I18n\Model\I18n;

class GetWordsFile implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var I18n
     */
    private I18n $i18n;
    
    /**
     * 是否正在执行（防止循环调用）
     * @var bool
     */
    private static bool $isExecuting = false;

    /**
     * GetWordsFile 初始函数...
     *
     * @param I18n $i18n
     */
    public function __construct(
        I18n $i18n
    )
    {
        $this->i18n = $i18n;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 防止循环调用：如果正在执行，直接返回
        if (self::$isExecuting) {
            return;
        }
        
        // 设置执行标志
        self::$isExecuting = true;
        
        try {
            /**@var DataObject $words_file_data */
            $words_file_data = $event->getData();
    //        $words_file      = $words_file_data->getData('file_path');

            // 获取翻译模式（与Parser.php保持一致）
            $translate_mode = Env::get('translation.mode', 'default');
            
            // 在线翻译模式：检测文件变更，如果变更则重新收集
            $use_cache = true;
            if ($translate_mode === 'online') {
                // 检查是否需要重新收集（检测生成的词典文件是否比CSV文件旧）
                $use_cache = !$this->needRegenerate();
            }

            // 翻译收集
            try {
                $this->i18n->convertToLanguageFile($use_cache);
            } catch (\Exception $e) {
                /**@var Printing $debug */
                $debug = ObjectManager::getInstance(Printing::class);
                $debug->debug($e->getMessage());
                if (CLI) {
                    throw $e;
                }
            }
            // 用户语言优先
            $lang = Cookie::getLang();
            // 默认中文
            if ($lang) {
                $words_file = Env::path_TRANSLATE_FILES_PATH . $lang . '.php';
            } else {
                $words_file = Env::path_TRANSLATE_DEFAULT_FILE;
            }
            # 词典文件
            $words_file_data->setData('file_path', $words_file);
        } finally {
            // 清除执行标志
            self::$isExecuting = false;
        }
    }
    
    /**
     * 检查是否需要重新生成翻译文件
     * 在online模式下，如果CSV文件比生成的词典文件新，则需要重新生成
     * 
     * @return bool true表示需要重新生成，false表示不需要
     */
    private function needRegenerate(): bool
    {
        $words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        
        // 如果生成的词典文件不存在，需要生成
        if (!file_exists($words_file)) {
            return true;
        }
        
        $words_file_mtime = filemtime($words_file);
        
        // 检查所有模块的i18n CSV文件
        $activeModules = Env::getInstance()->getActiveModules();
        foreach ($activeModules as $module) {
            $i18n_dir = $module['base_path'] . '/i18n';
            if (!is_dir($i18n_dir)) {
                continue;
            }
            
            // 扫描所有CSV文件
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($i18n_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'csv') {
                    $csv_mtime = $file->getMTime();
                    // 如果CSV文件比生成的词典文件新，需要重新生成
                    if ($csv_mtime > $words_file_mtime) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
}
