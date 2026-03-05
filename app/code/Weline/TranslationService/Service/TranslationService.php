<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;
use Weline\Framework\Event\EventsManager;
use Weline\TranslationService\Model\TranslationProvider;
use Weline\TranslationService\Model\TranslationRecord;
use Weline\TranslationService\Api\ProviderInterface;
use Weline\TranslationService\Api\TranslationServiceInterface;
use Weline\TranslationService\Service\ProviderFactory;
use Weline\TranslationService\Helper\LanguageCodeConverter;

/**
 * 翻译服务核心类
 * 
 * 功能：
 * - 提供统一的翻译接口
 * - 支持多个翻译渠道
 * - 自动选择最佳渠道
 * - 记录翻译历史
 * - 成本统计
 * - 国际化标准支持（ISO 639-1、ISO 639-2、BCP 47）
 */
class TranslationService implements TranslationServiceInterface
{
    /**
     * @var ProviderFactory
     */
    private ProviderFactory $providerFactory;

    /**
     * @var TranslationProvider
     */
    private TranslationProvider $providerModel;

    /**
     * @var TranslationRecord
     */
    private TranslationRecord $recordModel;

    /**
     * @var EventsManager
     */
    private EventsManager $eventsManager;

    /**
     * 构造函数
     */
    public function __construct(
        ProviderFactory $providerFactory,
        TranslationProvider $providerModel,
        TranslationRecord $recordModel,
        EventsManager $eventsManager
    ) {
        $this->providerFactory = $providerFactory;
        $this->providerModel = $providerModel;
        $this->recordModel = $recordModel;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 翻译文本
     * 
     * @param string $text 要翻译的文本
     * @param string $targetLanguage 目标语言代码
     * @param string $sourceLanguage 源语言代码（可选，auto表示自动检测）
     * @param string|null $providerCode 指定渠道代码（可选，不指定则自动选择）
     * @param array $options 额外选项
     * @return string 翻译后的文本
     * @throws Exception
     */
    public function translate(
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        ?string $providerCode = null,
        array $options = []
    ): string {
        if (empty($text)) {
            return $text;
        }

        // 标准化语言代码（转换为ISO 639-1格式）
        $targetLanguage = LanguageCodeConverter::normalize($targetLanguage);
        if ($sourceLanguage !== 'auto') {
            $sourceLanguage = LanguageCodeConverter::normalize($sourceLanguage);
        }

        // 触发翻译前事件，允许修改参数
        $beforeData = [
            'text' => $text,
            'target_language' => $targetLanguage,
            'source_language' => $sourceLanguage,
            'provider_code' => $providerCode,
            'options' => $options
        ];
        $this->eventsManager->dispatch('Weline_TranslationService::translate_before', $beforeData);
        
        // 从事件数据中获取可能被修改的参数
        $text = $beforeData['text'] ?? $text;
        $targetLanguage = $beforeData['target_language'] ?? $targetLanguage;
        $sourceLanguage = $beforeData['source_language'] ?? $sourceLanguage;
        $providerCode = $beforeData['provider_code'] ?? $providerCode;
        $options = $beforeData['options'] ?? $options;

        // 获取渠道配置
        $provider = $this->getProvider($providerCode, $targetLanguage);
        if (!$provider) {
            throw new Exception(__('未找到可用的翻译渠道'));
        }

        // 获取渠道适配器
        $adapter = $this->providerFactory->create($provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE));
        if (!$adapter) {
            throw new Exception(__('未找到渠道适配器：%{1}', [$provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE)]));
        }

        // 记录开始时间
        $startTime = microtime(true);

        try {
            // 执行翻译
            $result = $adapter->translate(
                $provider,
                $text,
                $targetLanguage,
                $sourceLanguage,
                $options
            );

            // 计算响应时间
            $responseTime = (int)((microtime(true) - $startTime) * 1000);

            // 计算成本
            $characterCount = mb_strlen($text, 'UTF-8');
            $cost = $this->calculateCost($provider, $characterCount);

            // 记录翻译历史
            $translatedText = $result['translated_text'] ?? $text;
            $this->recordTranslation(
                $provider,
                $text,
                $translatedText,
                $result['source_language'] ?? $sourceLanguage,
                $targetLanguage,
                $characterCount,
                $cost,
                $responseTime,
                TranslationRecord::STATUS_SUCCESS,
                null,
                $options['module_name'] ?? null
            );

            // 触发翻译后事件，允许修改结果
            $afterData = [
                'text' => $text,
                'translated_text' => $translatedText,
                'target_language' => $targetLanguage,
                'source_language' => $result['source_language'] ?? $sourceLanguage,
                'provider_code' => $provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE),
                'character_count' => $characterCount,
                'cost' => $cost,
                'response_time' => $responseTime,
                'options' => $options
            ];
            $this->eventsManager->dispatch('Weline_TranslationService::translate_after', $afterData);
            
