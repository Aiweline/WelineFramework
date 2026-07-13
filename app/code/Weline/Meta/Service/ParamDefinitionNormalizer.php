<?php

declare(strict_types=1);

namespace Weline\Meta\Service;

use Weline\Meta\Api\ParamDefinitionNormalizerInterface;

class ParamDefinitionNormalizer implements ParamDefinitionNormalizerInterface
{
    /**
     * @param array<string|int, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    public function normalizeDefinitions(array $params): array
    {
        $normalized = [];

        foreach ($params as $name => $definition) {
            if (is_int($name) && is_array($definition) && isset($definition['name'])) {
                $name = (string)$definition['name'];
            }
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }

            if (!is_array($definition)) {
                $definition = ['default' => $definition];
            }

            $normalized[$name] = $this->normalizeDefinition($name, $definition);
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $params
     * @return array<string, array<string, mixed>>
     */
    public function normalizeParsedParamList(array $params): array
    {
        $mapped = [];

        foreach ($params as $param) {
            if (!is_array($param)) {
                continue;
            }
            $name = trim((string)($param['param_name'] ?? $param['key'] ?? $param['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $mapped[$name] = $param;
        }

        return $this->normalizeDefinitions($mapped);
    }

    /**
     * Extract and normalize phtml @param annotations.
     *
     * Supported forms:
     * - @param hero_image {type="string", ui_type="media_image", i18n=false}
     * - @param.hero_image {type="string", input="media_image"}
     *
     * @return array<string, array<string, mixed>>
     */
    public function extractParamAnnotations(string $content): array
    {
        $params = [];
        if (!preg_match_all(
            '/@param(?:\s+([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s+\{|\.([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\{)/',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        foreach ($matches as $match) {
            $paramName = trim((string)(($match[1][0] ?? '') !== '' ? $match[1][0] : ($match[2][0] ?? '')));
            if ($paramName === '') {
                continue;
            }

            $openBracePos = (int)$match[0][1] + strlen((string)$match[0][0]) - 1;
            $attributeBlock = $this->readBalancedBlock($content, $openBracePos, '{', '}');
            if ($attributeBlock === null) {
                continue;
            }

            $params[$paramName] = array_merge(
                $params[$paramName] ?? [],
                $this->parseAttributeString($attributeBlock)
            );
        }

        return $this->normalizeDefinitions($params);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function normalizeDefinition(string $name, array $definition): array
    {
        $definition = $this->normalizeAliases($definition);

        $hasExplicitType = array_key_exists('type', $definition) && trim((string)$definition['type']) !== '';
        if (!$hasExplicitType && array_key_exists('default', $definition) && is_bool($definition['default'])) {
            $definition['type'] = 'bool';
            $definition['ui_type'] = $definition['ui_type'] ?? $definition['input'] ?? 'select';
            $definition['input'] = $definition['input'] ?? $definition['ui_type'];
        }

        $type = trim((string)($definition['type'] ?? 'string'));
        if ($type === '') {
            $type = 'string';
        }

        $uiType = trim((string)($definition['ui_type'] ?? $definition['input'] ?? $definition['ui'] ?? ''));
        if ($uiType === '') {
            $uiType = $type;
        }

        $label = $definition['label']
            ?? $definition['name_label']
            ?? $definition['title']
            ?? $definition['name']
            ?? $name;

        $definition['type'] = $type;
        $definition['ui_type'] = $uiType;
        $definition['input'] = $uiType;
        $definition['name'] = (string)$label;
        $definition['label'] = (string)$label;
        $definition['description'] = (string)($definition['description'] ?? '');
        $definition['required'] = $this->toBool($definition['required'] ?? false);

        $options = $definition['options'] ?? $definition['option'] ?? [];
        $definition['options'] = $this->normalizeOptions($options);
        if (in_array($type, ['bool', 'boolean'], true) && $definition['options'] === []) {
            $definition['options'] = $this->defaultBooleanOptions($name);
        }
        if (array_key_exists('option', $definition) && !is_array($definition['option'])) {
            unset($definition['option']);
        }

        if (array_key_exists('i18n', $definition)) {
            $definition['i18n'] = $this->toBool($definition['i18n']);
            $definition['translate'] = $definition['i18n'];
            $definition['translatable'] = $definition['i18n'];
        } elseif (array_key_exists('translate', $definition)) {
            $definition['translate'] = $this->toBool($definition['translate']);
            $definition['i18n'] = $definition['translate'];
            $definition['translatable'] = $definition['translate'];
        } elseif (array_key_exists('translatable', $definition)) {
            $definition['translatable'] = $this->toBool($definition['translatable']);
            $definition['i18n'] = $definition['translatable'];
            $definition['translate'] = $definition['translatable'];
        }

        if (array_key_exists('multiple', $definition)) {
            $definition['multiple'] = $this->toBool($definition['multiple']);
        }

        return $definition;
    }

    /**
     * @return array<string, string>
     */
    private function defaultBooleanOptions(string $name): array
    {
        $normalizedName = strtolower($name);
        if (str_starts_with($normalizedName, 'show') || str_contains($normalizedName, 'visible')) {
            return [
                '1' => '显示',
                '0' => '隐藏',
            ];
        }

        return [
            '1' => '是',
            '0' => '否',
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function normalizeAliases(array $definition): array
    {
        if (!isset($definition['ui_type']) && isset($definition['uiType'])) {
            $definition['ui_type'] = $definition['uiType'];
        }
        if (!isset($definition['ui_type']) && isset($definition['ui-type'])) {
            $definition['ui_type'] = $definition['ui-type'];
        }
        if (!isset($definition['label']) && isset($definition['name_label'])) {
            $definition['label'] = $definition['name_label'];
        }
        if (!isset($definition['options']) && isset($definition['values']) && is_array($definition['values'])) {
            $definition['options'] = array_combine(
                array_map('strval', $definition['values']),
                array_map('strval', $definition['values'])
            ) ?: [];
        }

        return $definition;
    }

    /**
     * @param mixed $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(mixed $options): array
    {
        if (is_array($options)) {
            if (array_is_list($options)) {
                $mapped = [];
                foreach ($options as $option) {
                    if (is_array($option)) {
                        $value = $option['value'] ?? $option['code'] ?? $option['id'] ?? null;
                        if ($value === null || $value === '') {
                            continue;
                        }
                        $mapped[(string)$value] = (string)($option['label'] ?? $option['name'] ?? $value);
                        continue;
                    }
                    $mapped[(string)$option] = (string)$option;
                }
                return $mapped;
            }
            return $options;
        }

        if (!is_string($options)) {
            return [];
        }

        $options = trim($options);
        if ($options === '') {
            return [];
        }

        $decoded = json_decode($options, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $normalized = [];
        foreach (explode(',', $options) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $separator = strpos($part, ':');
            if ($separator === false) {
                $normalized[$part] = $part;
                continue;
            }

            $key = trim(substr($part, 0, $separator), " \t\n\r\0\x0B'\"");
            $label = trim(substr($part, $separator + 1), " \t\n\r\0\x0B'\"");
            if ($key !== '') {
                $normalized[$key] = $label !== '' ? $label : $key;
            }
        }

        return $normalized;
    }

    /**
     * Return the inner block content for a balanced delimited value.
     */
    private function readBalancedBlock(string $content, int $openPos, string $openChar, string $closeChar): ?string
    {
        $length = strlen($content);
        if ($openPos < 0 || $openPos >= $length || $content[$openPos] !== $openChar) {
            return null;
        }

        $depth = 1;
        $quote = null;
        $escaped = false;
        $start = $openPos + 1;

        for ($pos = $start; $pos < $length; $pos++) {
            $char = $content[$pos];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === $openChar) {
                $depth++;
                continue;
            }

            if ($char === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $pos - $start);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAttributeString(string $attributesStr): array
    {
        $result = [];
        $pos = 0;
        $length = strlen($attributesStr);

        while ($pos < $length) {
            while ($pos < $length && (ctype_space($attributesStr[$pos]) || $attributesStr[$pos] === ',')) {
                $pos++;
            }
            if ($pos >= $length) {
                break;
            }

            $keyStart = $pos;
            while ($pos < $length && preg_match('/[A-Za-z0-9_\-]/', $attributesStr[$pos])) {
                $pos++;
            }
            $key = trim(substr($attributesStr, $keyStart, $pos - $keyStart));
            if ($key === '') {
                break;
            }

            while ($pos < $length && ctype_space($attributesStr[$pos])) {
                $pos++;
            }
            if ($pos >= $length || $attributesStr[$pos] !== '=') {
                $result[$key] = true;
                continue;
            }
            $pos++;
            while ($pos < $length && ctype_space($attributesStr[$pos])) {
                $pos++;
            }
            if ($pos >= $length) {
                $result[$key] = '';
                break;
            }

            [$rawValue, $pos] = $this->readAttributeValue($attributesStr, $pos);
            $result[$key] = $this->parseAttributeValue($rawValue);
        }

        return $result;
    }

    /**
     * @return array{0:string,1:int}
     */
    private function readAttributeValue(string $attributesStr, int $pos): array
    {
        $length = strlen($attributesStr);
        $char = $attributesStr[$pos];

        if ($char === '"' || $char === "'") {
            $quote = $char;
            $pos++;
            $start = $pos;
            $escaped = false;
            while ($pos < $length) {
                $current = $attributesStr[$pos];
                if ($escaped) {
                    $escaped = false;
                    $pos++;
                    continue;
                }
                if ($current === '\\') {
                    $escaped = true;
                    $pos++;
                    continue;
                }
                if ($current === $quote) {
                    $value = substr($attributesStr, $start, $pos - $start);
                    return [stripslashes($value), $pos + 1];
                }
                $pos++;
            }
            return [substr($attributesStr, $start), $length];
        }

        if ($char === '{' || $char === '[') {
            $closeChar = $char === '{' ? '}' : ']';
            $block = $this->readBalancedBlock($attributesStr, $pos, $char, $closeChar);
            if ($block === null) {
                return [substr($attributesStr, $pos), $length];
            }
            return [$char . $block . $closeChar, $pos + strlen($block) + 2];
        }

        $start = $pos;
        while ($pos < $length && $attributesStr[$pos] !== ',' && !ctype_space($attributesStr[$pos])) {
            $pos++;
        }

        return [substr($attributesStr, $start, $pos - $start), $pos];
    }

    private function parseAttributeValue(string $value): mixed
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        if ((str_starts_with($value, '[') && str_ends_with($value, ']'))
            || (str_starts_with($value, '{') && str_ends_with($value, '}'))
        ) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            return $this->parseLooseObjectOptions($value);
        }

        return $value;
    }

    /**
     * Parse loose option maps like {left:"Left", right:"Right"}.
     *
     * @return array<string, mixed>
     */
    private function parseLooseObjectOptions(string $value): array
    {
        $inner = trim($value, "{} \t\n\r\0\x0B");
        if ($inner === '') {
            return [];
        }

        $result = [];
        foreach ($this->splitTopLevelCsv($inner) as $part) {
            $separator = strpos($part, ':');
            if ($separator === false) {
                continue;
            }
            $key = trim(substr($part, 0, $separator), " \t\n\r\0\x0B'\"");
            $label = trim(substr($part, $separator + 1), " \t\n\r\0\x0B'\"");
            if ($key !== '') {
                $result[$key] = stripcslashes($label !== '' ? $label : $key);
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelCsv(string $value): array
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $escaped = false;
        $depth = 0;
        $length = strlen($value);

        for ($pos = 0; $pos < $length; $pos++) {
            $char = $value[$pos];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $depth++;
                continue;
            }
            if ($char === '}' || $char === ']') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($value, $start, $pos - $start));
                $start = $pos + 1;
            }
        }

        $parts[] = trim(substr($value, $start));
        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
