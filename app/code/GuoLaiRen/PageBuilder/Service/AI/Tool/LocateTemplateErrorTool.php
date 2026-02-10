<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;

class LocateTemplateErrorTool implements ToolInterface
{
    public function getName(): string
    {
        return 'locate_template_error';
    }

    public function getDescription(): string
    {
        return 'Locate template error position from an error message. Returns file path, line number, and surrounding context. If error points to compiled tpl, tries to map to source templates.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'error_message' => [
                    'type' => 'string',
                    'description' => 'Full error message containing file path and line number.',
                ],
                'context_lines' => [
                    'type' => 'integer',
                    'description' => 'How many lines of context to return around the error line.',
                    'default' => 6,
                ],
            ],
            'required' => ['error_message'],
        ];
    }

    public function execute(array $args): mixed
    {
        $error = (string)($args['error_message'] ?? '');
        $contextLines = (int)($args['context_lines'] ?? 6);
        if ($contextLines < 1) {
            $contextLines = 6;
        }

        if ($error === '') {
            return [
                'success' => false,
                'message' => 'Empty error message.',
            ];
        }

        $file = '';
        $line = 0;
        if (preg_match('/in\s+([A-Za-z]:\\\\[^\\s]+)\s+on line\s+(\\d+)/i', $error, $m)) {
            $file = $m[1];
            $line = (int)$m[2];
        } elseif (preg_match('/in\s+([A-Za-z]:\/[^\\s]+)\s+on line\s+(\\d+)/i', $error, $m)) {
            $file = $m[1];
            $line = (int)$m[2];
        }

        if ($file === '' || $line <= 0) {
            return [
                'success' => false,
                'message' => 'Cannot parse file path or line number from error message.',
            ];
        }

        $mapped = $this->mapToSourceTemplate($file);
        $target = $mapped['source_file'] ?? $file;
        $usedCompiled = $mapped['used_compiled'] ?? false;

        $context = $this->readContext($target, $line, $contextLines);
        if (!$context['exists']) {
            return [
                'success' => false,
                'message' => 'File does not exist.',
                'file' => $target,
                'line' => $line,
                'source_map' => $mapped,
            ];
        }

        return [
            'success' => true,
            'file' => $target,
            'line' => $line,
            'used_compiled' => $usedCompiled,
            'source_map' => $mapped,
            'context' => $context,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    private function mapToSourceTemplate(string $file): array
    {
        $normalized = str_replace('/', DIRECTORY_SEPARATOR, $file);
        $result = [
            'original_file' => $file,
            'source_file' => $file,
            'used_compiled' => false,
        ];

        if (strpos($normalized, DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'tpl' . DIRECTORY_SEPARATOR) === false) {
            return $result;
        }

        $result['used_compiled'] = true;

        $source = str_replace(
            DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'tpl' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR,
            $normalized
        );

        $base = basename($source);
        if (preg_match('/^com_ai-(\d+)\.phtml$/', $base, $m)) {
            $source = str_replace($base, 'ai-' . $m[1] . '.phtml', $source);
        } elseif (strpos($base, 'com_') === 0) {
            $source = str_replace($base, substr($base, 4), $source);
        }

        $result['source_file'] = $source;
        return $result;
    }

    private function readContext(string $file, int $line, int $contextLines): array
    {
        if (!is_file($file)) {
            return ['exists' => false];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return ['exists' => false];
        }
        $total = count($lines);
        $start = max(1, $line - $contextLines);
        $end = min($total, $line + $contextLines);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $snippet[] = $i . '|' . $lines[$i - 1];
        }

        return [
            'exists' => true,
            'start_line' => $start,
            'end_line' => $end,
            'total_lines' => $total,
            'snippet' => $snippet,
        ];
    }
}
