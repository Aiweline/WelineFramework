<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Helper;

use Weline\Framework\System\File\Scan;
use Weline\Framework\Module\Api\Data\DirectoryInterface;
use Weline\Framework\Module\Service\ModuleScanService;

/**
 * 文件信息
 * DESC:   | 扫描模块信息
 * 作者：   秋枫雁飞
 * 日期：   2020/9/20
 * 时间：   11:02
 * 网站：   https://bbs.aiweline.com
 * Email：  aiweline@qq.com
 */
class Scanner
{
    /**
     * @var Scan
     */
    private Scan $scan;

    /**
     * @var Data
     */
    private Data $data;
    private ModuleScanService $moduleScanService;

    public function __construct(
        Scan $scan,
        Data $data,
        $moduleScanService = null
    )
    {
        $this->scan = $scan;
        $this->data = $data;
        $this->moduleScanService = $moduleScanService instanceof ModuleScanService
            ? $moduleScanService
            : new ModuleScanService($scan);
    }

    public function getEtcFile(string $moduleName)
    {
        $moduleDir = $this->data->getModulePath($moduleName);

        return $this->moduleScanService->scanDirTreeIfExists($moduleDir, DirectoryInterface::etc, 12);
    }
}
