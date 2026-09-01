<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;

/**
 * 扫描支付客户指南 guide/policy phtml 的 i18n 契约（&lt;lang&gt; / @lang，禁止正文 __()）。
 */
class PaymentGuideTemplateScanner
{
    public const VIOLATION_TEMPLATE_MISSING = 'template_missing';
    public const VIOLATION_NO_LANG_TAGS = 'no_lang_tags';
    public const VIOLATION_PHP_TRANSLATE_FORBIDDEN = 'php_translate_forbidden';
    public const VIOLATION_UNWRAPPED_VISIBLE_TEXT = 'unwrapped_visible_text';

    /**
     * @var list<string>
     */
    private const VISIBLE_TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'strong', 'a', 'span', 'td', 'th'];

    public function __construct(
        private readonly PaymentCustomerGuideRegistry $guideRegistry,
    ) {
    }

    /**
     * @return list<array{
     *     type:string,
     *     method_code:string,
     *     source_module:string,
     *     template:string,
     *     path:string,
     *     line?:int,
     *     snippet?:string,
     *     message:string
     * }>
     */
    public function scanAll(?string $methodCode = null): array
    {
        $methodCode = $methodCode !== null ? strtolower(trim($methodCode)) : '';
        $violations = [];

        foreach ($this->guideRegistry->listPublishedEntries() as $entry) {
            $code = strtolower(trim((string) ($entry['method_code'] ?? '')));
            if ($code === '' || ($methodCode !== '' && $code !== $methodCode)) {
                continue;
            }

            $sourceModule = (string) ($entry['source_module'] ?? PaymentGuideI18nCatalog::HUB_MODULE);
            foreach (['guide_template', 'policy_template'] as $templateKey) {
                $templateRef = (string) ($entry[$templateKey] ?? '');
                if ($templateRef === '') {
                    continue;
                }

                $violations = array_merge(
                    $violations,
                    $this->scanTemplateReference($code, $sourceModule, $templateRef),
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<array{
     *     type:string,
     *     method_code:string,
     *     source_module:string,
     *     template:string,
     *     path:string,
     *     line?:int,
     *     snippet?:string,
     *     message:string
     * }>
     */
    public function scanTemplateReference(string $methodCode, string $sourceModule, string $templateRef): array
    {
        $absolutePath = $this->resolveTemplatePath($templateRef);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return [[
                'type' => self::VIOLATION_TEMPLATE_MISSING,
                'method_code' => $methodCode,
                'source_module' => $sourceModule,
                'template' => $templateRef,
                'path' => $absolutePath,
                'message' => (string) __(
                    '支付指南模板不存在：%{1}（%{2}）',
                    [$templateRef, $sourceModule],
                ),
            ]];
        }

        $content = (string) file_get_contents($absolutePath);
        $relativePath = $this->toDisplayPath($absolutePath);

        return $this->scanTemplateContent($methodCode, $sourceModule, $templateRef, $relativePath, $content);
    }

    /**
     * @return list<array{
     *     type:string,
     *     method_code:string,
     *     source_module:string,
     *     template:string,
     *     path:string,
     *     line?:int,
     *     snippet?:string,
     *     message:string
     * }>
     */
    public function scanTemplateContent(
        string $methodCode,
        string $sourceModule,
        string $templateRef,
        string $displayPath,
        string $content,
    ): array {
        $violations = [];

        if (!preg_match('/<lang\b|@lang\s*\(|@lang\{/i', $content)) {
            $violations[] = [
                'type' => self::VIOLATION_NO_LANG_TAGS,
                'method_code' => $methodCode,
                'source_module' => $sourceModule,
                'template' => $templateRef,
                'path' => $displayPath,
                'message' => (string) __(
                    '支付指南模板缺少 &lt;lang&gt; / @lang()：%{1}',
                    [$displayPath],
                ),
            ];
        }

        if (preg_match('/<\?=\s*__\s*\(|<\?php\s+echo\s+__\s*\(/i', $content)) {
            $violations[] = [
                'type' => self::VIOLATION_PHP_TRANSLATE_FORBIDDEN,
                'method_code' => $methodCode,
                'source_module' => $sourceModule,
                'template' => $templateRef,
                'path' => $displayPath,
                'message' => (string) __(
                    '支付指南模板禁止使用 &lt;?= __() ?&gt; 输出正文：%{1}',
                    [$displayPath],
                ),
            ];
        }

        foreach ($this->findUnwrappedVisibleTextViolations($content) as $line => $snippet) {
            $violations[] = [
                'type' => self::VIOLATION_UNWRAPPED_VISIBLE_TEXT,
                'method_code' => $methodCode,
                'source_module' => $sourceModule,
                'template' => $templateRef,
                'path' => $displayPath,
                'line' => $line,
                'snippet' => $snippet,
                'message' => (string) __(
                    '支付指南模板存在未包裹 &lt;lang&gt; 的可见文案（第 %{1} 行）：%{2}',
                    [(string) $line, $snippet],
                ),
            ];
        }

        return $violations;
    }

    public function resolveTemplatePath(string $templateRef): string
    {
        $templateRef = trim($templateRef);
        if ($templateRef === '') {
            return '';
        }

        if (!preg_match('/^([^:]+)::templates\/(.+\.phtml)$/i', $templateRef, $matches)) {
            return '';
        }

        $moduleName = trim($matches[1]);
        $relativeTemplate = 'view/templates/' . str_replace('/', DS, $matches[2]);
        $modulePath = $this->resolveModulePath($moduleName);
        if ($modulePath === '') {
            return '';
        }

        return rtrim($modulePath, '/\\') . DS . $relativeTemplate;
    }

    /**
     * @return array<int, string> line => snippet
     */
    private function findUnwrappedVisibleTextViolations(string $content): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $violations = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $strippedLine = (string) (preg_replace('/<\?(?:=|php)[\s\S]*?\?>/', '', $line) ?? $line);
            $trimmed = trim($strippedLine);
            if ($trimmed === '') {
                continue;
            }
            if (str_contains($trimmed, '<lang') || str_contains($trimmed, '@lang(')) {
                continue;
            }

            foreach (self::VISIBLE_TEXT_TAGS as $tag) {
                $pattern = '/<' . $tag . '\b[^>]*>([^<]+)</iu';
                if (!preg_match($pattern, $trimmed, $match)) {
                    continue;
                }

                $text = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '' || $this->isAllowedBareText($text)) {
                    continue;
                }

                $violations[$lineNumber] = mb_substr($text, 0, 80);
            }
        }

        return $violations;
    }

    private function isAllowedBareText(string $text): bool
    {
        if (preg_match('/^[\d\s\W]+$/u', $text)) {
            return true;
        }

        if (preg_match('/^(PayPal|Visa|Mastercard|AMEX|CNY|USD|EUR|¥|\$|€)$/iu', $text)) {
            return true;
        }

        if (preg_match('/^[\x{4e00}-\x{9fff}]{1,4}$/u', $text)) {
            return false;
        }

        if (preg_match('/[A-Za-z]{2,}/', $text) && !preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return false;
        }

        if (preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $text)) {
            return false;
        }

        return true;
    }

    private function resolveModulePath(string $moduleName): string
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return '';
        }

        $modules = Env::getInstance()->getModuleList();
        $module = $modules[$moduleName] ?? null;
        if (!is_array($module)) {
            return '';
        }

        return rtrim((string) ($module['base_path'] ?? ''), '/\\');
    }

    private function toDisplayPath(string $absolutePath): string
    {
        $root = rtrim((string) (defined('BP') ? BP : ''), '/\\');
        if ($root !== '' && str_starts_with($absolutePath, $root)) {
            return ltrim(substr($absolutePath, strlen($root)), '/\\');
        }

        return $absolutePath;
    }
}
