<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Setup;

use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Upgrade implements InstallInterface
{
    public const VERSION = '1.0.1';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $printer = $setup->getPrinter();
        $printer->note('主题表升级开始...');
        
        try {
            $db = $setup->getDb();
            $tableName = \Weline\Theme\Setup\Install::table_THEME;
            
            if ($db->tableExist($tableName)) {
                // 检查 preview_image 字段是否存在
                if (!$db->columnExist($tableName, 'preview_image')) {
                    $printer->warning('添加 preview_image 字段...');
                    
                    // 使用 SQL 直接添加字段
                    $sql = "ALTER TABLE {$tableName} ADD COLUMN preview_image VARCHAR(255) NULL DEFAULT NULL COMMENT '预览图片路径' AFTER path";
                    $db->query($sql);
                    
                    $printer->success('preview_image 字段添加成功');
                }
            }
        } catch (\Exception $e) {
            $printer->warning('升级跳过（可能已执行）：' . $e->getMessage());
        }
        
        $printer->note('主题表升级完成！');
    }
    
    public function getVersion(): string
    {
        return self::VERSION;
    }
}
