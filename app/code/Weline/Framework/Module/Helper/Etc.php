<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Module\Data\DirectoryInterface;
use Weline\Framework\Xml\Parser;

/**
 * 文件信息
 * DESC:   | 扫描module中etc的配置
 * 作者：   秋枫雁飞
 * 日期：   2020/9/20
 * 时间：   10:52
 * 网站：   https://bbs.aiweline.com
 * Email：  aiweline@qq.com
 */
class Etc
{
    /**
     * @var Scanner
     */
    protected Scanner $scanner;

    /**
     * @var Parser
     */
    private Parser $parser;

    /**
     * @var Data
     */
    private Data $data;

    /**
     * Etc 初始函数...
     *
     * @param Scanner $scanner
     * @param Parser  $parser
     * @param Data    $data
     */
    public function __construct(
        Scanner $scanner,
        Parser  $parser,
        Data    $data
    )
    {
        $this->scanner = $scanner;
        $this->parser  = $parser;
        $this->data    = $data;
    }

    /**
     * @DESC         |获得菜单配置
     *
     * 参数区：
     *
     * @param string $moduleName
     */
    public function getMenuConfig(string $moduleName)
    {
        $etcFiles                  = $this->scanner->getEtcFile($moduleName);
        $realModuleNameEtcPath     = $this->data->getModulePath($moduleName) . DS . DirectoryInterface::etc;
        $relativeModuleNameEtcPath = str_replace(APP_CODE_PATH, '', $realModuleNameEtcPath);
        p($relativeModuleNameEtcPath);
        if (isset($etcFiles[Env::framework_name . '\Admin\etc\adminhtml'])) {
            foreach ($etcFiles[Env::framework_name . '\Admin\etc\adminhtml'] as $etcFile) {
            }
        }
        p($this->scanner->getEtcFile($moduleName));
    }
}
