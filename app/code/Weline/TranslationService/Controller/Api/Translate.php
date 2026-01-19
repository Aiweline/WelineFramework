<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Controller\Api;

use Weline\Framework\App\Exception;
use Weline\Framework\App\State;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\TranslationService\Api\TranslationServiceInterface;
use Weline\TranslationService\Helper\LanguageCodeConverter;

/**
 * 翻译API控制器
 * 提供前端翻译接口
 */
class Translate extends FrontendRestController
{
    /**
     * 翻译文本
     * 
     * POST /translation-service/api/translate
     * 
     * 参数：
     * - text: 要翻译的文本
     * - target_language: 目标语言代码（可选，默认使用当前语言）
     * - source_language: 源语言代码（可选，默认auto自动检测）
     * - provider_code: 指定渠道代码（可选）
     */
    public function translate(): string
    {
        try {
            if (!$this->request->isPost()) {
                return $this->fetch(['success' => false, 'message' => __('仅支持POST请求')]);
            }

            $text = trim($this->request->getPost('text', ''));
            if (empty($text)) {
                return $this->fetch(['success' => false, 'message' => __('文本不能为空')]);
            }

            // 获取目标语言（默认使用当前语言）
            $targetLanguage = $this->request->getPost('target_language', '');
            if (empty($targetLanguage)) {
                // 获取当前语言
                $currentLang = Cookie::getLang() ?: State::getLang();
                // 转换为ISO 639-1格式
                $targetLanguage = LanguageCodeConverter::normalize($currentLang);
            } else {
                $targetLanguage = LanguageCodeConverter::normalize($targetLanguage);
            }

            // 获取源语言（默认auto）
            $sourceLanguage = $this->request->getPost('source_language', 'auto');
            if ($sourceLanguage !== 'auto') {
                $sourceLanguage = LanguageCodeConverter::normalize($sourceLanguage);
            }

            // 获取渠道代码（可选）
            $providerCode = $this->request->getPost('provider_code', null);
            if (empty($providerCode)) {
                $providerCode = null;
            }

            // 获取翻译服务
            /** @var TranslationServiceInterface $translationService */
            $translationService = ObjectManager::getInstance(TranslationServiceInterface::class);

            // 执行翻译
            $translatedText = $translationService->translate(
                $text,
                $targetLanguage,
                $sourceLanguage,
                $providerCode,
                ['module_name' => 'Weline_TranslationService']
            );

            return $this->fetch([
                'success' => true,
                'data' => [
                    'original_text' => $text,
                    'translated_text' => $translatedText,
                    'source_language' => $sourceLanguage === 'auto' ? 'auto' : $sourceLanguage,
                    'target_language' => $targetLanguage,
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetch([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 批量翻译
     * 
     * POST /translation-service/api/batch-translate
     * 
     * 参数：
     * - texts: 要翻译的文本数组（JSON格式）
     * - target_language: 目标语言代码（可选）
     * - source_language: 源语言代码（可选）
     * - provider_code: 指定渠道代码（可选）
     */
    public function batchTranslate(): string
    {
        try {
            if (!$this->request->isPost()) {
                return $this->fetch(['success' => false, 'message' => __('仅支持POST请求')]);
            }

            $textsJson = $this->request->getPost('texts', '[]');
            $texts = json_decode($textsJson, true);
            
            if (empty($texts) || !is_array($texts)) {
                return $this->fetch(['success' => false, 'message' => __('文本数组不能为空')]);
            }

            // 过滤空文本
            $texts = array_filter(array_map('trim', $texts), function($text) {
                return !empty($text);
            });

            if (empty($texts)) {
                return $this->fetch(['success' => false, 'message' => __('没有有效的文本需要翻译')]);
            }

            // 获取目标语言
            $targetLanguage = $this->request->getPost('target_language', '');
            if (empty($targetLanguage)) {
                $currentLang = Cookie::getLang() ?: State::getLang();
                $targetLanguage = LanguageCodeConverter::normalize($currentLang);
            } else {
                $targetLanguage = LanguageCodeConverter::normalize($targetLanguage);
            }

            // 获取源语言
            $sourceLanguage = $this->request->getPost('source_language', 'auto');
            if ($sourceLanguage !== 'auto') {
                $sourceLanguage = LanguageCodeConverter::normalize($sourceLanguage);
            }

            // 获取渠道代码
            $providerCode = $this->request->getPost('provider_code', null);
            if (empty($providerCode)) {
                $providerCode = null;
            }

            // 获取翻译服务
            /** @var TranslationServiceInterface $translationService */
            $translationService = ObjectManager::getInstance(TranslationServiceInterface::class);

            // 执行批量翻译
            $translatedTexts = $translationService->batchTranslate(
                array_values($texts),
                $targetLanguage,
                $sourceLanguage,
                $providerCode,
                ['module_name' => 'Weline_TranslationService']
            );

            return $this->fetch([
                'success' => true,
                'data' => [
                    'original_texts' => array_values($texts),
                    'translated_texts' => $translatedTexts,
                    'source_language' => $sourceLanguage === 'auto' ? 'auto' : $sourceLanguage,
                    'target_language' => $targetLanguage,
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetch([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
