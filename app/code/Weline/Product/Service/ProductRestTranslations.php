<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\App\State;
use Weline\Product\Api\Data\ProductAdminCommand;

/** 将 REST 文案整理为同一商品命令中的语言分组。 */
final class ProductRestTranslations
{
    public const FIELDS = ['name', 'short_description', 'description', 'meta_name', 'meta_description', 'meta_keywords'];

    public function __construct(private readonly ?\Closure $translate = null)
    {
    }

    public static function normalizeLocale(mixed $locale): string
    {
        if (!is_string($locale) || !preg_match('/^[a-z]{2,3}(?:[-_][a-z]{4})?(?:[-_](?:[a-z]{2}|[0-9]{3}))?$/iD', trim($locale))) {
            throw new \InvalidArgumentException('product_api_locale_invalid');
        }
        $parts = explode('_', str_replace('-', '_', trim($locale)));
        $parts[0] = strtolower($parts[0]);
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $parts[$index] = strlen($part) === 4 ? ucfirst(strtolower($part)) : strtoupper($part);
            }
        }
        $normalized = implode('_', $parts);
        if (State::isAllowedLanguageCode($normalized)) {
            return $normalized;
        }

        // 浏览器常省略脚本；只匹配已安装目录中的同语言、同地区，避免默认语言回退。
        if (count($parts) === 2 && strlen($parts[1]) !== 4) {
            $repository = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\I18n\Api\Localization\LocaleRepositoryInterface::class,
            );
            $matches = [];
            foreach ($repository->installedActive((string)State::getLangLocal()) as $record) {
                $candidate = explode('_', $record->code);
                if (count($candidate) === 3 && strlen($candidate[1]) === 4
                    && $candidate[0] === $parts[0] && $candidate[2] === $parts[1]
                    && State::isAllowedLanguageCode($record->code)
                ) {
                    $matches[$record->code] = true;
                }
            }
            if (count($matches) === 1) {
                return (string)array_key_first($matches);
            }
        }
        throw new \InvalidArgumentException('product_api_locale_unsupported');
    }

    /** 按请求头的偏好权重选择已登记语言；明确的不支持语言不会静默写入默认值。 */
    public static function negotiateLocale(string $header, string $fallback): string
    {
        if (trim($header) === '') {
            return self::normalizeLocale($fallback);
        }
        $choices = [];
        foreach (explode(',', $header) as $index => $item) {
            $parts = array_map('trim', explode(';', $item));
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                if (preg_match('/^q=(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/iD', $parameter, $match)) {
                    $quality = (float)$match[1];
                }
            }
            if ($quality > 0) {
                $choices[] = ['locale' => $parts[0], 'quality' => $quality, 'index' => $index];
            }
        }
        usort($choices, static fn(array $a, array $b): int => ($b['quality'] <=> $a['quality']) ?: ($a['index'] <=> $b['index']));
        foreach ($choices as $choice) {
            try {
                return self::normalizeLocale($choice['locale'] === '*' ? $fallback : $choice['locale']);
            } catch (\InvalidArgumentException) {
            }
        }
        throw new \InvalidArgumentException('product_api_locale_unsupported');
    }

    public function prepareCommand(ProductAdminCommand $command, array $body, string $requestLocale): ProductAdminCommand
    {
        $locale = self::normalizeLocale($requestLocale);
        $payload = $command->payload;
        $groups = [];
        foreach ([$payload['translations'] ?? [], $body['translations'] ?? []] as $translations) {
            if (!is_array($translations) || ($translations !== [] && array_is_list($translations))) {
                throw new \InvalidArgumentException('product_api_translations_invalid');
            }
            foreach ($translations as $key => $group) {
                $key = self::normalizeLocale($key);
                $groups[$key] = array_replace($groups[$key] ?? [], self::normalizeGroup($group));
            }
        }

        $source = array_intersect_key($payload, array_flip(self::FIELDS));
        if (array_key_exists('store_id', $payload)) {
            $source['store_id'] = ProductRestInput::nonNegativeInt($payload['store_id'], 'store_id');
        }
        $source = self::normalizeGroup($source);
        $sharedRows = [];
        if (array_key_exists('attributes', $payload)) {
            if (!is_array($payload['attributes'])) {
                throw new \InvalidArgumentException('product_attributes_invalid');
            }
            foreach ($payload['attributes'] as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('product_attribute_invalid');
                }
                if (array_key_exists('locale', $row) && $row['locale'] === '') {
                    $sharedRows[] = $row;
                    continue;
                }
                $rowLocale = array_key_exists('locale', $row) ? self::normalizeLocale($row['locale']) : $locale;
                $row['locale'] = $rowLocale;
                $row['store_id'] ??= $payload['store_id'] ?? 0;
                $groups[$rowLocale]['attributes'][] = $row;
            }
            $payload['attributes'] = $sharedRows;
        }
        if ($source !== []) {
            $groups[$locale] = array_replace($source, $groups[$locale] ?? []);
        }

        $targets = $body['translate_to'] ?? $payload['translate_to'] ?? [];
        if (!is_array($targets) || !array_is_list($targets)) {
            throw new \InvalidArgumentException('product_api_translate_to_invalid');
        }
        $targets = array_values(array_unique(array_map(self::normalizeLocale(...), $targets)));
        $sourceFields = array_intersect_key($groups[$locale] ?? [], array_flip(self::FIELDS));
        if ($targets !== [] && $sourceFields === []) {
            throw new \InvalidArgumentException('product_api_translation_source_required');
        }
        foreach ($targets as $target) {
            if ($target === $locale) {
                continue;
            }
            $fields = [];
            foreach ($sourceFields as $field => $value) {
                if (array_key_exists($field, $groups[$target] ?? [])) {
                    continue;
                }
                if ($value === '') {
                    $groups[$target][$field] = '';
                } else {
                    $fields[$field] = $value;
                }
            }
            if ($fields !== []) {
                $translated = $this->translateTexts(array_values($fields), $target, $locale);
                $groups[$target] = array_replace($groups[$target] ?? [], array_combine(array_keys($fields), $translated));
            }
            if (isset($groups[$locale]['store_id'])) {
                $groups[$target]['store_id'] ??= $groups[$locale]['store_id'];
            }
        }

        foreach ($groups as $key => $group) {
            $groups[$key] = self::normalizeGroup($group);
        }

        // 新建时只以源文初始化无语言回退；后续编辑全部走明确语言，不再改写回退值。
        foreach (self::FIELDS as $field) {
            unset($payload[$field]);
        }
        if ($command->action === ProductAdminCommand::ACTION_CREATE) {
            foreach ($sourceFields as $field => $value) {
                $payload[$field] = $value;
            }
        }
        unset($payload['translate_to'], $payload['local_code']);
        $payload['locale'] = '';
        $payload['translations'] = $groups;

        return new ProductAdminCommand(
            action: $command->action,
            websiteId: $command->websiteId,
            globalProductUuid: $command->globalProductUuid,
            expectedVersion: $command->expectedVersion,
            requestHash: $command->requestHash,
            actorId: $command->actorId,
            payload: $payload,
        );
    }

    private static function normalizeGroup(mixed $group): array
    {
        if (!is_array($group) || ($group !== [] && array_is_list($group))) {
            throw new \InvalidArgumentException('product_api_translation_group_invalid');
        }
        foreach ($group as $field => $value) {
            if ($field === 'store_id') {
                $group[$field] = ProductRestInput::nonNegativeInt($value, 'store_id');
            } elseif ($field === 'attributes') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('product_attributes_invalid');
                }
            } elseif (in_array($field, self::FIELDS, true)) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('product_api_translation_value_invalid');
                }
                $group[$field] = trim($value);
                $maximum = match ($field) { 'name', 'meta_name' => 255, 'description' => 20000, default => 2000 };
                if (strlen($group[$field]) > $maximum) {
                    throw new \InvalidArgumentException('product_' . $field . '_too_large');
                }
                if ($field === 'name' && $group[$field] === '') {
                    throw new \InvalidArgumentException('product_name_required');
                }
            } else {
                throw new \InvalidArgumentException('product_api_translation_field_invalid');
            }
        }
        return $group;
    }

    /** 使用已有公开 Query；所有译文准备成功后才交给商品事务。 */
    private function translateTexts(array $texts, string $target, string $source): array
    {
        try {
            if ($this->translate !== null) {
                $translated = ($this->translate)($texts, $target, $source);
            } else {
                $result = \w_query('translationService', 'batchTranslate', [
                    'texts' => $texts, 'target_language' => $target, 'source_language' => $source,
                ]);
                if (!is_array($result) || ($result['success'] ?? false) !== true) {
                    throw new \RuntimeException('translation_result_invalid');
                }
                $translated = $result['data']['translated_texts'] ?? null;
            }
            if (!is_array($translated) || !array_is_list($translated) || count($translated) !== count($texts)) {
                throw new \RuntimeException('translation_result_invalid');
            }
            foreach ($translated as $text) {
                if (!is_string($text) || trim($text) === '') {
                    throw new \RuntimeException('translation_result_invalid');
                }
            }
            return $translated;
        } catch (\Throwable $exception) {
            throw new \RuntimeException('product_api_translation_failed', 502, $exception);
        }
    }

    /** 在管理快照旁附加目标语言视图，保留原始属性供应用管理各语言。 */
    public static function localizeSnapshot(array $snapshot, string $locale): array
    {
        $translations = [];
        $base = [];
        foreach ($snapshot['attributes'] ?? [] as $row) {
            if (($row['entity_type'] ?? '') !== 'product'
                || (int)($row['entity_id'] ?? 0) !== (int)($snapshot['product']['product_id'] ?? 0)
                || (int)($row['store_id'] ?? 0) !== 0
            ) {
                continue;
            }
            $code = (string)($row['attribute_code'] ?? '');
            $rowLocale = (string)($row['locale'] ?? '');
            $value = ($row['scope_state'] ?? '') === 'cleared' ? null : ($row['value'] ?? null);
            if ($rowLocale === '') {
                $base[$code] = $value;
            } else {
                $translations[$rowLocale][$code] = $value;
            }
        }
        $snapshot['locale'] = $locale;
        $snapshot['content'] = array_replace($base, $translations[$locale] ?? []);
        $snapshot['translations'] = $translations;
        return $snapshot;
    }
}
