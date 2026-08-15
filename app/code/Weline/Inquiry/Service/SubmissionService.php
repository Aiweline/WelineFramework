<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Inquiry\Model\Attachment;
use Weline\Inquiry\Model\Submission;

final class SubmissionService
{
    public function __construct(private readonly LocalizedFormResolver $resolver, private readonly Submission $submission, private readonly Attachment $attachment) {}

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function submit(array $params): array
    {
        $code = trim((string)($params['code'] ?? '')); $locale = trim((string)($params['locale'] ?? '')); $values = is_array($params['values'] ?? null) ? $params['values'] : [];
        if (trim((string)($values['company_website'] ?? '')) !== '') { return ['accepted' => true, 'duplicate' => false]; }
        $resolved = $this->resolver->published($code, $locale); $form = $resolved['form']; $version = $resolved['version'];
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._-]{16,128}$/', $idempotencyKey) !== 1) { throw new \InvalidArgumentException((string)__('无效的幂等提交键')); }
        $existing = $this->submission->reset()->where(Submission::schema_fields_FORM_ID, (int)$form['form_id'])->where(Submission::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)->select()->fetchArray();
        if ($existing !== []) { return ['accepted' => true, 'duplicate' => true, 'submission_id' => (int)($existing[0]['submission_id'] ?? 0)]; }
        $clean = $this->validate((array)$resolved['schema'], $values);
        $submission = $this->submission->clear()->setData([
            Submission::schema_fields_FORM_ID => (int)$form['form_id'], Submission::schema_fields_VERSION_ID => (int)$version['version_id'], Submission::schema_fields_LOCALE => (string)$resolved['locale'], Submission::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
            Submission::schema_fields_PAYLOAD_JSON => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), Submission::schema_fields_SCHEMA_SNAPSHOT_JSON => json_encode(['schema' => $resolved['schema'], 'copy' => $resolved['copy']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            Submission::schema_fields_SOURCE_FINGERPRINT => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), Submission::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ]);
        $submission->save();
        $this->saveAttachments((int)$submission->getId(), $values['_attachments'] ?? []);
        return ['accepted' => true, 'duplicate' => false, 'submission_id' => (int)$submission->getId(), 'message' => (string)($resolved['copy']['success_message'] ?? __('提交成功，我们会尽快联系您。'))];
    }

    /** @param array<string,mixed> $schema @param array<string,mixed> $values @return array<string,mixed> */
    private function validate(array $schema, array $values): array
    {
        $result = [];
        foreach ((array)($schema['fields'] ?? []) as $field) {
            if (!is_array($field)) { continue; }
            $key = (string)$field['key']; $type = (string)$field['type']; $value = $values[$key] ?? ($type === 'checkbox' ? [] : '');
            if ($type === 'checkbox') { $value = is_array($value) ? array_values(array_map('strval', $value)) : [(string)$value]; }
            else { $value = trim((string)$value); }
            if (($field['required'] ?? false) && ($value === '' || $value === [])) { throw new \InvalidArgumentException((string)__('请填写：%{1}', $key)); }
            if ($value !== '' && $type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) { throw new \InvalidArgumentException((string)__('邮箱格式不正确')); }
            if ($value !== '' && in_array($type, ['select', 'radio'], true)) { $allowed = array_column((array)$field['options'], 'value'); if (!in_array($value, $allowed, true)) { throw new \InvalidArgumentException((string)__('选择项无效')); } }
            if (is_string($value)) { $rules = (array)($field['validation'] ?? []); if (isset($rules['min_length']) && mb_strlen($value) < (int)$rules['min_length']) { throw new \InvalidArgumentException((string)__('填写内容过短')); } if (isset($rules['max_length']) && mb_strlen($value) > (int)$rules['max_length']) { throw new \InvalidArgumentException((string)__('填写内容过长')); } if (!empty($rules['pattern']) && @preg_match('/' . str_replace('/', '\\/', (string)$rules['pattern']) . '/', $value) !== 1) { throw new \InvalidArgumentException((string)__('填写格式不正确')); } }
            $result[$key] = $value;
        }
        return $result;
    }
    /** @param mixed $attachments */
    private function saveAttachments(int $submissionId, mixed $attachments): void
    {
        foreach (is_array($attachments) ? $attachments : [] as $file) {
            if (!is_array($file) || preg_match('/^[A-Za-z0-9._-]{16,128}$/', (string)($file['ticket'] ?? '')) !== 1) { continue; }
            $this->attachment->clear()->setData([Attachment::schema_fields_SUBMISSION_ID => $submissionId, Attachment::schema_fields_UPLOAD_TICKET => (string)$file['ticket'], Attachment::schema_fields_FILENAME => mb_substr((string)($file['name'] ?? ''), 0, 255), Attachment::schema_fields_MIME_TYPE => mb_substr((string)($file['mime'] ?? ''), 0, 127), Attachment::schema_fields_SIZE => max(0, (int)($file['size'] ?? 0)), Attachment::schema_fields_CREATED_AT => date('Y-m-d H:i:s')])->save();
        }
    }
}
