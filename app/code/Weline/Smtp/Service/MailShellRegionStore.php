<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * 邮件壳页头/页尾可视化编辑持久化（按 storage_scope 继承）。
 * 正文仍走 weline_smtp_mail_template.body_html；此处只存 header 内层与 footer 各行 innerHTML。
 */
final class MailShellRegionStore
{
    public const CONFIG_KEY = 'smtp_mail_shell_regions';

    public function __construct(
        private readonly ConfigReader $reader,
        private readonly ConfigStore $store,
    ) {
    }

    /**
     * @return array{header:string,footer:list<string>}
     */
    public function get(string $storageScope): array
    {
        $storageScope = trim($storageScope);
        if ($storageScope === '') {
            return ['header' => '', 'footer' => []];
        }
        try {
            $raw = $this->reader->getConfig(
                self::CONFIG_KEY,
                'Weline_Smtp',
                ConfigReader::area_BACKEND,
                null,
                $storageScope,
                ConfigReader::LOCALE_DEFAULT,
            );
        } catch (\Throwable) {
            return ['header' => '', 'footer' => []];
        }
        if ($raw === null || $raw === '') {
            return ['header' => '', 'footer' => []];
        }
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return ['header' => '', 'footer' => []];
        }
        $header = trim((string)($decoded['header'] ?? ''));
        $footer = [];
        foreach (($decoded['footer'] ?? []) as $row) {
            if (!is_string($row)) {
                continue;
            }
            $footer[] = trim($row);
        }

        return ['header' => $header, 'footer' => $footer];
    }

    /**
     * 清空当前 storage_scope 的壳区覆盖（header="" / footer=[]），预览回落 shell.phtml。
     */
    public function clear(string $storageScope): void
    {
        $this->save($storageScope, '', []);
    }

    /**
     * @param list<string> $footerRows
     */
    public function save(string $storageScope, string $header, array $footerRows): void
    {
        $storageScope = trim($storageScope);
        if ($storageScope === '') {
            return;
        }
        $header = trim($header);
        $footerRows = array_values(array_map(static fn($v) => trim((string)$v), $footerRows));
        $hasFooter = false;
        foreach ($footerRows as $row) {
            if ($row !== '') {
                $hasFooter = true;
                break;
            }
        }
        $payload = '';
        if ($header !== '' || $hasFooter) {
            $payload = json_encode(
                ['header' => $header, 'footer' => $footerRows],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
        $this->persistPayload($storageScope, $payload, 'smtp_mail_shell_regions_save');
    }

    private function persistPayload(string $storageScope, string $payload, string $reason): void
    {
        $this->store->setScopedConfig(
            self::CONFIG_KEY,
            $payload,
            'Weline_Smtp',
            ConfigReader::area_BACKEND,
            $storageScope,
            ConfigReader::LOCALE_DEFAULT,
            [
                'value_type' => 'json',
                'reason' => $reason,
            ]
        );
    }

    public function applyToShell(string $shellHtml, string $storageScope): string
    {
        return $this->applyRegions($shellHtml, $this->get($storageScope));
    }

    /**
     * @param array{header?:string,footer?:list<string>} $regions
     */
    public function applyRegions(string $shellHtml, array $regions): string
    {
        return self::mergeRegionHtml($shellHtml, $regions);
    }

    /**
     * @param array{header?:string,footer?:list<string>} $regions
     */
    public static function mergeRegionHtml(string $shellHtml, array $regions): string
    {
        $header = trim((string)($regions['header'] ?? ''));
        $footerRows = is_array($regions['footer'] ?? null) ? $regions['footer'] : [];
        if ($header === '' && $footerRows === []) {
            return $shellHtml;
        }

        if ($header !== '') {
            $shellHtml = self::replaceRegionInner($shellHtml, 'header', $header, 0);
        }
        if ($footerRows !== []) {
            $footerSlots = preg_match_all('/\bdata-weline-mail-region="footer"/i', $shellHtml) ?: 0;
            $nonEmpty = [];
            foreach ($footerRows as $row) {
                $row = trim((string)$row);
                if ($row !== '') {
                    $nonEmpty[] = $row;
                }
            }
            // 壳已合并为单页尾格：历史多行 override 拼进同一格，避免第二段丢失
            if ($footerSlots === 1 && $nonEmpty !== []) {
                $shellHtml = self::replaceRegionInner($shellHtml, 'footer', implode("\n", $nonEmpty), 0);
            } else {
                $index = 0;
                foreach ($footerRows as $row) {
                    $row = trim((string)$row);
                    if ($row === '') {
                        $index++;
                        continue;
                    }
                    $shellHtml = self::replaceRegionInner($shellHtml, 'footer', $row, $index);
                    $index++;
                }
            }
        }

        return $shellHtml;
    }

    private static function replaceRegionInner(string $html, string $region, string $inner, int $occurrence): string
    {
        // 页头内嵌套 <table><td>…</td></table>：非贪婪首个 </td> 会提前截断，
        // 把右栏文案 TD 挤成壳行兄弟单元格（可视化竖条重影）。须按 TD 深度配对闭合。
        $openPattern = '/<td\b[^>]*\bdata-weline-mail-region="' . preg_quote($region, '/') . '"[^>]*>/i';
        if (!preg_match_all($openPattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        if (!isset($matches[0][$occurrence])) {
            return $html;
        }
        $openTag = $matches[0][$occurrence][0];
        $openPos = (int)$matches[0][$occurrence][1];
        $contentStart = $openPos + strlen($openTag);
        $closePos = self::findMatchingTdClose($html, $contentStart);
        if ($closePos < 0) {
            return $html;
        }

        return substr($html, 0, $contentStart) . $inner . substr($html, $closePos);
    }

    /**
     * 从区域 TD 内容起点起，找到与之配对的 </td> 起始下标（深度归零处）。
     */
    private static function findMatchingTdClose(string $html, int $contentStart): int
    {
        $len = strlen($html);
        $depth = 1;
        $pos = $contentStart;
        while ($pos < $len && $depth > 0) {
            $nextOpen = self::findNextTdOpen($html, $pos);
            $nextClose = stripos($html, '</td>', $pos);
            if ($nextClose === false) {
                return -1;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 3;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $nextClose;
            }
            $pos = $nextClose + 5;
        }

        return -1;
    }

    /**
     * @return int|false 下一真实 <td …> 开标签起点
     */
    private static function findNextTdOpen(string $html, int $from): int|false
    {
        $pos = $from;
        $len = strlen($html);
        while ($pos < $len) {
            $found = stripos($html, '<td', $pos);
            if ($found === false) {
                return false;
            }
            $after = $html[$found + 3] ?? '';
            if ($after === '>' || ctype_space($after)) {
                return $found;
            }
            $pos = $found + 3;
        }

        return false;
    }
}
