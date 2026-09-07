<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

/** Validates the neutral schema; translations deliberately live outside this payload. */
final class FormSchemaService
{
    public const FIELD_TYPES = ['text', 'textarea', 'email', 'tel', 'number', 'select', 'radio', 'checkbox', 'file', 'hidden', 'country'];

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    public function normalize(array $schema): array
    {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $seen = [];
        $normalized = [];
        foreach ($fields as $sortOrder => $field) {
            if (!is_array($field)) { continue; }
            $key = strtolower(trim((string)($field['key'] ?? '')));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || isset($seen[$key])) {
                throw new \InvalidArgumentException('inquiry_schema_invalid_or_duplicate_key');
            }
            $seen[$key] = true;
            $type = strtolower(trim((string)($field['type'] ?? 'text')));
            if (!in_array($type, self::FIELD_TYPES, true)) { throw new \InvalidArgumentException('inquiry_schema_unsupported_field_type:' . $type); }
            $options = [];
            foreach ((array)($field['options'] ?? []) as $option) {
                if (!is_array($option)) { continue; }
                $value = trim((string)($option['value'] ?? ''));
                if ($value !== '') { $options[] = ['value' => $value]; }
            }
            if (in_array($type, ['select', 'radio'], true) && $options === []) {
                throw new \InvalidArgumentException('inquiry_schema_choice_options_required');
            }
            $validationRaw = is_array($field['validation'] ?? null) ? $field['validation'] : [];
            if ($type === 'country' && !isset($validationRaw['catalog']) && isset($field['catalog'])) {
                $validationRaw['catalog'] = $field['catalog'];
            }
            $validation = $this->validation($validationRaw, $type);
            $normalized[] = [
                'key' => $key, 'type' => $type, 'required' => (bool)($field['required'] ?? false),
                'options' => $options, 'validation' => $validation,
                'sort_order' => (int)($field['sort_order'] ?? $sortOrder),
            ];
        }
        if ($normalized === []) { throw new \InvalidArgumentException('inquiry_schema_fields_required'); }
        usort($normalized, static fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
        return ['fields' => $normalized, 'honeypot' => 'company_website'];
    }

    /** @param mixed $raw @return array<string,mixed> */
    private function validation(mixed $raw, string $fieldType = 'text'): array
    {
        $raw = is_array($raw) ? $raw : [];
        $result = [];
        foreach (['min_length', 'max_length', 'min', 'max', 'pattern'] as $key) {
            if (!array_key_exists($key, $raw)) { continue; }
            $value = $raw[$key];
            if (in_array($key, ['min_length', 'max_length'], true)) { $value = max(0, min(10000, (int)$value)); }
            if (in_array($key, ['min', 'max'], true)) { $value = (float)$value; }
            if ($key === 'pattern') { $value = trim((string)$value); if (strlen($value) > 255) { throw new \InvalidArgumentException('inquiry_schema_pattern_too_long'); } }
            $result[$key] = $value;
        }
        if ($fieldType === 'country') {
            $catalog = strtolower(trim((string)($raw['catalog'] ?? 'global')));
            $result['catalog'] = in_array($catalog, ['installed', 'global'], true) ? $catalog : 'global';
            $levelsRaw = trim((string)($raw['levels'] ?? 'country|province|city|district'));
            $allowedLevels = ['country', 'province', 'city', 'district', 'street'];
            $levels = [];
            foreach (preg_split('/[|,]+/', $levelsRaw) ?: [] as $level) {
                $level = strtolower(trim((string)$level));
                if ($level !== '' && in_array($level, $allowedLevels, true) && !in_array($level, $levels, true)) {
                    $levels[] = $level;
                }
            }
            if ($levels === []) {
                $levels = ['country', 'province', 'city', 'district'];
            }
            $result['levels'] = implode('|', $levels);
            $selection = strtolower(trim((string)($raw['selection'] ?? 'single')));
            $result['selection'] = $selection === 'multi' ? 'multi' : 'single';
        }
        return $result;
    }
}
