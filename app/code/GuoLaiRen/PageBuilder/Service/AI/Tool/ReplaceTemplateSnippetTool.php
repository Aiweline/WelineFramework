<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;

class ReplaceTemplateSnippetTool implements ToolInterface
{
    public function getName(): string
    {
        return 'replace_template_snippet';
    }

    public function getDescription(): string
    {
        return 'Replace a specific line range in a template file. Use after locate_template_error to apply a precise fix.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Absolute file path to edit.',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => 'Start line number (1-based, inclusive).',
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => 'End line number (1-based, inclusive).',
                ],
                'replacement' => [
                    'type' => 'string',
                    'description' => 'Replacement content for the line range.',
                ],
            ],
            'required' => ['file_path', 'start_line', 'end_line', 'replacement'],
        ];
    }

    public function execute(array $args): mixed
    {
        $file = (string)($args['file_path'] ?? '');
        $start = (int)($args['start_line'] ?? 0);
        $end = (int)($args['end_line'] ?? 0);
        $replacement = (string)($args['replacement'] ?? '');

        if ($file === '' || $start <= 0 || $end <= 0 || $start > $end) {
            return [
                'success' => false,
                'message' => 'Invalid parameters.',
            ];
        }

        $real = realpath($file);
        if (!$real || !is_file($real)) {
            return [
                'success' => false,
                'message' => 'File not found.',
                'file' => $file,
            ];
        }

        if (defined('BP')) {
            $bp = rtrim((string)BP, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (strpos($real, $bp) !== 0) {
                return [
                    'success' => false,
                    'message' => 'File outside project root.',
                    'file' => $real,
                ];
            }
        }

        $lines = @file($real, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [
                'success' => false,
                'message' => 'Failed to read file.',
                'file' => $real,
            ];
        }

        $total = count($lines);
        if ($start > $total || $end > $total) {
            return [
                'success' => false,
                'message' => 'Line range out of bounds.',
                'total_lines' => $total,
            ];
        }

        $replacementLines = $replacement === '' ? [''] : preg_split('/\\r\\n|\\n|\\r/', $replacement);
        if ($replacementLines === false) {
            $replacementLines = [$replacement];
        }

        $before = array_slice($lines, 0, $start - 1);
        $after = array_slice($lines, $end);
        $newLines = array_merge($before, $replacementLines, $after);

        $content = implode(PHP_EOL, $newLines);
        if (file_put_contents($real, $content) === false) {
            return [
                'success' => false,
                'message' => 'Failed to write file.',
                'file' => $real,
            ];
        }

        return [
            'success' => true,
            'file' => $real,
            'start_line' => $start,
            'end_line' => $end,
            'new_total_lines' => count($newLines),
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
