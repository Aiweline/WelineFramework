<?php

declare(strict_types=1);

namespace GuoLaiRen\Desensitization\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Setup;
use Weline\Framework\Setup\Data\Context;

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
        $connection = $setup->getConnection();
        
        $connection->createTable('desensitization_rule', function ($table) {
            $table->addColumn('rule_id', 'int', 10, 'PRIMARY KEY AUTO_INCREMENT')
                ->addColumn('name', 'varchar', 100, 'NOT NULL COMMENT "规则名称"')
                ->addColumn('type', 'varchar', 50, 'NOT NULL COMMENT "规则类型"')
                ->addColumn('pattern', 'text', null, 'NOT NULL COMMENT "匹配模式（正则表达式）"')
                ->addColumn('replacement', 'text', null, 'NOT NULL COMMENT "替换内容"')
                ->addColumn('description', 'varchar', 255, 'DEFAULT NULL COMMENT "规则描述"')
                ->addColumn('is_active', 'tinyint', 1, 'NOT NULL DEFAULT 1 COMMENT "是否激活"')
                ->addColumn('priority', 'int', 10, 'NOT NULL DEFAULT 0 COMMENT "优先级"')
                ->addColumn('created_at', 'timestamp', null, 'NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "创建时间"')
                ->addColumn('updated_at', 'timestamp', null, 'NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT "更新时间"')
                ->addIndex('idx_type', 'type')
                ->addIndex('idx_is_active', 'is_active')
                ->addIndex('idx_priority', 'priority');
        });
    }

    /**
     * 创建脱敏日志表
     *
     * @param Setup $setup
     * @return void
     */
    private function createLogTable(Setup $setup): void
    {
        $connection = $setup->getConnection();
        
        $connection->createTable('desensitization_log', function ($table) {
            $table->addColumn('log_id', 'int', 10, 'PRIMARY KEY AUTO_INCREMENT')
                ->addColumn('original_content', 'text', null, 'NOT NULL COMMENT "原始内容"')
                ->addColumn('desensitized_content', 'text', null, 'NOT NULL COMMENT "脱敏后内容"')
                ->addColumn('rule_id', 'int', 10, 'DEFAULT 0 COMMENT "使用的规则ID"')
                ->addColumn('method', 'varchar', 50, 'NOT NULL DEFAULT "regex" COMMENT "脱敏方法"')
                ->addColumn('execution_time', 'decimal', '10,4', 'DEFAULT 0.0000 COMMENT "执行时间（秒）"')
                ->addColumn('user_id', 'int', 10, 'DEFAULT 0 COMMENT "用户ID"')
                ->addColumn('ip_address', 'varchar', 45, 'DEFAULT NULL COMMENT "IP地址"')
                ->addColumn('created_at', 'timestamp', null, 'NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "创建时间"')
                ->addIndex('idx_rule_id', 'rule_id')
                ->addIndex('idx_user_id', 'user_id')
                ->addIndex('idx_created_at', 'created_at')
                ->addIndex('idx_method', 'method');
        });
    }

    /**
     * 插入默认规则
     *
     * @param Setup $setup
     * @return void
     */
    private function insertDefaultRules(Setup $setup): void
    {
        $connection = $setup->getConnection();
        
        $defaultRules = [
            ['email', 'email', '/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\.([a-zA-Z]{2,})/', '$1***@$2.***', '邮箱脱敏', 5],
            ['phone', 'phone', '/(\d{3})\d{4}(\d{4})/', '$1****$2', '手机号脱敏', 5],
            ['id_card', 'id_card', '/(\d{6})\d{8}(\d{4})/', '$1********$2', '身份证号脱敏', 5],
            ['bank_card', 'bank_card', '/(\d{4})\d{12}(\d{4})/', '$1************$2', '银行卡号脱敏', 5],
            ['credit_card', 'credit_card', '/(\d{4})[\s-]?\d{4}[\s-]?\d{4}[\s-]?(\d{4})/', '$1****$2', '信用卡号脱敏', 5],
        ];
        
        foreach ($defaultRules as $rule) {
            $connection->insert('desensitization_rule', [
                'name' => $rule[0],
                'type' => $rule[1],
                'pattern' => $rule[2],
                'replacement' => $rule[3],
                'description' => $rule[4],
                'priority' => $rule[5],
                'is_active' => 1
            ]);
        }
    }
}

