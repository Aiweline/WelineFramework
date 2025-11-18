<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/22
 * 时间：11:06
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\DeveloperWorkspace\Setup;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Helper\Importer\SqlFile;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\System\File\Scan;

class Install implements \Weline\Framework\Setup\InstallInterface
{
    private Scan $scan;

    public function __construct(
        Scan $scan
    )
    {
        $this->scan = $scan;
    }

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $sql_files = [];
        $this->scan->globFile($context->getModulePath() . 'Setup' . DS . 'Data' . DS . 'sql' . DS . '*', $sql_files, '.sql');
        foreach ($sql_files as $sql_file) {
            if (is_file($sql_file)) {
                $context->getPrinter()->setup(__('开始导入数据库Sql文件'));
                /**@var SqlFile $sqlFile */
                $sqlFile = ObjectManager::getInstance(SqlFile::class);
                /**@var Document $document */
                $document = ObjectManager::getInstance(Document::class);
                try {
                    $sqlFile->setConnection($document->getConnection());
                } catch (DbException $e) {
                    $context->getPrinter()->error($e->getMessage());
                    continue;
                }
                $context->getPrinter()->printList($sqlFile->import_data($sql_file));
                $context->getPrinter()->setup(__('数据库Sql文件导入完成'));
            }
        }
    }
}
