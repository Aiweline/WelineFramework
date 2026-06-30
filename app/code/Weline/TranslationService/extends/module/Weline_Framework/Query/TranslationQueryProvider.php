<?php

declare(strict_types=1);

namespace Weline\TranslationService\Extends\Module\Weline_Framework\Query;

use Weline\Framework\App\State;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\TranslationService\Helper\LanguageCodeConverter;
use Weline\TranslationService\Model\TranslationProvider;
use Weline\TranslationService\Model\TranslationRecord;
use Weline\TranslationService\Service\ProviderFactory;
use Weline\TranslationService\Service\TranslationService;

class TranslationQueryProvider implements QueryProviderInterface
{
    private ?TranslationService $translationService = null;

    public function getProviderName(): string
    {
        return 'translationService';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'translate' => $this->translate($params),
            'batchTranslate' => $this->batchTranslate($params),
            default => throw new \InvalidArgumentException((string)__('Unsupported translation operation: %{1}', $operation)),
        };
    }

    private function translate(array $params): array
    {
        $text = \trim((string)($params['text'] ?? ''));
        if ($text === '') {
            return [
                'success' => false,
                'message' => (string)__('Text cannot be empty.'),
            ];
        }

        $sourceLanguage = $this->normalizeSourceLanguage((string)($params['source_language'] ?? 'auto'));
        $targetLanguage = $this->normalizeTargetLanguage((string)($params['target_language'] ?? ''));
        $providerCode = $this->normalizeOptionalString($params['provider_code'] ?? null);

        $translatedText = $this->getTranslationService()->translate(
            $text,
            $targetLanguage,
            $sourceLanguage,
            $providerCode,
            ['module_name' => 'Weline_TranslationService']
        );

        return [
            'success' => true,
            'data' => [
                'original_text' => $text,
                'translated_text' => $translatedText,
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
            ],
        ];
    }

    private function batchTranslate(array $params): array
    {
        $texts = $params['texts'] ?? [];
        if (!\is_array($texts)) {
            $texts = [];
        }

        $texts = \array_values(\array_filter(\array_map(static function (mixed $text): string {
            return \trim((string)$text);
        }, $texts), static fn(string $text): bool => $text !== ''));

        if ($texts === []) {
            return [
                'success' => false,
                'message' => (string)__('Texts cannot be empty.'),
            ];
        }

        $sourceLanguage = $this->normalizeSourceLanguage((string)($params['source_language'] ?? 'auto'));
        $targetLanguage = $this->normalizeTargetLanguage((string)($params['target_language'] ?? ''));
        $providerCode = $this->normalizeOptionalString($params['provider_code'] ?? null);

        $translatedTexts = $this->getTranslationService()->batchTranslate(
            $texts,
            $targetLanguage,
            $sourceLanguage,
            $providerCode,
            ['module_name' => 'Weline_TranslationService']
        );

        return [
            'success' => true,
            'data' => [
                'original_texts' => $texts,
                'translated_texts' => $translatedTexts,
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
            ],
        ];
    }

    private function normalizeTargetLanguage(string $language): string
    {
        if ($language === '') {
            $language = Cookie::getLang() ?: State::getLang();
        }
        return LanguageCodeConverter::normalize($language);
    }

    private function normalizeSourceLanguage(string $language): string
    {
        if ($language === '' || $language === 'auto') {
            return 'auto';
        }
        return LanguageCodeConverter::normalize($language);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = \trim($value);
        return $value === '' ? null : $value;
    }

    private function getTranslationService(): TranslationService
    {
        if ($this->translationService instanceof TranslationService) {
            return $this->translationService;
        }

        $this->translationService = new TranslationService(
            new ProviderFactory(),
            ObjectManager::getInstance(TranslationProvider::class),
            ObjectManager::getInstance(TranslationRecord::class),
            ObjectManager::getInstance(EventsManager::class)
        );

        return $this->translationService;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'translationService',
            'name' => __('Translation service query provider'),
            'description' => __('Provides frontend worker translation operations.'),
            'module' => 'Weline_TranslationService',
            'operations' => [
                [
                    'name' => 'translate',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'description' => __('Translate text through configured translation service.'),
                    'params' => [
                        ['name' => 'text', 'type' => 'string', 'required' => true, 'max_length' => 16000],
                        ['name' => 'target_language', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'source_language', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'provider_code', 'type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                ],
                [
                    'name' => 'batchTranslate',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 10,
                    'description' => __('Translate multiple texts through configured translation service.'),
                    'params' => [
                        ['name' => 'texts', 'type' => 'list', 'required' => true, 'max_items' => 50],
                        ['name' => 'target_language', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'source_language', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'provider_code', 'type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                ],
            ],
        ];
    }
}