            // 返回可能被修改的翻译结果
            return $afterData['translated_text'] ?? $translatedText;
        } catch (\Exception $e) {
            // 记录失败
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            $characterCount = mb_strlen($text, 'UTF-8');
            
            $this->recordTranslation(
                $provider,
                $text,
                '',
                $sourceLanguage,
                $targetLanguage,
                $characterCount,
                0,
                $responseTime,
                TranslationRecord::STATUS_FAILED,
                $e->getMessage(),
                $options['module_name'] ?? null
            );

            // 触发翻译错误事件
            $errorData = [
                'text' => $text,
                'target_language' => $targetLanguage,
                'source_language' => $sourceLanguage,
                'provider_code' => $provider ? $provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE) : null,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'options' => $options
            ];
            $this->eventsManager->dispatch('Weline_TranslationService::translate_error', $errorData);

            throw $e;
        }
    }

    /**
     * 批量翻译
     * 
     * @param array $texts 要翻译的文本数组
     * @param string $targetLanguage 目标语言代码
     * @param string $sourceLanguage 源语言代码
     * @param string|null $providerCode 指定渠道代码
     * @param array $options 额外选项
     * @return array 翻译结果数组
     * @throws Exception
     */
    public function batchTranslate(
        array $texts,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        ?string $providerCode = null,
        array $options = []
    ): array {
        if (empty($texts)) {
            return [];
        }

        // 标准化语言代码
        $targetLanguage = LanguageCodeConverter::normalize($targetLanguage);
        if ($sourceLanguage !== 'auto') {
            $sourceLanguage = LanguageCodeConverter::normalize($sourceLanguage);
        }

        // 触发批量翻译前事件
        $beforeData = [
            'texts' => $texts,
            'target_language' => $targetLanguage,
            'source_language' => $sourceLanguage,
            'provider_code' => $providerCode,
            'options' => $options
        ];
        $this->eventsManager->dispatch('Weline_TranslationService::batch_translate_before', $beforeData);
        
        // 从事件数据中获取可能被修改的参数
        $texts = $beforeData['texts'] ?? $texts;
        $targetLanguage = $beforeData['target_language'] ?? $targetLanguage;
        $sourceLanguage = $beforeData['source_language'] ?? $sourceLanguage;
        $providerCode = $beforeData['provider_code'] ?? $providerCode;
        $options = $beforeData['options'] ?? $options;

        // 获取渠道配置
        $provider = $this->getProvider($providerCode, $targetLanguage);
        if (!$provider) {
            throw new Exception(__('未找到可用的翻译渠道'));
        }

        // 获取渠道适配器
        $adapter = $this->providerFactory->create($provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE));
        if (!$adapter) {
            throw new Exception(__('未找到渠道适配器：%{1}', [$provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE)]));
        }

        // 记录开始时间
        $startTime = microtime(true);

        try {
            // 执行批量翻译
            $results = $adapter->batchTranslate(
                $provider,
                $texts,
                $targetLanguage,
                $sourceLanguage,
                $options
            );

            // 计算响应时间
            $responseTime = (int)((microtime(true) - $startTime) * 1000);

            // 计算总字符数和成本
            $totalCharacters = 0;
            foreach ($texts as $text) {
                $totalCharacters += mb_strlen($text, 'UTF-8');
            }
            $cost = $this->calculateCost($provider, $totalCharacters);

            // 记录翻译历史（批量记录为一条）
            $this->recordTranslation(
                $provider,
                json_encode($texts, JSON_UNESCAPED_UNICODE),
                json_encode($results, JSON_UNESCAPED_UNICODE),
                $sourceLanguage,
                $targetLanguage,
                $totalCharacters,
                $cost,
                $responseTime,
                TranslationRecord::STATUS_SUCCESS,
                null,
                $options['module_name'] ?? null
            );

            // 触发批量翻译后事件
            $afterData = [
                'texts' => $texts,
                'translated_texts' => $results,
                'target_language' => $targetLanguage,
                'source_language' => $sourceLanguage,
                'provider_code' => $provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE),
                'total_characters' => $totalCharacters,
                'cost' => $cost,
                'response_time' => $responseTime,
                'options' => $options
            ];
            $this->eventsManager->dispatch('Weline_TranslationService::batch_translate_after', $afterData);
            
            // 返回可能被修改的翻译结果
            return $afterData['translated_texts'] ?? $results;
        } catch (\Exception $e) {
            // 记录失败
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            $totalCharacters = 0;
            foreach ($texts as $text) {
                $totalCharacters += mb_strlen($text, 'UTF-8');
            }
            
            $this->recordTranslation(
                $provider,
                json_encode($texts, JSON_UNESCAPED_UNICODE),
                '',
                $sourceLanguage,
                $targetLanguage,
                $totalCharacters,
                0,
                $responseTime,
                TranslationRecord::STATUS_FAILED,
                $e->getMessage(),
                $options['module_name'] ?? null
            );

            // 触发批量翻译错误事件
            $errorData = [
                'texts' => $texts,
                'target_language' => $targetLanguage,
                'source_language' => $sourceLanguage,
                'provider_code' => $provider ? $provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE) : null,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'options' => $options
            ];
            $this->eventsManager->dispatch('Weline_TranslationService::batch_translate_error', $errorData);

            throw $e;
        }
    }

    /**
     * 检测语言
     * 
     * @param string $text 要检测的文本
     * @param string|null $providerCode 指定渠道代码
     * @return string 语言代码
     * @throws Exception
     */
    public function detectLanguage(string $text, ?string $providerCode = null): string
    {
        if (empty($text)) {
            throw new Exception(__('文本不能为空'));
        }

        // 获取渠道配置
        $provider = $this->getProvider($providerCode);
        if (!$provider) {
            throw new Exception(__('未找到可用的翻译渠道'));
        }

        // 获取渠道适配器
        $adapter = $this->providerFactory->create($provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE));
        if (!$adapter) {
            throw new Exception(__('未找到渠道适配器：%{1}', [$provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE)]));
        }

        return $adapter->detectLanguage($provider, $text);
    }

    /**
     * 获取渠道配置
     * 
     * @param string|null $providerCode 指定渠道代码
     * @param string|null $targetLanguage 目标语言（用于检查渠道是否支持）
     * @return TranslationProvider|null
     */
    private function getProvider(?string $providerCode = null, ?string $targetLanguage = null): ?TranslationProvider
    {
        if ($providerCode) {
            // 指定渠道
            $provider = $this->providerModel->clear()
                ->load(TranslationProvider::schema_fields_PROVIDER_CODE, $providerCode);
            
            if ($provider->getId() && $provider->isEnabled()) {
                // 检查语言支持
                if ($targetLanguage && !$provider->supportsLanguage($targetLanguage)) {
                    return null;
                }
                return $provider;
            }
        } else {
            // 自动选择渠道
            // 优先选择默认渠道
            $provider = $this->providerModel->clear()
                ->where(TranslationProvider::schema_fields_IS_DEFAULT, 1)
                ->where(TranslationProvider::schema_fields_IS_ENABLED, 1)
                ->order('priority', 'DESC')
                ->find()
                ->fetch();
            
            if ($provider->getId()) {
                // 检查语言支持
                if ($targetLanguage && !$provider->supportsLanguage($targetLanguage)) {
                    // 如果默认渠道不支持，查找其他支持该语言的渠道
                    $provider = $this->providerModel->clear()
                        ->where(TranslationProvider::schema_fields_IS_ENABLED, 1)
                        ->order('priority', 'DESC')
                        ->select()
                        ->fetch();
                    
                    foreach ($provider as $p) {
                        if ($p->supportsLanguage($targetLanguage)) {
                            return $p;
                        }
                    }
                    return null;
                }
                return $provider;
            }

            // 如果没有默认渠道，选择优先级最高的启用渠道
            $provider = $this->providerModel->clear()
                ->where(TranslationProvider::schema_fields_IS_ENABLED, 1)
                ->order('priority', 'DESC')
                ->find()
                ->fetch();
            
            if ($provider->getId()) {
                // 检查语言支持
                if ($targetLanguage && !$provider->supportsLanguage($targetLanguage)) {
                    return null;
                }
                return $provider;
            }
        }

        return null;
    }

    /**
     * 计算成本
     * 
     * @param TranslationProvider $provider
     * @param int $characterCount
     * @return float
     */
    private function calculateCost(TranslationProvider $provider, int $characterCount): float
    {
        $costPerChar = (float)$provider->getData(TranslationProvider::schema_fields_COST_PER_CHARACTER);
        return $costPerChar * $characterCount;
    }

    /**
     * 记录翻译历史
     * 
     * @param TranslationProvider $provider
     * @param string $sourceText
     * @param string $translatedText
     * @param string $sourceLanguage
     * @param string $targetLanguage
     * @param int $characterCount
     * @param float $cost
     * @param int $responseTime
     * @param string $status
     * @param string|null $errorMessage
     * @param string|null $moduleName
     * @return void
     */
    private function recordTranslation(
        TranslationProvider $provider,
        string $sourceText,
        string $translatedText,
        string $sourceLanguage,
        string $targetLanguage,
        int $characterCount,
        float $cost,
        int $responseTime,
        string $status,
        ?string $errorMessage = null,
        ?string $moduleName = null
    ): void {
        try {
            $this->recordModel->clear()
                ->setData(TranslationRecord::schema_fields_PROVIDER_ID, $provider->getId())
                ->setData(TranslationRecord::schema_fields_PROVIDER_CODE, $provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE))
                ->setData(TranslationRecord::schema_fields_SOURCE_TEXT, $sourceText)
                ->setData(TranslationRecord::schema_fields_TRANSLATED_TEXT, $translatedText)
                ->setData(TranslationRecord::schema_fields_SOURCE_LANGUAGE, $sourceLanguage)
                ->setData(TranslationRecord::schema_fields_TARGET_LANGUAGE, $targetLanguage)
                ->setData(TranslationRecord::schema_fields_CHARACTER_COUNT, $characterCount)
                ->setData(TranslationRecord::schema_fields_COST, $cost)
                ->setData(TranslationRecord::schema_fields_RESPONSE_TIME, $responseTime)
                ->setData(TranslationRecord::schema_fields_STATUS, $status)
                ->setData(TranslationRecord::schema_fields_ERROR_MESSAGE, $errorMessage)
                ->setData(TranslationRecord::schema_fields_MODULE_NAME, $moduleName)
                ->setData(TranslationRecord::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                ->save();
        } catch (\Exception $e) {
            // 记录失败不影响翻译功能，只记录日志
            w_log_error('Failed to record translation: ' . $e->getMessage());
        }
    }

    /**
     * 检查是否支持该语言
     * 
     * @param string $languageCode 语言代码
     * @param string|null $providerCode 指定渠道代码
     * @return bool
     */
    public function supportsLanguage(string $languageCode, ?string $providerCode = null): bool
    {
        $normalizedCode = LanguageCodeConverter::normalize($languageCode);
        
        if ($providerCode) {
            $provider = $this->providerModel->clear()
                ->load(TranslationProvider::schema_fields_PROVIDER_CODE, $providerCode);
            
            if ($provider->getId() && $provider->isEnabled()) {
                return $provider->supportsLanguage($normalizedCode);
            }
        } else {
            // 检查是否有任何渠道支持该语言
            $providers = $this->providerModel->clear()
                ->where(TranslationProvider::schema_fields_IS_ENABLED, 1)
                ->select()
                ->fetch();
            
            foreach ($providers as $provider) {
                if ($provider->supportsLanguage($normalizedCode)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * 获取所有可用的翻译渠道
     * 
     * @return array 渠道代码数组
     */
    public function getAvailableProviders(): array
    {
        $providers = $this->providerModel->clear()
            ->where(TranslationProvider::schema_fields_IS_ENABLED, 1)
            ->select()
            ->fetch();
        
        $codes = [];
        foreach ($providers as $provider) {
            $codes[] = $provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE);
        }
        
        return $codes;
    }
}

