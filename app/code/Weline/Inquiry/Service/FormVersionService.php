<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Inquiry\Model\Form;
use Weline\Inquiry\Model\FormVersion;
use Weline\Inquiry\Model\FormVersion\LocalDescription;

final class FormVersionService
{
    public function __construct(private readonly Form $form, private readonly FormVersion $version, private readonly LocalDescription $localDescription, private readonly FormSchemaService $schema) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveDraft(array $payload): array
    {
        $formId = (int)($payload['form_id'] ?? 0);
        $code = strtolower(trim((string)($payload['code'] ?? '')));
        $name = trim((string)($payload['name'] ?? ''));
        $locale = trim((string)($payload['default_locale'] ?? 'en_US'));
        if ($name === '' || preg_match('/^[a-z][a-z0-9-]{1,95}$/', $code) !== 1) { throw new \InvalidArgumentException((string)__('表单名称和 code 不合法')); }
        $existing = $formId > 0 ? $this->form->load($formId) : null;
        if ($formId > 0 && !$existing?->getId()) { throw new \InvalidArgumentException((string)__('表单不存在')); }
        if ($existing?->getId() && (int)$existing->getData(Form::schema_fields_PUBLISHED_VERSION_ID) > 0 && $existing->getData(Form::schema_fields_CODE) !== $code) { throw new \InvalidArgumentException((string)__('表单首次发布后 code 不可修改')); }
        $schema = $this->schema->normalize((array)($payload['schema'] ?? []));
        $now = date('Y-m-d H:i:s');
        $target = $existing?->getId() ? $existing : $this->form->clear();
        $target->setData([Form::schema_fields_CODE => $code, Form::schema_fields_NAME => $name, Form::schema_fields_DEFAULT_LOCALE => $locale, Form::schema_fields_STATUS => Form::STATUS_DRAFT, Form::schema_fields_UPDATED_AT => $now]);
        if (!$target->getId()) { $target->setData(Form::schema_fields_CREATED_AT, $now); }
        $target->save();
        $formId = (int)$target->getId();
        $draftId = (int)$target->getData(Form::schema_fields_DRAFT_VERSION_ID);
        $versionNo = 1;
        if ($draftId > 0) { $draft = $this->version->load($draftId); $versionNo = (int)$draft->getData(FormVersion::schema_fields_VERSION_NO); }
        else { $max = $this->version->reset()->where(FormVersion::schema_fields_FORM_ID, $formId)->order(FormVersion::schema_fields_VERSION_NO, 'DESC')->limit(1)->select()->fetchArray(); $versionNo = ((int)($max[0][FormVersion::schema_fields_VERSION_NO] ?? 0)) + 1; $draft = $this->version->clear(); }
        $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $draft->setData([FormVersion::schema_fields_FORM_ID => $formId, FormVersion::schema_fields_VERSION_NO => $versionNo, FormVersion::schema_fields_STATUS => FormVersion::STATUS_DRAFT, FormVersion::schema_fields_SCHEMA_JSON => $json, FormVersion::schema_fields_SETTINGS_JSON => json_encode((array)($payload['settings'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), FormVersion::schema_fields_CHECKSUM => hash('sha256', $json)]);
        if (!$draft->getId()) { $draft->setData(FormVersion::schema_fields_CREATED_AT, $now); }
        $draft->save();
        $target->setData(Form::schema_fields_DRAFT_VERSION_ID, (int)$draft->getId())->save();
        $this->saveTranslations((int)$draft->getId(), (array)($payload['translations'] ?? []));
        return $this->draft((int)$target->getId());
    }

    /** @return array<string,mixed> */
    public function publish(int $formId): array
    {
        $form = $this->form->load($formId); $draftId = (int)$form->getData(Form::schema_fields_DRAFT_VERSION_ID);
        if (!$form->getId() || $draftId <= 0) { throw new \InvalidArgumentException((string)__('没有可发布的草稿')); }
        $translations = $this->translations($draftId); $default = (string)$form->getData(Form::schema_fields_DEFAULT_LOCALE);
        if (empty($translations[$default]['title']) || empty($translations[$default]['submit_label'])) { throw new \InvalidArgumentException((string)__('默认语言必须填写表单标题和提交按钮文案')); }
        $draft = $this->version->load($draftId); $draft->setData(FormVersion::schema_fields_STATUS, FormVersion::STATUS_PUBLISHED)->save();
        $form->setData([Form::schema_fields_STATUS => Form::STATUS_PUBLISHED, Form::schema_fields_PUBLISHED_VERSION_ID => $draftId, Form::schema_fields_UPDATED_AT => date('Y-m-d H:i:s')])->save();
        return $this->draft($formId);
    }

    /** @return array<string,mixed> */
    public function draft(int $formId): array
    {
        $form = $this->form->load($formId); if (!$form->getId()) { throw new \InvalidArgumentException((string)__('表单不存在')); }
        $versionId = (int)($form->getData(Form::schema_fields_DRAFT_VERSION_ID) ?: $form->getData(Form::schema_fields_PUBLISHED_VERSION_ID));
        $version = $versionId ? $this->version->load($versionId) : $this->version->reset();
        return ['form' => $form->getData(), 'version' => $version->getData(), 'schema' => json_decode((string)$version->getData(FormVersion::schema_fields_SCHEMA_JSON), true) ?: ['fields' => []], 'settings' => json_decode((string)$version->getData(FormVersion::schema_fields_SETTINGS_JSON), true) ?: [], 'translations' => $this->translations($versionId)];
    }

    /** @param array<string,mixed> $translations */
    private function saveTranslations(int $versionId, array $translations): void
    {
        foreach ($translations as $locale => $copy) {
            $locale = trim((string)$locale); if ($locale === '' || !is_array($copy)) { continue; }
            $this->localDescription->clear()->setData([LocalDescription::schema_fields_ID => $versionId, LocalDescription::schema_fields_local_code => $locale, LocalDescription::schema_fields_CONFIG => json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->forceCheck(true, [LocalDescription::schema_fields_ID, LocalDescription::schema_fields_local_code])->save();
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function translations(int $versionId): array
    {
        if ($versionId <= 0) { return []; }
        $rows = $this->localDescription->reset()->where(LocalDescription::schema_fields_ID, $versionId)->select()->fetchArray(); $result = [];
        foreach ($rows as $row) { if (is_array($row) && ($locale = trim((string)($row[LocalDescription::schema_fields_local_code] ?? ''))) !== '') { $result[$locale] = json_decode((string)($row[LocalDescription::schema_fields_CONFIG] ?? '{}'), true) ?: []; } }
        return $result;
    }
}
