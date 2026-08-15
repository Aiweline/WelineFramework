<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Inquiry\Model\Form;
use Weline\Inquiry\Model\FormVersion;

final class LocalizedFormResolver
{
    public function __construct(private readonly Form $form, private readonly FormVersion $version, private readonly FormVersionService $versions) {}

    /** @return array<string,mixed> */
    public function published(string $code, string $locale = ''): array
    {
        $rows = $this->form->reset()->where(Form::schema_fields_CODE, trim($code))->where(Form::schema_fields_STATUS, Form::STATUS_PUBLISHED)->select()->fetchArray();
        $form = $rows[0] ?? null;
        if (!is_array($form) || (int)($form[Form::schema_fields_PUBLISHED_VERSION_ID] ?? 0) <= 0) { throw new \InvalidArgumentException((string)__('未找到已发布的询盘表单')); }
        $versionId = (int)$form[Form::schema_fields_PUBLISHED_VERSION_ID];
        $version = $this->version->load($versionId);
        $schema = json_decode((string)$version->getData(FormVersion::schema_fields_SCHEMA_JSON), true) ?: ['fields' => []];
        $translations = $this->versions->translations($versionId);
        $locale = trim($locale) ?: (string)$form[Form::schema_fields_DEFAULT_LOCALE];
        $copy = $this->resolveCopy($translations, $locale, (string)$form[Form::schema_fields_DEFAULT_LOCALE]);
        return ['form' => $form, 'version' => $version->getData(), 'schema' => $schema, 'settings' => json_decode((string)$version->getData(FormVersion::schema_fields_SETTINGS_JSON), true) ?: [], 'locale' => $locale, 'copy' => $copy];
    }

    /** @param array<string,array<string,mixed>> $translations @return array<string,mixed> */
    private function resolveCopy(array $translations, string $locale, string $defaultLocale): array
    {
        $fallbackLocale = 'en_US';
        $layers = array_values(array_filter([$translations[$fallbackLocale] ?? [], $translations[$defaultLocale] ?? [], $translations[$locale] ?? []], 'is_array'));
        $result = [];
        foreach ($layers as $layer) { $result = $this->merge($result, $layer); }
        return $result;
    }
    /** @param array<string,mixed> $base @param array<string,mixed> $override @return array<string,mixed> */
    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) { $base[$key] = is_array($value) && is_array($base[$key] ?? null) ? $this->merge($base[$key], $value) : $value; }
        return $base;
    }
}
