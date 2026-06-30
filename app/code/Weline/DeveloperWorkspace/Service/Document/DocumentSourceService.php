<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Document;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\App\Env;

class DocumentSourceService
{
    public function getDocumentContent(Document $document): string
    {
        $moduleName = (string)$document->getModuleName();
        if (str_starts_with($moduleName, 'API_')) {
            return $this->cleanHtmlComments(htmlspecialchars_decode((string)$document->getContent()));
        }

        if ($document->isAutoImported()) {
            $content = $this->loadDocumentFromFile($moduleName, (string)$document->getFilePath());
            if ($content !== null) {
                return $content;
            }
        }

        return $this->cleanHtmlComments(htmlspecialchars_decode((string)$document->getContent()));
    }

    public function getDocumentSourceHash(Document $document): string
    {
        $payload = [
            'title' => (string)$document->getTitle(),
            'summary' => (string)$document->getData(Document::schema_fields_summary),
            'content' => $this->getDocumentContent($document),
            'file_mtime' => (string)$document->getData(Document::schema_fields_FILE_MTIME),
            'updated_at' => (string)$document->getData(Document::schema_fields_UPDATED_AT),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getCatalogSourceHash(Catalog $catalog): string
    {
        return hash('sha256', json_encode([
            'name' => (string)$catalog->getName(),
            'description' => (string)$catalog->getDescription(),
            'pid' => (string)$catalog->getPid(),
            'level' => (string)$catalog->getData(Catalog::schema_fields_level),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function estimateTokens(string $text): int
    {
        $chinese = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text);
        $ascii = preg_match_all('/[a-zA-Z0-9_]/', $text);
        $other = max(0, strlen($text) - (int)$chinese - (int)$ascii);

        return ((int)$chinese * 2) + (int)$ascii + $other;
    }

    private function loadDocumentFromFile(string $moduleName, string $relativePath): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules[$moduleName])) {
            return null;
        }

        $moduleBasePath = rtrim((string)($modules[$moduleName]['base_path'] ?? ''), '/\\');
        if ($moduleBasePath === '' || $relativePath === '') {
            return null;
        }

        $fullPath = $moduleBasePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $realModulePath = realpath($moduleBasePath);
        $realFullPath = realpath($fullPath);
        if ($realModulePath === false || $realFullPath === false || !str_starts_with($realFullPath, $realModulePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($realFullPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['md', 'markdown', 'txt'], true) || !is_file($realFullPath) || !is_readable($realFullPath)) {
            return null;
        }

        $content = file_get_contents($realFullPath);
        if ($content === false || $content === '') {
            return '';
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1', 'Windows-1252'], true) ?: 'GBK';
            $converted = mb_convert_encoding($content, 'UTF-8', (string)$encoding);
            if ($converted !== false) {
                $content = $converted;
            }
        }

        return $this->cleanHtmlComments($content);
    }

    private function cleanHtmlComments(string $content): string
    {
        $cleaned = preg_replace('/<!--[\s\S]*?-->/', '', $content);
        $cleaned = $cleaned === null ? $content : $cleaned;
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
        return trim($cleaned ?? '');
    }
}
