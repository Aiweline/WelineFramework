<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\System\ModuleFileReader;

class Reader extends ModuleFileReader
{

    /**
     * @var CachePoolInterface
     */
    protected CachePoolInterface $i18nCache;

    /**
     * @var Scanner
     */
    protected Scanner $scanner;

    /**
     * Read 初始函数...
     *
     * @param Scanner $scanner
     */
    public function __construct(
        Scanner $scanner
    )
    {
        $this->i18nCache = w_cache('i18n');
        $this->scanner   = $scanner;
        parent::__construct($scanner, 'i18n');
    }

    /**
     * @DESC         |读取模块i18n翻译文件
     *
     * 参数区：
     */
    public function getAllI18ns()
    {
        $cache_key    = 'cache_i18n_lang_packs';
        $all_lan_pack = $this->i18nCache->get($cache_key);
        if ($all_lan_pack && ('online' !== Env::getInstance()->getConfig('translate_mode'))) {
            return $all_lan_pack;
        }
        $all_lan_pack = [];
        /**@var LanguagePackReader $lang_pack_reader */
        $lang_pack_reader          = ObjectManager::getInstance(LanguagePackReader::class);
        $all_lan_pack['I18n_Pack'] = $lang_pack_reader->getLanguagePack();
        // 模块中的i18n包
        $modules = Env::getInstance()->getActiveModules();
        foreach ($modules as $module) {
            $files = [];
            $this->scanner->globFile($module['base_path'] . 'i18n', $files, '.csv');
            foreach ($files as $key => $file) {
                $filename = pathinfo($file, PATHINFO_FILENAME);
                unset($files[$key]);
                $files[$filename] = $file;
            }
            if ($files) {
                $all_lan_pack[$module['name']] = $files;
            }
        }
        $this->i18nCache->set($cache_key, $all_lan_pack);
        return $all_lan_pack;
    }
}
