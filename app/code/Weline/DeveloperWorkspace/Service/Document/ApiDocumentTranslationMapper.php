<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Document;

use Weline\DeveloperWorkspace\Model\Document\Translation;

class ApiDocumentTranslationMapper
{
    public function apply(array $api, array $view): array
    {
        $api['document'] = is_array($api['document'] ?? null) ? $api['document'] : [];
        $api['document']['summary'] = (string)($view['title'] ?? ($api['document']['summary'] ?? ''));
        $api['document']['description'] = (string)($view['summary'] ?? ($api['document']['description'] ?? ''));
        $api['translation_status'] = (string)($view['translation_status'] ?? Translation::STATUS_MISSING);
        $api['translation_locale'] = (string)($view['locale'] ?? '');
        $api['translation_source_locale'] = (string)($view['source_locale'] ?? '');
        $api['is_translated'] = !empty($view['is_translated']);

        $content = (string)($view['content'] ?? '');
        if ($content === '' || empty($api['is_translated'])) {
            return $api;
        }

        $parameterDescriptions = $this->extractParameterDescriptions($content);
        if ($parameterDescriptions !== [] && !empty($api['parameters']) && is_array($api['parameters'])) {
            foreach ($api['parameters'] as &$parameter) {
                if (!is_array($parameter)) {
                    continue;
                }
                $name = (string)($parameter['name'] ?? '');
                if ($name !== '' && isset($parameterDescriptions[$name])) {
                    $parameter['description'] = $parameterDescriptions[$name];
                    if ($this->isSourceTextDefault($parameter['default'] ?? null)) {
                        unset($parameter['default']);
                    }
                }
            }
            unset($parameter);
        }

        $responseDescriptions = $this->extractResponseDescriptions($content);
        if ($responseDescriptions !== [] && !empty($api['responses']) && is_array($api['responses'])) {
            foreach ($api['responses'] as $code => &$response) {
                if (!is_array($response)) {
                    continue;
                }
                $code = (string)$code;
                if (isset($responseDescriptions[$code])) {
                    $response['description'] = $responseDescriptions[$code];
                }
            }
            unset($response);
        }

        return $api;
    }

    private function extractParameterDescriptions(string $markdown): array
    {
        $descriptions = [];
        foreach (preg_split("/\r\n|\n|\r/", $markdown) ?: [] as $line) {
            $cells = $this->splitMarkdownTableRow((string)$line);
            if (count($cells) < 4 || !preg_match('/`([^`]+)`/', $cells[0], $matches)) {
                continue;
            }

            $name = trim((string)($matches[1] ?? ''));
            $description = trim($cells[3]);
            if ($name === '' || $description === '' || $this->isTableHeader($description)) {
                continue;
            }

            $descriptions[$name] = $description;
        }

        return $descriptions;
    }

    private function extractResponseDescriptions(string $markdown): array
    {
        $descriptions = [];
        $currentCode = '';
        $buffer = [];

        foreach (preg_split("/\r\n|\n|\r/", $markdown) ?: [] as $line) {
            $line = rtrim((string)$line);
            if (preg_match('/^###\s+([0-9A-Za-z_-]+)\s*$/', $line, $matches)) {
                $this->commitResponseDescription($descriptions, $currentCode, $buffer);
                $currentCode = (string)($matches[1] ?? '');
                $buffer = [];
                continue;
            }

            if ($currentCode !== '' && preg_match('/^#{1,2}\s+/', $line)) {
                $this->commitResponseDescription($descriptions, $currentCode, $buffer);
                $currentCode = '';
                $buffer = [];
                continue;
            }

            if ($currentCode !== '') {
                $buffer[] = $line;
            }
        }
        $this->commitResponseDescription($descriptions, $currentCode, $buffer);

        return $descriptions;
    }

    private function commitResponseDescription(array &$descriptions, string $code, array $buffer): void
    {
        if ($code === '') {
            return;
        }

        $text = trim(implode("\n", array_filter($buffer, static fn(string $line): bool => trim($line) !== '')));
        if ($text !== '') {
            $descriptions[$code] = $text;
        }
    }

    private function splitMarkdownTableRow(string $line): array
    {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '|')) {
            return [];
        }

        $cells = preg_split('/(?<!\\\\)\|/', trim($line, '|'));
        if ($cells === false) {
            return [];
        }

        return array_map(
            static fn(string $cell): string => trim(str_replace('\|', '|', $cell)),
            $cells
        );
    }

    private function isSourceTextDefault(mixed $default): bool
    {
        if (!is_string($default)) {
            return false;
        }

        $default = trim($default);
        if ($default === '') {
            return false;
        }

        return (bool)preg_match('/\p{Han}/u', $default);
    }

    private function isTableHeader(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['说明', 'description'], true);
    }
}
