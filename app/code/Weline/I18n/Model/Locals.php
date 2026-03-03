<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 22:05:23
 */

namespace Weline\I18n\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\App\Env;
use Weline\Framework\Setup\Db\ModelSetup;

class Locals extends \Weline\Framework\Database\Model
{
    public const table = "i18n_locals";
    public const fields_ID = 'code';
    public const fields_CODE = 'code';
    public const fields_TARGET_CODE = 'target_code';
    public const fields_NAME = 'name';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_INSTALL = 'is_install';
    public const fields_FLAG = 'flag';

    public array $_unit_primary_keys = ['code', 'target_code'];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 1.0.3: 添加 code + target_code 组合唯一索引（PostgreSQL ON CONFLICT 需要）
        if ($setup->tableExist()) {
            $this->ensureUniqueIndex($setup);
        }
        
        // 重新安装全球语言包
        $this->installAllGlobalLocales();
    }
    
    /**
     * 确保唯一索引存在（用于 ON CONFLICT）
     */
    private function ensureUniqueIndex(ModelSetup $setup): void
    {
        $table = $setup->getTable();
        $connector = $this->getConnection()->getConnector();
        $isPostgresql = $connector instanceof \Weline\Framework\Database\Connection\Adapter\Pgsql\Connector;
        
        // 检查唯一索引是否已存在
        if ($setup->hasIndex('idx_code_target')) {
            return;
        }
        
        try {
            if ($isPostgresql) {
                // PostgreSQL: 先删除旧索引，再创建唯一索引
                $setup->query("DROP INDEX IF EXISTS \"idx_{$table}_idx_code\"");
                $setup->query("DROP INDEX IF EXISTS \"idx_{$table}_idx_target_code\"");
                $setup->query("CREATE UNIQUE INDEX IF NOT EXISTS \"idx_{$table}_idx_code_target\" ON public.\"{$table}\" (\"code\", \"target_code\")");
            } else {
                // MySQL: 使用 ALTER TABLE
                try {
                    $setup->query("ALTER TABLE `{$table}` DROP INDEX `idx_code`");
                } catch (\Throwable $e) {
                    // 索引不存在，忽略
                }
                try {
                    $setup->query("ALTER TABLE `{$table}` DROP INDEX `idx_target_code`");
                } catch (\Throwable $e) {
                    // 索引不存在，忽略
                }
                $setup->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `idx_code_target` (`code`, `target_code`)");
            }
        } catch (\Throwable $e) {
            w_log_error('I18n upgrade failed to create unique index: ' . $e->getMessage(), [], 'i18n');
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
    //    $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 10, 'not null', '地方代码')
                ->addColumn(self::fields_TARGET_CODE, TableInterface::column_type_VARCHAR, 10, 'not null', '展示的地方代码')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 128, 'not null', '展示的地方代码对应地方代码名称')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '启用状态')
                ->addColumn(self::fields_IS_INSTALL, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否安装')
                ->addColumn(self::fields_FLAG, TableInterface::column_type_TEXT, 20000, '', 'svg国旗')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_code_target', [self::fields_CODE, self::fields_TARGET_CODE], '区码+目标区码唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_NAME, '名字索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_install', self::fields_IS_INSTALL, '安装索引')
                ->create();
        }
        
        // 安装时一次性安装全球所有语言包
        $this->installAllGlobalLocales();
    }
    
    /**
     * 安装全球所有语言包
     */
    private function installAllGlobalLocales(): void
    {
        try {
            // 获取所有可用的语言代码
            $allLocales = \Symfony\Component\Intl\Locales::getLocales();
            $insertData = [];
            
            /** @var I18n $i18nModel */
            $i18nModel = \Weline\Framework\Manager\ObjectManager::getInstance(I18n::class);
            
            foreach ($allLocales as $locale) {
                try {
                    // 获取语言名称（使用英语作为显示语言）
                    // 如果locale不存在，getName会抛出异常，需要单独捕获
                    $localeName = \Symfony\Component\Intl\Locales::getName($locale, 'en');
                } catch (\Exception $e) {
                    // 如果获取名称失败，使用locale代码作为名称，并跳过这个locale
                    if (defined('DEV') && DEV) {
                        w_log_warning("I18n: 无法获取locale '{$locale}' 的名称，跳过: " . $e->getMessage(), [], 'i18n');
                    }
                    continue;
                }
                
                // 从 locale 提取国家代码
                $countryCode = '';
                $parts = explode('_', $locale);
                $lastPart = end($parts);
                if (strlen($lastPart) === 2 && strtoupper($lastPart) === $lastPart) {
                    $countryCode = $lastPart;
                }
                
                // 获取国旗SVG
                $flagSvg = '';
                if ($countryCode) {
                    try {
                        $flagSvg = $i18nModel->getCountryFlag($countryCode, 24, 18);
                    } catch (\Exception $e) {
                        $flagSvg = '';
                    }
                }
                
                $insertData[] = [
                    self::fields_CODE => $locale,
                    self::fields_TARGET_CODE => $locale,
                    self::fields_NAME => $localeName,
                    self::fields_IS_ACTIVE => 0, // 默认未激活
                    self::fields_IS_INSTALL => 1, // 默认已安装
                    self::fields_FLAG => $flagSvg
                ];
            }
            
            // 批量插入数据
            if (!empty($insertData)) {
                $this->clearQuery();
                $this->insert($insertData, [self::fields_CODE, self::fields_TARGET_CODE])->fetch();
            }
            
        } catch (\Exception $e) {
            // 记录错误但不中断安装过程
            w_log_error('I18n global locales installation failed: ' . $e->getMessage(), [], 'i18n');
        }
    }

    /**
     * 保存后清除语言缓存
     * 当语言数据更新时，清除缓存的语言列表，确保下次请求时重新加载最新数据
     */
    public function save_after()
    {
        parent::save_after();
        // 清除语言缓存
        try {
            w_cache('i18n')->clear();
        } catch (\Throwable $e) {
            // 缓存清除失败，静默处理
        }
    }
}
