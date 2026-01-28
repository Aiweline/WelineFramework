<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Db;

use Weline\Framework\App\Debug;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Db\AlterWithBackup;
use Weline\Framework\Setup\Db\Service\FieldBackupService;
use Weline\Framework\Setup\Data\Context;

/**
 * 这个类用来对Model表结构修改，自动读取Model模型的表名和主键
 */
class ModelSetup
{
    protected ?AbstractModel $model = null;

    private Printing $printing;
    private ?Context $context = null;
    private ?FieldBackupService $fieldBackupService = null;

    /**
     * Setup constructor.
     *
     * @param \Weline\Framework\Output\Cli\Printing $printing
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function __construct(
        Printing $printing,
    )
    {
        $this->printing = $printing;
    }

    /**
     * @DESC          # 设置模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/6 22:25
     * 参数区：
     *
     * @param AbstractModel $model
     *
     * @return $this
     */
    public function putModel(AbstractModel $model): ModelSetup
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @DESC         | 创建表
     *
     * 参数区：
     *
     * @param string $comment
     * @param string $table
     *
     * @return CreateInterface
     */
    public function createTable(string $comment = '', string $table = ''): CreateInterface
    {
        if ($this->model === null) {
            throw new \Weline\Framework\App\Exception(__('ModelSetup: Model 未初始化，请先调用 putModel()'));
        }
        return $this->model->getConnection()->getConnector()
            ->createTable()
            ->createTable($table ?: $this->model->getTable(), $comment);
    }

    /**
     * @DESC         |修改表 两个都留空仅读取表修改类，用此类对表进行其他修改 【提示：如果对表名进行了修改，请紧接着修改Model模型名（或者模型提供对应表名，否则无法找到对应表）】
     *
     * 参数区：
     *
     * @param string $comment 留空不修改表注释
     * @param string $new_table_name 留空不修改表名
     *
     * @return AlterInterface
     */
    public function alterTable(string $comment = '', string $new_table_name = ''): AlterInterface
    {
        if ($this->model === null) {
            throw new \Weline\Framework\App\Exception(__('ModelSetup: Model 未初始化，请先调用 putModel()'));
        }
        if (!$this->model->getConnection()->getConnector()->tableExist($this->model->getTable())) {
            throw new \Weline\Framework\App\Exception(__('表不存在: %{1}', $this->model->getTable()));
        }
        $alter = $this->model->getConnection()->getConnector()->alterTable()->forTable($this->model->getTable(), $this->model->_primary_key, $comment, $new_table_name);
        
        // 返回包装类，自动处理字段备份和恢复
        return new AlterWithBackup($alter, $this);
    }

