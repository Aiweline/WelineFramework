<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Config;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\System\File\App\Scanner;
use Weline\Framework\System\File\Data\File;

class LanguagePackReader
{
    /**
     * @var Scanner
     */
    private Scanner $scanner;

    /**
     * LanguagePackReader 初始函数...
     *
     * @param Scanner $scanner
     */
    public function __construct(Scanner $scanner)
    {
        $this->scanner = $scanner;
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/8/12 21:40
     * 参数区：
     * @return array
     * @throws \ReflectionException
     * @throws Exception
     */
    public function getLanguagePack(): array
    {
        // 所有语言包
        $packs = [];
        $this->scanner->globFile(APP_PATH . 'i18n' . DS, $packs, '.csv');
        foreach ($packs as $key => $pack) {
            $file_info = pathinfo($pack, PATHINFO_FILENAME);
            unset($packs[$key]);
            $packs[$file_info] = $pack;
        }
        return $packs;
    }
}
