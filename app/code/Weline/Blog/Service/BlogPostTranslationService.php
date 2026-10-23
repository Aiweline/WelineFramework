<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\Post;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Websites\Data\WebsiteData;

final class BlogPostTranslationService
{
    public const SETTING_MODULE = 'Weline_Blog';
    public const SETTING_AREA = 'blog';
    public const SETTING_KEY_AUTO = 'auto_translate_missing_locales';
    public const MAX_TARGET_LOCALES = 3;
    public const MAX_CHARS_PER_REQUEST = 1800;

    public function __construct(
        private readonly Post $postModel,
        private readonly BlogPostAdminService $adminService,
        private readonly EventsManager $eventsManager,
    ) {
    }

    public function isAutoTranslateEnabled(): bool
    {
        try {
            return (int)$this->systemConfig()->getConfig(self::SETTING_KEY_AUTO, self::SETTING_MODULE, self::SETTING_AREA, '0') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function setAutoTranslateEnabled(bool $enabled): void
    {
        $this->systemConfig()->setConfig(self::SETTING_KEY_AUTO, $enabled ? '1' : '0', self::SETTING_MODULE, self::SETTING_AREA);
    }

    /**
     * 当前翻译默认模型是否为模拟供应商（fake / fake-*）。
     */
    public function isSimulatedProvider(): bool
    {
        try {
            $manager = ObjectManager::getInstance(\Weline\Ai\Service\DefaultModelManager::class);
            $model = $manager->getDefaultModel('translation');
            if ($model === null) {
                return false;
            }

            return BlogTranslationSimulation::isFake(
                (string)$model->getData(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE),
                (string)$model->getData(\Weline\Ai\Model\AiModel::schema_fields_SUPPLIER)
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function systemConfig(): SystemConfig
    {
        return ObjectManager::getInstance(SystemConfig::class);
    }

    public function enabledLocales(int $websiteId): array
    {
        $locales = [];
        foreach (WebsiteData::languageCodesForWebsite($websiteId) as $locale) {
            $locale = trim((string)$locale);
            if ($locale === '') {
                continue;
            }
            $locales[$locale] = $locale;
        }
        return array_values($locales);
    }

    public function versions(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }
        $rows = (clone $this->postModel)->clearData()->reset()
            ->where(Post::schema_fields_TRANSLATION_OF, $postId)
            ->select()
            ->fetchArray();
        $versions = [];
        foreach ($rows as $row) {
            $locale = trim((string)($row[Post::schema_fields_LOCALE] ?? ''));
            if ($locale === '') {
                continue;
            }
            $versions[$locale] = [
                'post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
                'title' => (string)($row[Post::schema_fields_TITLE] ?? ''),
                'status' => (string)($row[Post::schema_fields_STATUS] ?? ''),
                'slug' => (string)($row[Post::schema_fields_SLUG] ?? ''),
            ];
        }
        return $versions;
    }

    public function missingLocales(int $postId, int $websiteId, string $sourceLocale): array
    {
        $have = array_keys($this->versions($postId));
        $have[] = $sourceLocale;
        return array_values(array_diff($this->enabledLocales($websiteId), $have));
    }

    public function translatePost(int $postId, array $locales, array $options = []): array
    {
        $result = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
        $simulated = $this->isSimulatedProvider();
        if ($simulated) {
            $result['simulation'] = true;
            $result['simulated'] = [];
        }
        $source = (clone $this->postModel)->clearData()->reset()->load($postId);
        if ($source->getPostId() <= 0) {
            $result['errors'][] = '文章不存在';
            return $result;
        }
        $sourceData = $source->getData();
        $sourceLocale = (string)($sourceData[Post::schema_fields_LOCALE] ?? '');
        $websiteId = (int)($sourceData[Post::schema_fields_WEBSITE_ID] ?? 0);
        $sourceStatus = (string)($sourceData[Post::schema_fields_STATUS] ?? '');
        $baseSlug = (string)($sourceData[Post::schema_fields_SLUG] ?? '');
        $overwrite = !empty($options['overwrite']);
        $asDraft = !empty($options['as_draft']);

        $targets = [];
        $enabled = $this->enabledLocales($websiteId);
        foreach ($locales as $locale) {
            $locale = trim((string)$locale);
            if ($locale === '' || $locale === $sourceLocale || !in_array($locale, $enabled, true) || in_array($locale, $targets, true)) {
                continue;
            }
            $targets[] = $locale;
        }
        if ($targets === []) {
            return $result;
        }

        $texts = $this->collectTexts($sourceData);
        foreach ($targets as $locale) {
            $existing = $this->versions($postId);
            $isUpdate = isset($existing[$locale]);
            if ($isUpdate && !$overwrite) {
                $result['skipped'][$locale] = $existing[$locale];
                continue;
            }
            $translated = $this->translateTexts($texts, $sourceLocale, $locale);
            $map = $translated['map'];
            if ($map === []) {
                $detail = $translated['errors'] !== []
                    ? ': ' . implode('; ', array_slice($translated['errors'], 0, 2))
                    : '';
                $result['errors'][] = $locale . ': AI 未返回译文' . $detail;
                continue;
            }
            $data = $sourceData;
            $data[Post::schema_fields_ID] = $isUpdate ? $existing[$locale]['post_id'] : 0;
            $data[Post::schema_fields_TRANSLATION_OF] = $postId;
            $data[Post::schema_fields_LOCALE] = $locale;
            $data[Post::schema_fields_TITLE] = $this->mapped($map, (string)($sourceData[Post::schema_fields_TITLE] ?? ''));
            $data[Post::schema_fields_EXCERPT] = $this->mapped($map, (string)($sourceData[Post::schema_fields_EXCERPT] ?? ''));
            $data[Post::schema_fields_KEYWORDS] = $this->mapped($map, (string)($sourceData[Post::schema_fields_KEYWORDS] ?? ''));
            $data[Post::schema_fields_CONTENT] = BlogTranslationHtml::rebuild((string)($sourceData[Post::schema_fields_CONTENT] ?? ''), $map);
            $data[Post::schema_fields_STATUS] = $asDraft ? Post::STATUS_DRAFT : $sourceStatus;
            $data[Post::schema_fields_PUBLISHED_AT] = $data[Post::schema_fields_STATUS] === Post::STATUS_PUBLISHED ? date('Y-m-d H:i:s') : null;

            if ($simulated) {
                // 模拟供应商（fake）结果只做预览，绝不入库。
                $data[Post::schema_fields_SLUG] = BlogTranslationHtml::slugFor($baseSlug, $locale, 0);
                $result['simulated'][$locale] = BlogTranslationSimulation::preview($data);
                continue;
            }

            $saved = null;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $data[Post::schema_fields_SLUG] = BlogTranslationHtml::slugFor($baseSlug, $locale, $attempt);
                try {
                    $saved = $this->adminService->save($data);
                    break;
                } catch (\Throwable) {
                    $saved = null;
                }
            }
            if (!is_array($saved) || (int)($saved['post_id'] ?? 0) <= 0) {
                $result['errors'][] = $locale . ': 保存失败';
                continue;
            }
            $bucket = $isUpdate ? 'updated' : 'created';
            $result[$bucket][$locale] = [
                'post_id' => (int)$saved['post_id'],
                'slug' => (string)($saved['slug'] ?? $data[Post::schema_fields_SLUG]),
                'title' => (string)($saved['title'] ?? $data[Post::schema_fields_TITLE]),
                'status' => (string)($saved['status'] ?? $data[Post::schema_fields_STATUS]),
            ];
        }
        return $result;
    }

    private function collectTexts(array $data): array
    {
        $texts = [];
        foreach ([Post::schema_fields_TITLE, Post::schema_fields_EXCERPT, Post::schema_fields_KEYWORDS] as $field) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value !== '') {
                $texts[$value] = $value;
            }
        }
        foreach (BlogTranslationHtml::textNodes((string)($data[Post::schema_fields_CONTENT] ?? '')) as $text) {
            $texts[$text] = $text;
        }
        return array_values($texts);
    }

    private function translateTexts(array $texts, string $sourceLocale, string $targetLocale): array
    {
        $chunks = [];
        $chunk = [];
        $length = 0;
        foreach ($texts as $text) {
            $textLength = mb_strlen($text);
            if ($chunk !== [] && $length + $textLength > self::MAX_CHARS_PER_REQUEST) {
                $chunks[] = $chunk;
                $chunk = [];
                $length = 0;
            }
            $chunk[] = $text;
            $length += $textLength;
        }
        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        $map = [];
        $errors = [];
        foreach ($chunks as $chunk) {
            $eventData = [
                'words' => array_combine($chunk, $chunk),
                'source_locale' => $sourceLocale,
                'target_locale' => $targetLocale,
                'strategy' => 'light',
                'concurrency_lane' => 'local-model',
                'translations' => [],
                'errors' => [],
                'success' => false,
            ];
            $this->eventsManager->dispatch('Weline_I18n::machine_translate', $eventData);
            $translations = $eventData['translations'] ?? [];
            if (is_array($translations)) {
                foreach ($translations as $source => $target) {
                    $source = trim((string)$source);
                    $target = (string)$target;
                    if ($source !== '' && trim($target) !== '') {
                        $map[$source] = $target;
                    }
                }
            }
            foreach ((array)($eventData['errors'] ?? []) as $error) {
                $error = trim((string)$error);
                if ($error !== '') {
                    $errors[$error] = $error;
                }
            }
        }

        return ['map' => $map, 'errors' => array_values($errors)];
    }

    private function mapped(array $map, string $original): string
    {
        $key = trim($original);
        return ($key !== '' && isset($map[$key])) ? (string)$map[$key] : $original;
    }

    public function autoFill(int $postId): array
    {
        $result = ['enabled' => true, 'created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
        if (!$this->isAutoTranslateEnabled()) {
            $result['enabled'] = false;
            return $result;
        }
        $source = (clone $this->postModel)->clearData()->reset()->load($postId);
        if ($source->getPostId() <= 0) {
            $result['errors'][] = '文章不存在';
            return $result;
        }
        $sourceData = $source->getData();
        if ((string)($sourceData[Post::schema_fields_STATUS] ?? '') !== Post::STATUS_PUBLISHED) {
            return $result;
        }
        $missing = $this->missingLocales(
            $postId,
            (int)($sourceData[Post::schema_fields_WEBSITE_ID] ?? 0),
            (string)($sourceData[Post::schema_fields_LOCALE] ?? '')
        );
        if ($missing === []) {
            return $result;
        }
        // 自动补齐一律先存草稿：机器译文未经人工确认，不能直接上线。
        $translated = $this->translatePost($postId, array_slice($missing, 0, self::MAX_TARGET_LOCALES), ['as_draft' => true]);
        $result['created'] = $translated['created'];
        $result['updated'] = $translated['updated'];
        $result['skipped'] = $translated['skipped'];
        $result['errors'] = $translated['errors'];
        if (!empty($translated['simulation'])) {
            $result['simulation'] = true;
            $result['simulated'] = $translated['simulated'] ?? [];
        }
        return $result;
    }
}
