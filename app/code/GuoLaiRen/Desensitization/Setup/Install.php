<?php

declare(strict_types=1);

namespace GuoLaiRen\Desensitization\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;

class Install implements InstallInterface
{
    /**
     * 安装模块时执行的数据库操作
     *
     * @param Setup $setup
     * @param Context $context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->createRuleTable($setup);
        $this->createLogTable($setup);
        $this->insertDefaultRules($setup);
    }

    /**
     * 创建脱敏规则表
     *
     * @param Setup $setup
     * @return void
     */
    private function createRuleTable(Setup $setup): void
    {
        $setup->getDb()
            ->createTable('desensitization_rule', '脱敏规则表')
            ->addColumn('rule_id', TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键')
            ->addColumn('name', TableInterface::column_type_VARCHAR, 100, 'not null', '规则名称')
            ->addColumn('type', TableInterface::column_type_VARCHAR, 50, 'not null', '规则类型')
            ->addColumn('pattern', TableInterface::column_type_TEXT, null, 'not null', '匹配模式（正则）')
            ->addColumn('replacement', TableInterface::column_type_TEXT, null, 'not null', '替换内容')
            ->addColumn('description', TableInterface::column_type_VARCHAR, 255, 'null', '规则描述')
            ->addColumn('is_active', TableInterface::column_type_INTEGER, 1, 'default 1', '是否激活')
            ->addColumn('priority', TableInterface::column_type_INTEGER, 10, 'default 0', '优先级')
            ->addColumn('created_at', TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', '创建时间')
            ->addColumn('updated_at', TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
            ->addIndex(TableInterface::index_type_KEY, 'idx_type', ['type'], '类型索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', ['is_active'], '是否激活索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_priority', ['priority'], '优先级索引')
            ->create();
    }

    /**
     * 创建脱敏日志表
     *
     * @param Setup $setup
     * @return void
     */
    private function createLogTable(Setup $setup): void
    {
        $setup->getDb()
            ->createTable('desensitization_log', '脱敏日志表')
            ->addColumn('log_id', TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键')
            ->addColumn('original_content', TableInterface::column_type_TEXT, null, 'not null', '原始内容')
            ->addColumn('desensitized_content', TableInterface::column_type_TEXT, null, 'not null', '脱敏后内容')
            ->addColumn('rule_id', TableInterface::column_type_INTEGER, 10, 'default 0', '规则ID')
            ->addColumn('method', TableInterface::column_type_VARCHAR, 50, "default 'regex'", '脱敏方法')
            ->addColumn('execution_time', TableInterface::column_type_DECIMAL, '10,4', 'default 0.0000', '执行时间（秒）')
            ->addColumn('user_id', TableInterface::column_type_INTEGER, 10, 'default 0', '用户ID')
            ->addColumn('ip_address', TableInterface::column_type_VARCHAR, 45, 'null', 'IP地址')
            ->addColumn('created_at', TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', '创建时间')
            ->addIndex(TableInterface::index_type_KEY, 'idx_rule_id', ['rule_id'], '规则ID索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', ['user_id'], '用户ID索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', ['created_at'], '创建时间索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_method', ['method'], '方法索引')
            ->create();
    }

    /**
     * 插入默认规则
     *
     * @param Setup $setup
     * @return void
     */
    private function insertDefaultRules(Setup $setup): void
    {
        // 使用 Data Setup 的简易查询接口插入默认规则
        $defaultRules = [
            ['email', 'email', '/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\.([a-zA-Z]{2,})/', '$1***@$2.***', '邮箱脱敏', 5],
            ['phone', 'phone', '/(\d{3})\d{4}(\d{4})/', '$1****$2', '手机号脱敏', 5],
            ['id_card', 'id_card', '/(\d{6})\d{8}(\d{4})/', '$1********$2', '身份证号脱敏', 5],
            ['bank_card', 'bank_card', '/(\d{4})\d{12}(\d{4})/', '$1************$2', '银行卡号脱敏', 5],
            ['credit_card', 'credit_card', '/(\d{4})[\s-]?\d{4}[\s-]?\d{4}[\s-]?(\d{4})/', '$1****$2', '信用卡号脱敏', 5],
        ];
        
        foreach ($defaultRules as $rule) {
            $name = addslashes($rule[0]);
            $type = addslashes($rule[1]);
            $pattern = addslashes($rule[2]);
            $replacement = addslashes($rule[3]);
            $description = addslashes($rule[4]);
            $priority = (int)$rule[5];
            $setup->getDb()->query(
                "INSERT INTO desensitization_rule (`name`,`type`,`pattern`,`replacement`,`description`,`priority`,`is_active`) " .
                "VALUES ('{$name}','{$type}','{$pattern}','{$replacement}','{$description}',{$priority},1)"
            );
        }
    }
}

