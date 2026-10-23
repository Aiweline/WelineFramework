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
        
        // 创建翻译记录表
        
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

