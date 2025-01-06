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
    public function execute(Event $event)
    {
        /**@var DataObject $words_file_data */
        $words_file_data = $event->getData('file_data');
//        $words_file      = $words_file_data->getData('file_path');

        // 翻译收集
        try {
            $this->i18n->convertToLanguageFile();
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
    }
}