    /**
     * @DESC          # 获取前缀
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:27
     * 参数区：
     * @return string
     */
    public function getTablePrefix(): string
    {
        if ($this->model === null) {
            throw new \Weline\Framework\App\Exception(__('ModelSetup: Model 未初始化，请先调用 putModel()'));
        }
        $prefix = $this->model->getConnection()->getConfigProvider()->getPrefix();
        return $prefix ?? '';
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $table_name
     *
     * @return bool
     */
    public function tableExist(string $table_name = ''): bool
    {
        if ($this->model === null) {
            throw new \Weline\Framework\App\Exception(__('ModelSetup: Model 未初始化，请先调用 putModel()'));
        }
        return $this->model->getConnection()->getConnector()->tableExist($table_name ?: $this->model->getTable());
    }

    /**
     * @DESC         |获取表名
     *
     * 参数区：
     *
     * @param string $name
     *
     * @return string
     */
    public function getTable(string $name = ''): string
    {
        if (!empty($name) && !is_int(strpos($name, $this->getTablePrefix()))) {
            $name = $this->getTablePrefix() . $name;
        }
        if (empty($name)) {
            $name = $this->model->getTable();
        }
        return $name;
    }

    /**
     * @DESC         |删除表
     *
     * 参数区：
     *
     * @param string $table_name
     *
     * @return bool
     * @throws Null
     */
    public function dropTable(string $table_name = ''): bool
    {
        if (empty($table_name)) {
            $table_name = $this->model->getTable();
        }
        try {
            // 获取连接器类型，判断是否为 PostgreSQL
            $connector = $this->model->getConnection()->getConnector();
            $isPostgresql = $connector instanceof \Weline\Framework\Database\Connection\Adapter\Pgsql\Connector;
            
            // PostgreSQL 需要双引号包裹表名，并添加 CASCADE 选项
            if ($isPostgresql) {
                // 如果表名已经包含引号，先去除
                $table_name = trim($table_name, '`"\'');
                // 如果表名包含点号（schema.table），分别处理
                if (str_contains($table_name, '.')) {
                    $parts = explode('.', $table_name);
                    $quotedParts = array_map(function($part) {
                        $part = trim($part, '`"\'');
                        return '"' . $part . '"';
                    }, $parts);
                    $table_name = implode('.', $quotedParts);
                } else {
                    $table_name = '"' . $table_name . '"';
                }
                $sql = 'DROP TABLE IF EXISTS ' . $table_name . ' CASCADE';
            } else {
                // MySQL/SQLite 等其他数据库
                $sql = 'DROP TABLE IF EXISTS ' . $table_name;
            }
            
            $this->query($sql);
            return true;
        } catch (\Exception $exception) {
            throw $exception;
        }
    }

    /**
     * @DESC         |复制表（包含结构和数据）
     *
     * 参数区：
     *
     * @param string $source_table 源表名
     * @param string $target_table 目标表名
     * @param bool $with_data 是否复制数据，默认 true
     *
     * @return bool
     * @throws \Exception
     */
    public function copyTable(string $source_table, string $target_table, bool $with_data = true): bool
    {
        if ($this->model === null) {
            throw new \Weline\Framework\App\Exception(__('ModelSetup: Model 未初始化，请先调用 putModel()'));
        }
        
        $connector = $this->model->getConnection()->getConnector();
        $connection = $this->model->getConnection();
        
        // 检查源表是否存在
        if (!$connector->tableExist($source_table)) {
            throw new \Weline\Framework\App\Exception(__('源表不存在：%1', $source_table));
        }
        
        // 如果目标表已存在，先删除
        if ($connector->tableExist($target_table)) {
            $this->dropTable($target_table);
        }
        
        try {
            // 获取源表的建表语句
            $createTableSql = $connector->getCreateTableSql($source_table);
            
            if (empty($createTableSql)) {
                throw new \Weline\Framework\App\Exception(__('无法获取表的建表语句：%1', $source_table));
            }
            
            // 移除 IF NOT EXISTS（如果有）
            $createTableSql = preg_replace('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE TABLE ', $createTableSql);
            
            // 替换表名（处理各种引号格式：`table`、"table"、table）
            // 匹配 CREATE TABLE 后的表名
            $createTableSql = preg_replace(
                '/CREATE\s+TABLE\s+[`"\']?[^`"\'\s(]+[`"\']?/i',
                'CREATE TABLE ' . $target_table,
                $createTableSql,
                1
            );
            
            // 执行建表语句
            $connection->query($createTableSql)->fetch();
            
            // 如果需要复制数据
            if ($with_data) {
                $insertSql = "INSERT INTO {$target_table} SELECT * FROM {$source_table}";
                $connection->query($insertSql)->fetch();
            }
            
            return true;
        } catch (\Exception $e) {
            throw new \Weline\Framework\App\Exception(__('复制表失败：%1', $e->getMessage()));
        }
    }

    /**
     * @DESC         |忽略约束删除表
     *
     * 参数区：
     *
     * @param string $table_name
     *
     * @return bool
     * @throws Null
     */
    public function forceDropTable(string $table_name = ''): bool
    {
        if (empty($table_name)) {
            $table_name = $this->model->getTable();
        }
        try {
            $this->query('SET FOREIGN_KEY_CHECKS = 0;DROP TABLE IF EXISTS ' . $table_name . ';SET FOREIGN_KEY_CHECKS = 1;');
            return true;
        } catch (\Exception $exception) {
            throw $exception;
        }
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:56
     * 参数区：
     *
     * @param string $sql
     *
     * @return mixed
     * @throws NUll
     */
    public function query(string $sql): mixed
    {
        return $this->model->getConnection()->query($sql)->fetch();
    }

    public function hasField(string $field): bool
    {
        return $this->model->getConnection()->getConnector()->hasField($this->getTable(), $field);
    }

    public function hasIndex(string $idx_name): bool
    {
        if (!$this->tableExist()) {
            throw new \Exception(__("%{1} 表不存在，无法判断字段是否存在！", $this->model->getTable()));
        }
        return $this->model->getConnection()->getConnector()->hasIndex($this->getTable(), $idx_name);
    }

    public function getVersion(): string
    {
        return $this->model->getConnection()->getVersion();
    }

    public function getConnection(): ConnectionFactory
    {
        return $this->model->getConnection();
    }

    /**
     * @DESC          # 读取打印器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/8 21:56
     * 参数区：
     * @return Printing
     */
    public function getPrinting(): Printing
    {
        return $this->printing;
    }
    
    /**
     * @DESC          # 设置上下文（用于获取模块信息）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/XX
     * 参数区：
     * @param Context $context
     * @return $this
     */
    public function setContext(Context $context): ModelSetup
    {
        $this->context = $context;
        return $this;
    }
    
    /**
     * @DESC          # 获取字段备份服务
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/XX
     * 参数区：
     * @return FieldBackupService
     */
    public function getFieldBackupService(): FieldBackupService
    {
        if ($this->fieldBackupService === null) {
            $this->fieldBackupService = ObjectManager::getInstance(FieldBackupService::class);
        }
        return $this->fieldBackupService;
    }
    
    /**
     * 获取模型
     */
    public function getModel(): ?AbstractModel
    {
        return $this->model;
    }
    
    /**
     * 获取上下文
     */
    public function getContext(): ?Context
    {
        return $this->context;
    }
    
    /**
     * @DESC          # 删除字段（带备份）
     * 
     * 在删除字段前自动备份数据，以便后续恢复
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/XX
     * 参数区：
     * @param string $fieldName 字段名
     * @param string $moduleName 模块名称（可选，如果未提供则从Context获取）
     * @param string $version 模块版本（可选，如果未提供则从Context获取）
     * @return AlterInterface
     */
    public function deleteColumnWithBackup(
        string $fieldName,
        ?string $moduleName = null,
        ?string $version = null
    ): AlterInterface {
        if ($this->model === null) {
            throw new \Weline\Framework\App\Exception(__('ModelSetup: Model 未初始化，请先调用 putModel()'));
        }
        
        // 获取模块信息
        if ($moduleName === null || $version === null) {
            if ($this->context === null) {
                throw new \Weline\Framework\App\Exception(__('ModelSetup: 删除字段需要模块信息，请先调用 setContext() 或提供 moduleName 和 version 参数'));
            }
            $moduleName = $moduleName ?? $this->context->getModuleName();
            $version = $version ?? $this->context->getNewVersion();
        }
        
        // 获取主键
        $primaryKey = $this->model->_primary_key ?? 'id';
        
        // 备份字段数据
        $this->getFieldBackupService()->backupFieldData(
            $this->getTable(),
            $fieldName,
            $primaryKey,
            $moduleName,
            $version
        );
        
        // 删除字段
        return $this->alterTable()->deleteColumn($fieldName);
    }
    
    /**
     * @DESC          # 添加字段（带恢复）
     * 
     * 在添加字段后自动恢复之前备份的数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/XX
     * 参数区：
     * @param string $fieldName 字段名
     * @param string $afterColumn 在哪个字段之后
     * @param string $type 字段类型
     * @param string|int $length 长度
     * @param string $options 配置
     * @param string $comment 注释
     * @param string $moduleName 模块名称（可选，如果未提供则从Context获取）
     * @param string $version 模块版本（可选，如果未提供则从Context获取）
     * @return AlterInterface
     */
    public function addColumnWithRestore(
        string $fieldName,
        string $afterColumn,
        string $type,
        string|int $length,
        string $options,
        string $comment,
        ?string $moduleName = null,
        ?string $version = null
    ): AlterInterface {
        if ($this->model === null) {
            throw new \Weline\Framework\App\Exception(__('ModelSetup: Model 未初始化，请先调用 putModel()'));
        }
        
        // 先添加字段
        $alter = $this->alterTable()->addColumn($fieldName, $afterColumn, $type, $length, $options, $comment);
        $alter->alter();
        
        // 获取模块信息
        if ($moduleName === null || $version === null) {
            if ($this->context === null) {
                // 如果没有上下文，尝试恢复所有版本的备份
                $this->printing->warning(__('ModelSetup: 无法获取模块信息，将尝试恢复所有版本的备份数据'));
                $moduleName = $this->getModuleNameFromModel();
                $version = null; // 恢复所有版本
            } else {
                $moduleName = $moduleName ?? $this->context->getModuleName();
                $version = $version ?? $this->context->getNewVersion();
            }
        }
        
        // 恢复字段数据（如果有备份）
        if ($moduleName) {
            $this->getFieldBackupService()->restoreFieldData(
                $this->getTable(),
                $fieldName,
                $moduleName,
                $version
            );
        }
        
        return $alter;
    }
    
    /**
     * 从模型类名推断模块名称
     */
    private function getModuleNameFromModel(): ?string
    {
        if ($this->model === null) {
            return null;
        }
        
        $className = get_class($this->model);
        // 从命名空间提取模块名，例如：GuoLaiRen\PageBuilder\Model\Page -> GuoLaiRen_PageBuilder
        if (preg_match('/^([A-Za-z0-9_]+)\\\\([A-Za-z0-9_]+)\\\\/', $className, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        
        return null;
    }
}
