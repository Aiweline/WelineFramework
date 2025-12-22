<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Framework\Setup\Db\Migration\Base;

/**
 * 添加 token_price_input 和 token_price_output 字段
 * 
 * 用于记录模型的输入和输出价格
 */
class add_token_price_fields_20250111 extends Base
{
    /**
     * 升级数据库
     */
    public function upgrade(): void
    {
        // 获取表操作对象（实际表名是 'ai'，不是 'ai_model'）
        $table = $this->getTable('ai');
        
        // 添加 token_price_input 字段（输入价格）
        if (!$table->hasFields('token_price_input')) {
            $table->addColumn(
                'token_price_input',
                'DECIMAL',
                [
                    'precision' => 10,
                    'scale' => 6,
                    'nullable' => true,
                    'default' => 0,
                    'comment' => '每1000个输入tokens的价格(美元)'
                ]
            );
        }
        
        // 添加 token_price_output 字段（输出价格）
        if (!$table->hasFields('token_price_output')) {
            $table->addColumn(
                'token_price_output',
                'DECIMAL',
                [
                    'precision' => 10,
                    'scale' => 6,
                    'nullable' => true,
                    'default' => 0,
                    'comment' => '每1000个输出tokens的价格(美元)'
                ]
            );
        }
        
        // 执行表结构变更
        $table->alter();
    }
    
    /**
     * 回滚数据库（可选）
     */
    public function rollback(): void
    {
        // 获取表操作对象（实际表名是 'ai'，不是 'ai_model'）
        $table = $this->getTable('ai');
        
        // 删除添加的字段
        if ($table->hasFields('token_price_input')) {
            $table->dropColumn('token_price_input');
        }
        
        if ($table->hasFields('token_price_output')) {
            $table->dropColumn('token_price_output');
        }
        
        // 执行表结构变更
        $table->alter();
    }
}

