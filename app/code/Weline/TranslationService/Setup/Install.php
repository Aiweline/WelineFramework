<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\TranslationService\Model\TranslationProvider;
use Weline\TranslationService\Model\TranslationRecord;

/**
 * 翻译服务模块安装脚本
 * 连接通过 Setup::getDb() 获取（由 setModuleContext 在 setup 执行前设置）。
 */
class Install implements InstallInterface
{
    /**
     * 执行安装
     * 
     * @param Setup $setup
     * @param Context $context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        $connection = $setup->getDb();
        
        // 创建翻译渠道配置表
        $connection->createTable('w_translation_provider', '翻译渠道配置表')
            ->addColumn('provider_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '渠道ID')
            ->addColumn('provider_code', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '渠道代码（google、baidu、deepl、microsoft等）')
            ->addColumn('provider_name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '渠道名称')
            ->addColumn('api_key', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 500, '', 'API密钥')
            ->addColumn('api_secret', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 500, '', 'API密钥（加密存储）')
            ->addColumn('api_endpoint', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 500, '', 'API端点URL')
            ->addColumn('config', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '额外配置（JSON格式）')
            ->addColumn('is_enabled', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用（1=启用，0=禁用）')
            ->addColumn('is_default', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否默认渠道（1=是，0=否）')
            ->addColumn('priority', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '优先级（数字越大优先级越高）')
            ->addColumn('supported_languages', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '支持的语言列表（JSON格式）')
            ->addColumn('rate_limit', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '速率限制（每分钟请求数）')
            ->addColumn('daily_limit', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '每日限制（每日请求数）')
            ->addColumn('cost_per_character', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '每字符成本')
            ->addColumn('description', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '渠道描述')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '更新时间')
            ->addIndex('UNIQUE', 'idx_w_translation_provider_code', ['provider_code'])
            ->addIndex('INDEX', 'idx_w_translation_provider_is_enabled', ['is_enabled'])
            ->addIndex('INDEX', 'idx_w_translation_provider_is_default', ['is_default'])
            ->addIndex('INDEX', 'idx_w_translation_provider_priority', ['priority'])
            ->create();
        
        // 创建翻译记录表
        $connection->createTable('w_translation_record', '翻译记录表')
            ->addColumn('record_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '记录ID')
            ->addColumn('provider_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '渠道ID')
            ->addColumn('provider_code', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '渠道代码')
            ->addColumn('source_text', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'not null', '源文本')
            ->addColumn('translated_text', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '翻译文本')
            ->addColumn('source_language', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'not null', '源语言代码（ISO 639-1或BCP 47）')
            ->addColumn('target_language', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'not null', '目标语言代码（ISO 639-1或BCP 47）')
            ->addColumn('character_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '字符数')
            ->addColumn('cost', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '翻译成本')
            ->addColumn('response_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '响应时间（毫秒）')
            ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'success\'', '状态（success、failed、pending）')
            ->addColumn('error_message', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '错误信息')
            ->addColumn('request_data', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '请求数据（JSON格式）')
            ->addColumn('response_data', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '响应数据（JSON格式）')
            ->addColumn('module_name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, '', '调用模块名称')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addIndex('INDEX', 'idx_w_translation_record_provider_id', ['provider_id'])
            ->addIndex('INDEX', 'idx_w_translation_record_provider_code', ['provider_code'])
            ->addIndex('INDEX', 'idx_w_translation_record_status', ['status'])
            ->addIndex('INDEX', 'idx_w_translation_record_created_at', ['created_at'])
            ->addIndex('INDEX', 'idx_w_translation_record_module_name', ['module_name'])
            ->create();
        
        // 插入默认渠道配置（不包含API密钥，需要用户配置）
        $defaultProviders = [
            [
                'provider_code' => 'google',
                'provider_name' => 'Google翻译',
                'api_endpoint' => 'https://translation.googleapis.com/language/translate/v2',
                'is_enabled' => 0,
                'priority' => 10,
                'supported_languages' => json_encode(['zh', 'en', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar', 'pt', 'it', 'nl', 'pl', 'tr', 'vi', 'th', 'id', 'hi', 'cs', 'sv', 'da', 'fi', 'no', 'ro', 'hu', 'el', 'he', 'uk', 'bg', 'hr', 'sk', 'sl', 'et', 'lv', 'lt', 'mt', 'ga', 'cy']),
                'description' => 'Google Cloud Translation API，支持100+种语言',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'provider_code' => 'baidu',
                'provider_name' => '百度翻译',
                'api_endpoint' => 'https://fanyi-api.baidu.com/api/trans/vip/translate',
                'is_enabled' => 0,
                'priority' => 9,
                'supported_languages' => json_encode(['zh', 'en', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar', 'pt', 'it', 'nl', 'pl', 'th', 'vi', 'id', 'hi', 'cs', 'sv', 'da', 'fi', 'no', 'ro', 'hu', 'el', 'he', 'uk', 'bg', 'hr', 'sk', 'sl', 'et', 'lv', 'lt', 'mt', 'ga', 'cy']),
                'description' => '百度翻译API，支持200+种语言，适合中文翻译',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'provider_code' => 'deepl',
                'provider_name' => 'DeepL翻译',
                'api_endpoint' => 'https://api-free.deepl.com/v2/translate',
                'is_enabled' => 0,
                'priority' => 8,
                'supported_languages' => json_encode(['zh', 'en', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'pt', 'it', 'nl', 'pl', 'tr', 'cs', 'sv', 'da', 'fi', 'no', 'ro', 'hu', 'el', 'bg', 'hr', 'sk', 'sl', 'et', 'lv', 'lt']),
                'description' => 'DeepL翻译API，翻译质量高，支持30+种语言',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'provider_code' => 'microsoft',
                'provider_name' => 'Microsoft翻译',
                'api_endpoint' => 'https://api.cognitive.microsofttranslator.com/translate',
                'is_enabled' => 0,
                'priority' => 7,
                'supported_languages' => json_encode(['zh', 'en', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar', 'pt', 'it', 'nl', 'pl', 'tr', 'vi', 'th', 'id', 'hi', 'cs', 'sv', 'da', 'fi', 'no', 'ro', 'hu', 'el', 'he', 'uk', 'bg', 'hr', 'sk', 'sl', 'et', 'lv', 'lt', 'mt', 'ga', 'cy']),
                'description' => 'Microsoft Translator API，支持100+种语言',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'provider_code' => 'youdao',
                'provider_name' => '有道翻译',
                'api_endpoint' => 'https://openapi.youdao.com/api',
                'is_enabled' => 0,
                'priority' => 6,
                'supported_languages' => json_encode(['zh', 'en', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar', 'pt', 'it', 'nl', 'pl', 'th', 'vi', 'id', 'hi']),
                'description' => '有道翻译API，支持100+种语言',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'provider_code' => 'tencent',
                'provider_name' => '腾讯翻译',
                'api_endpoint' => 'https://tmt.tencentcloudapi.com',
                'is_enabled' => 0,
                'priority' => 5,
                'supported_languages' => json_encode(['zh', 'en', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar', 'pt', 'it', 'nl', 'pl', 'th', 'vi', 'id', 'hi', 'tr', 'cs', 'sv', 'da', 'fi', 'no', 'ro', 'hu', 'el', 'he', 'uk', 'bg', 'hr', 'sk', 'sl', 'et', 'lv', 'lt']),
                'description' => '腾讯云翻译API，支持100+种语言',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ];
        
        /** @var TranslationProvider $providerModel */
        $providerModel = ObjectManager::getInstance(TranslationProvider::class);
        
        foreach ($defaultProviders as $provider) {
            $existing = $providerModel->clear()->load('provider_code', $provider['provider_code']);
            if (!$existing->getId()) {
                $providerModel->clear()
                    ->setData($provider)
                    ->save();
            }
        }
    }
}

