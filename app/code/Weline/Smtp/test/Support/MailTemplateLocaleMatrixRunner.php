<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Support;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Model\SmtpMailTemplate;
use Weline\Smtp\Model\SmtpSendLog;
use Weline\Smtp\Service\MailBrandContextService;
use Weline\Smtp\Service\MailChannelCollector;
use Weline\Smtp\Service\MailTemplateRenderer;
use Weline\Smtp\Service\MailTemplateResolver;
use Weline\Smtp\Service\MailTemplateSeedLocaleResolver;
use Weline\Smtp\Service\MailTemplateSendContext;
use Weline\Smtp\Service\MailTemplateShellComposer;
use Weline\Websites\Model\Website;

/**
 * 穷尽矩阵：默认站 locale × Smtp channel 渲染断言（不真发 SMTP）。
 *
 * 断言：
 * - UC-1：非中文目标语 resolved_locale ≠ zh_*（须为本语种或 en_US）
 * - 非中文：可见主题+正文（去注释）无 CJK（白名单：长安汉服）
 * - 非 en_*：不得整页残留该 channel 的 en_US 种子特征串（en 占位 ≠ 真译）
 * - 非 zh / 非 en_*：可见 subject+wrapped content 禁止壳层英标（Phone:/Hours:/Address: 等）
 */
final class MailTemplateLocaleMatrixRunner
{
    public const CJK_WHITELIST = ['长安汉服'];

    /** 跨渠道常见英文欢迎语（辅）；主检测仍按 channel 的 en_US 种子抽取 */
    public const GLOBAL_EN_MARKERS = [
        'Welcome gift',
        'Thanks for subscribing',
        'Thanks for joining',
        'Your coupon code',
        'Shop now',
        'mailing list',
        'You can unsubscribe anytime',
        'Password reset',
        'Reset your password',
        'Order created',
        'Order confirmation',
        'We received your order',
        'Need help?',
    ];

    /**
     * 壳层 / 品牌营业时间英标（plan test-shell-en-gate）。
     * 用正则，要求冒号/问号形态，避免误杀 brand URL 路径中的 phone/hours 段。
     *
     * @var list<array{id:string,pattern:string}>
     */
    public const SHELL_EN_LABEL_PATTERNS = [
        ['id' => 'Phone:', 'pattern' => '/\bPhone\s*:/iu'],
        ['id' => 'Hours:', 'pattern' => '/\bHours\s*:/iu'],
        ['id' => 'Address:', 'pattern' => '/\bAddress\s*:/iu'],
        ['id' => 'Need help?', 'pattern' => '/Need\s+help\s*\?/iu'],
        ['id' => 'Monday to Friday', 'pattern' => '/Monday\s+to\s+Friday/iu'],
        ['id' => 'Monday-Friday', 'pattern' => '/Monday\s*[-–—]\s*Friday/iu'],
        ['id' => 'Mon-Fri', 'pattern' => '/\bMon(?:day)?\s*[-–—]\s*Fri(?:day)?\b/iu'],
        ['id' => 'Mon to Fri', 'pattern' => '/\bMon\s+to\s+Fri\b/iu'],
    ];

    /**
     * @param array{
     *   channel?:string,
     *   locale?:string,
     *   persist-preview?:bool,
     *   preview-locales?:string,
     *   preview-channels?:string,
     *   preview-channel?:string,
     *   fail-fast?:bool,
     *   quiet?:bool
     * } $opts
     * @return array<string, mixed>
     */
    public static function run(array $opts = []): array
    {
        /** @var MailChannelCollector $collector */
        $collector = ObjectManager::getInstance(MailChannelCollector::class);
        /** @var MailTemplateResolver $resolver */
        $resolver = ObjectManager::getInstance(MailTemplateResolver::class);
        /** @var MailTemplateRenderer $renderer */
        $renderer = ObjectManager::getInstance(MailTemplateRenderer::class);
        /** @var MailTemplateShellComposer $shell */
        $shell = ObjectManager::getInstance(MailTemplateShellComposer::class);
        /** @var MailBrandContextService $brand */
        $brand = ObjectManager::getInstance(MailBrandContextService::class);
        /** @var MailTemplateSendContext $sendCtx */
        $sendCtx = ObjectManager::getInstance(MailTemplateSendContext::class);

        $channels = $collector->collect();
        $channelFilter = trim((string)($opts['channel'] ?? ''));
        if ($channelFilter !== '') {
            $channels = array_values(array_filter(
                $channels,
                static fn(array $ch): bool => (string)($ch['code'] ?? '') === $channelFilter
            ));
        }
        if ($channels === []) {
            throw new \RuntimeException('No channels to matrix');
        }

        $locales = MailTemplateSeedLocaleResolver::forDefaultWebsite();
        $localeFilter = trim((string)($opts['locale'] ?? ''));
        if ($localeFilter !== '') {
            $locales = array_values(array_filter(
                $locales,
                static fn(string $loc): bool => $loc === $localeFilter
            ));
        }
        if ($locales === []) {
            throw new \RuntimeException('No locales to matrix');
        }

        $websiteCode = 'default';
        $websiteDefaultLang = '';
        try {
            $website = ObjectManager::getInstance(Website::class)->load(Website::ID_DEFAULT);
            if ($website && $website->getId() !== null && $website->getId() !== '') {
                $websiteCode = trim((string)($website->getCode() ?: 'default')) ?: 'default';
                $websiteDefaultLang = trim((string)($website->getDefaultLanguage() ?? ''));
            }
        } catch (\Throwable) {
            // PHPUnit SQLite 等无 websites 表时退回 default；CLI 全矩阵仍走真实站。
        }

        $ctxProbe = $sendCtx->resolve([
            'website_code' => $websiteCode,
            'locale' => 'en_US',
        ]);
        $storageScope = (string)($ctxProbe['storage_scope'] ?? 'default.default.default');

        $failFast = !empty($opts['fail-fast']);
        $quiet = !empty($opts['quiet']);
        $failures = [];
        $cells = [];
        $pass = 0;
        $fail = 0;
        $skip = 0;
        $enPlaceholderFails = 0;
        $shellEnFails = 0;
        $cjkFails = 0;
        $uc1Fails = 0;

        /** @var array<string, string> $shellCache */
        $shellCache = [];
        /** @var array<string, array<string, mixed>> $brandCache */
        $brandCache = [];
        /** @var array<string, list<string>> $enMarkersByChannel */
        $enMarkersByChannel = [];

        $planned = count($locales) * count($channels);
        $done = 0;
        self::progress($quiet, "matrix start locales=" . count($locales) . " channels=" . count($channels) . " cells={$planned}");

        foreach ($locales as $locale) {
            $ctx = $sendCtx->resolve([
                'website_code' => $websiteCode,
                'locale' => $locale,
            ]);
            $scope = (string)($ctx['storage_scope'] ?? $storageScope);
            $websiteDefault = trim((string)($ctx['website_default'] ?? ''));
            $websiteDefaultSource = $websiteDefault !== '' ? 'send_context' : 'send_context_empty';

            foreach ($channels as $ch) {
                $channel = (string)($ch['code'] ?? '');
                if ($channel === '') {
                    continue;
                }
                $done++;

                $cell = [
                    'locale' => $locale,
                    'channel' => $channel,
                    'storage_scope' => $scope,
                    'website_default' => $websiteDefault,
                    'website_default_source' => $websiteDefaultSource,
                    'status' => 'pass',
                    'reasons' => [],
                ];

                $hit = $resolver->resolve(
                    $channel,
                    $scope,
                    $locale,
                    $websiteDefault !== '' ? $websiteDefault : null
                );
                if ($hit === null) {
                    $cell['status'] = 'fail';
                    $cell['reasons'][] = 'resolve_null';
                    $fail++;
                    $uc1Fails++;
                    $failures[] = [
                        'locale' => $locale,
                        'channel' => $channel,
                        'reason' => 'resolve_null',
                    ];
                    $cells[] = $cell;
                    self::tick($quiet, $done, $planned, $pass, $fail);
                    if ($failFast) {
                        break 2;
                    }
                    continue;
                }

                $resolvedLocale = (string)($hit['locale'] ?? '');
                $resolvedScope = (string)($hit['storage_scope'] ?? $scope);
                $tpl = $hit['template'];
                $cell['resolved_locale'] = $resolvedLocale;
                $cell['template_id'] = (int)$tpl->getId();
                $cell['resolved_scope'] = $resolvedScope;
                // Snapshot BEFORE buildEnMarkers: SmtpMailTemplate is an ObjectManager
                // singleton; resolving en_US for markers mutates the same instance.
                $subjectTpl = (string)$tpl->getData(SmtpMailTemplate::schema_fields_SUBJECT);
                $bodyTplRaw = (string)$tpl->getData(SmtpMailTemplate::schema_fields_BODY_HTML);

                if (!self::isChineseLocale($locale)) {
                    if (self::isChineseLocale($resolvedLocale)) {
                        $cell['status'] = 'fail';
                        $cell['reasons'][] = 'uc1_resolved_zh:' . $resolvedLocale;
                        $uc1Fails++;
                    } elseif ($resolvedLocale !== $locale && $resolvedLocale !== 'en_US') {
                        $cell['status'] = 'fail';
                        $cell['reasons'][] = 'uc1_unexpected_locale:' . $resolvedLocale;
                        $uc1Fails++;
                    }
                }

                if (!isset($enMarkersByChannel[$channel])) {
                    $enMarkersByChannel[$channel] = self::buildEnMarkersForChannel(
                        $resolver,
                        $shell,
                        $channel,
                        $scope,
                        $websiteDefault
                    );
                }

                $allowed = [];
                foreach ($ch['variables'] ?? [] as $var) {
                    $code = trim((string)($var['code'] ?? ''));
                    if ($code !== '') {
                        $allowed[] = $code;
                    }
                }
                $allowed = array_values(array_unique(array_merge(
                    $allowed,
                    MailBrandContextService::variableCodes()
                )));

                $samples = self::latinSamplesFromChannel($ch, $locale);
                $brandKey = $resolvedLocale . '|' . $resolvedScope;
                if (!isset($brandCache[$brandKey])) {
                    $brandCache[$brandKey] = $brand->mergeInto([], $resolvedScope, $resolvedLocale);
                }
                $vars = array_merge($brandCache[$brandKey], $samples);

                $bodyTpl = $shell->extractBodyFragment($bodyTplRaw);
                $subject = $brand->ensureBrandedSubject($renderer->render($subjectTpl, $vars, $allowed), $vars);
                $bodyHtml = $renderer->render($bodyTpl, $vars, $allowed);

                $shellKey = $resolvedLocale . '|' . $resolvedScope;
                if (!isset($shellCache[$shellKey])) {
                    $shellCache[$shellKey] = $shell->loadShell($resolvedLocale, $resolvedScope);
                }
                $content = str_replace(
                    ['{{MAIL_BODY}}', '{{PREHEADER}}'],
                    [$bodyHtml, htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
                    $shellCache[$shellKey]
                );
                $content = $renderer->render($content, $vars, $allowed);

                $cell['subject'] = $subject;
                $visible = self::visibleText($subject . "\n" . $content);
                $cjkHits = self::findCjk($visible);
                $cell['cjk_hits'] = $cjkHits;
                if (!self::isChineseLocale($locale) && $cjkHits !== []) {
                    $cell['status'] = 'fail';
                    $cell['reasons'][] = 'cjk:' . implode(',', array_slice($cjkHits, 0, 8));
                    $cjkFails++;
                }

                // 非 en_*：禁止仍是 en_US 种子占位（真译门禁）
                if (!self::isEnglishLocale($locale) && !self::isChineseLocale($locale)) {
                    $hitMarkers = self::findEnMarkers($visible, $enMarkersByChannel[$channel]);
                    $cell['en_marker_hits'] = $hitMarkers;
                    if ($hitMarkers !== []) {
                        $cell['status'] = 'fail';
                        $cell['reasons'][] = 'en_placeholder:' . implode('|', array_slice($hitMarkers, 0, 4));
                        $enPlaceholderFails++;
                    }
                }

                // 非 zh / 非 en_*：壳层英标门禁（汇审假绿补洞 · test-shell-en-gate）
                if (!self::isEnglishLocale($locale) && !self::isChineseLocale($locale)) {
                    $shellHits = self::findShellEnLabels($visible);
                    $cell['shell_en_hits'] = $shellHits;
                    if ($shellHits !== []) {
                        $cell['status'] = 'fail';
                        $cell['reasons'][] = 'shell_en_leak:' . implode('|', array_slice($shellHits, 0, 6));
                        $shellEnFails++;
                    }
                }

                if ($cell['status'] === 'fail') {
                    $fail++;
                    $failures[] = [
                        'locale' => $locale,
                        'channel' => $channel,
                        'resolved_locale' => $resolvedLocale,
                        'reason' => implode('; ', $cell['reasons']),
                        'cjk_sample' => array_slice($cjkHits, 0, 12),
                        'en_marker_hits' => $cell['en_marker_hits'] ?? [],
                        'shell_en_hits' => $cell['shell_en_hits'] ?? [],
                    ];
                    if ($failFast) {
                        $cells[] = $cell;
                        break 2;
                    }
                } else {
                    $pass++;
                }
                $cells[] = $cell;
                self::tick($quiet, $done, $planned, $pass, $fail);
            }
        }

        $previewLogs = [];
        if (!empty($opts['persist-preview'])) {
            $previewLocales = self::parseList(
                (string)($opts['preview-locales'] ?? 'de_DE,it_IT,ru_RU,pl_PL,nl_NL')
            );
            $previewChannelsRaw = trim((string)($opts['preview-channels'] ?? $opts['preview-channel'] ?? ''));
            if ($previewChannelsRaw === '') {
                $previewChannelsRaw = 'Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created';
            }
            $previewChannels = self::parseList($previewChannelsRaw);
            $previewLogs = self::persistPreviewLogs(
                $previewChannels,
                $previewLocales,
                $collector,
                $resolver,
                $renderer,
                $shell,
                $brand,
                $sendCtx,
                $websiteCode,
                $brandCache,
                $shellCache
            );
        }

        return [
            'verdict' => $fail === 0 ? 'pass' : 'fail',
            'plan_id' => 'test-shell-en-gate',
            'uc' => ['UC-1', 'UC-2', 'UC-3', 'shell-en-gate'],
            'generated_at' => date('c'),
            'meta' => [
                'locale_count' => count($locales),
                'channel_count' => count($channels),
                'locales' => $locales,
                'channels' => array_map(static fn(array $c): string => (string)$c['code'], $channels),
                'storage_scope' => $storageScope,
                'website_code' => $websiteCode,
                'website_default_language' => $websiteDefaultLang,
                'platform_default_language' => (string)Env::default_LANGUAGE_CODE,
                'cjk_whitelist' => self::CJK_WHITELIST,
                'en_placeholder_gate' => true,
                'shell_en_gate' => true,
                'shell_en_label_ids' => array_map(
                    static fn(array $row): string => (string)$row['id'],
                    self::SHELL_EN_LABEL_PATTERNS
                ),
            ],
            'counts' => [
                'total' => $pass + $fail + $skip,
                'pass' => $pass,
                'fail' => $fail,
                'skip' => $skip,
                'failure_rows' => count($failures),
                'uc1_fails' => $uc1Fails,
                'cjk_fails' => $cjkFails,
                'en_placeholder_fails' => $enPlaceholderFails,
                'shell_en_fails' => $shellEnFails,
            ],
            'failures' => $failures,
            'preview_logs' => $previewLogs,
            'cells' => getenv('MATRIX_KEEP_CELLS') === '1'
                ? $cells
                : array_values(array_filter($cells, static fn(array $c): bool => ($c['status'] ?? '') === 'fail')),
        ];
    }

    /**
     * @param list<string> $previewChannels
     * @param list<string> $previewLocales
     * @param array<string, array<string, mixed>> $brandCache
     * @param array<string, string> $shellCache
     * @return list<array<string, mixed>>
     */
    public static function persistPreviewLogs(
        array $previewChannels,
        array $previewLocales,
        MailChannelCollector $collector,
        MailTemplateResolver $resolver,
        MailTemplateRenderer $renderer,
        MailTemplateShellComposer $shell,
        MailBrandContextService $brand,
        MailTemplateSendContext $sendCtx,
        string $websiteCode,
        array &$brandCache,
        array &$shellCache,
    ): array {
        $env = include BP . 'app/etc/env.php';
        $backendPrefix = (string)($env['router']['area_routes']['backend']['prefix'] ?? '');
        $hostBase = 'https://p05113ef3.test.weline.com:9555';
        $out = [];

        foreach ($previewChannels as $channel) {
            $meta = $collector->getByCode($channel);
            if ($meta === null) {
                $out[] = ['channel' => $channel, 'error' => 'channel_not_found'];
                continue;
            }
            foreach ($previewLocales as $locale) {
                if ($locale === '' || self::isChineseLocale($locale)) {
                    continue;
                }
                $ctx = $sendCtx->resolve([
                    'website_code' => $websiteCode,
                    'locale' => $locale,
                ]);
                $scope = (string)($ctx['storage_scope'] ?? 'default.default.default');
                $websiteDefault = trim((string)($ctx['website_default'] ?? ''));
                $hit = $resolver->resolve(
                    $channel,
                    $scope,
                    $locale,
                    $websiteDefault !== '' ? $websiteDefault : null
                );
                if ($hit === null) {
                    $out[] = [
                        'locale' => $locale,
                        'channel' => $channel,
                        'error' => 'resolve_null',
                    ];
                    continue;
                }

                $resolvedLocale = (string)$hit['locale'];
                $resolvedScope = (string)$hit['storage_scope'];
                $tpl = $hit['template'];
                $allowed = array_values(array_unique(array_merge(
                    array_values(array_filter(array_map(
                        static fn(array $v): string => trim((string)($v['code'] ?? '')),
                        $meta['variables'] ?? []
                    ))),
                    MailBrandContextService::variableCodes()
                )));
                $samples = self::latinSamplesFromChannel($meta, $locale);
                $brandKey = $resolvedLocale . '|' . $resolvedScope;
                if (!isset($brandCache[$brandKey])) {
                    $brandCache[$brandKey] = $brand->mergeInto([], $resolvedScope, $resolvedLocale);
                }
                $vars = array_merge($brandCache[$brandKey], $samples);

                $subjectTpl = (string)$tpl->getData(SmtpMailTemplate::schema_fields_SUBJECT);
                $bodyTpl = $shell->extractBodyFragment((string)$tpl->getData(SmtpMailTemplate::schema_fields_BODY_HTML));
                $subject = $brand->ensureBrandedSubject($renderer->render($subjectTpl, $vars, $allowed), $vars);
                $bodyHtml = $renderer->render($bodyTpl, $vars, $allowed);
                $shellKey = $resolvedLocale . '|' . $resolvedScope;
                if (!isset($shellCache[$shellKey])) {
                    $shellCache[$shellKey] = $shell->loadShell($resolvedLocale, $resolvedScope);
                }
                $content = str_replace(
                    ['{{MAIL_BODY}}', '{{PREHEADER}}'],
                    [$bodyHtml, htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
                    $shellCache[$shellKey]
                );
                $content = $renderer->render($content, $vars, $allowed);

                /** @var SmtpSendLog $log */
                $log = ObjectManager::getInstance(SmtpSendLog::class);
                $log->clearData()
                    ->setData(SmtpSendLog::schema_fields_FROM_EMAIL, 'matrix-preview@localhost')
                    ->setData(SmtpSendLog::schema_fields_SENDER_NAME, 'MatrixPreview')
                    ->setData(SmtpSendLog::schema_fields_TO_EMAIL, json_encode([['email' => 'matrix-preview@example.com', 'name' => 'QA']], JSON_UNESCAPED_UNICODE))
                    ->setData(SmtpSendLog::schema_fields_REPLY_TO, json_encode([], JSON_UNESCAPED_UNICODE))
                    ->setData(SmtpSendLog::schema_fields_SUBJECT, mb_substr('[matrix-preview][' . $locale . '][' . $channel . '] ' . $subject, 0, 255))
                    ->setData(SmtpSendLog::schema_fields_CONTENT, $content)
                    ->setData(SmtpSendLog::schema_fields_ALT, $renderer->htmlToText($content))
                    ->setData(SmtpSendLog::schema_fields_CC, json_encode([], JSON_UNESCAPED_UNICODE))
                    ->setData(SmtpSendLog::schema_fields_BCC, json_encode([], JSON_UNESCAPED_UNICODE))
                    ->setData(SmtpSendLog::schema_fields_IS_HTML, 1)
                    ->setData(SmtpSendLog::schema_fields_ATTACHMENT, json_encode([], JSON_UNESCAPED_UNICODE))
                    ->setData(SmtpSendLog::schema_fields_PROXY, 'matrix-preview')
                    ->setData(SmtpSendLog::schema_fields_MODULE, 'Weline_Smtp')
                    ->setData(SmtpSendLog::schema_fields_CHANNEL, $channel)
                    ->setData(SmtpSendLog::schema_fields_SENDER_CODE, 'matrix-preview')
                    ->setData(SmtpSendLog::schema_fields_STORAGE_SCOPE, $resolvedScope)
                    ->setData(SmtpSendLog::schema_fields_TEMPLATE_ID, (int)$tpl->getId())
                    ->setData(SmtpSendLog::schema_fields_LOCALE, $locale)
                    ->save();

                $logId = (int)$log->getId();
                $url = $hostBase . '/' . trim($backendPrefix, '/') . '/zh_Hans_CN/smtp/backend/log?log_id=' . $logId . '&embed=1';
                $visible = self::visibleText($subject . "\n" . $content);
                $out[] = [
                    'locale' => $locale,
                    'channel' => $channel,
                    'resolved_locale' => $resolvedLocale,
                    'template_id' => (int)$tpl->getId(),
                    'log_id' => $logId,
                    'url' => $url,
                    'subject' => $subject,
                    'cjk_hits' => self::findCjk($visible),
                    'en_marker_hits' => self::findEnMarkers(
                        $visible,
                        self::buildEnMarkersForChannel($resolver, $shell, $channel, $scope, $websiteDefault)
                    ),
                    'shell_en_hits' => self::findShellEnLabels($visible),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function buildEnMarkersForChannel(
        MailTemplateResolver $resolver,
        MailTemplateShellComposer $shell,
        string $channel,
        string $scope,
        string $websiteDefault,
    ): array {
        $hit = $resolver->resolve(
            $channel,
            $scope,
            'en_US',
            $websiteDefault !== '' ? $websiteDefault : null
        );
        $markers = self::GLOBAL_EN_MARKERS;
        if ($hit === null) {
            return array_values(array_unique($markers));
        }
        $tpl = $hit['template'];
        $subject = (string)$tpl->getData(SmtpMailTemplate::schema_fields_SUBJECT);
        $body = $shell->extractBodyFragment((string)$tpl->getData(SmtpMailTemplate::schema_fields_BODY_HTML));
        $raw = $subject . "\n" . $body;
        $raw = preg_replace('/\{\{\s*[^}]+\}\}/', ' ', $raw) ?? $raw;
        $text = self::visibleText($raw);

        foreach (self::GLOBAL_EN_MARKERS as $g) {
            if (stripos($text, $g) !== false) {
                $markers[] = $g;
            }
        }
        // 抽取 2～6 词的英文短句作特征
        if (preg_match_all('/\b([A-Za-z][A-Za-z\']*(?:\s+[A-Za-z][A-Za-z\']*){1,5})\b/', $text, $m)) {
            foreach ($m[1] as $phrase) {
                $phrase = trim($phrase);
                if (strlen($phrase) < 12) {
                    continue;
                }
                // 跳过过泛词组
                if (preg_match('/^(Your|The|This|Please|Hello|Dear)\b/i', $phrase) === 1 && strlen($phrase) < 18) {
                    continue;
                }
                $markers[] = $phrase;
            }
        }
        $subjectPlain = trim(preg_replace('/\{\{\s*[^}]+\}\}/', '', $subject) ?? $subject);
        if (strlen($subjectPlain) >= 8) {
            $markers[] = $subjectPlain;
        }

        $markers = array_values(array_unique(array_filter(array_map('trim', $markers))));
        // 控制数量：优先较长特征
        usort($markers, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_slice($markers, 0, 24);
    }

    /**
     * @param list<string> $markers
     * @return list<string>
     */
    public static function findEnMarkers(string $visible, array $markers): array
    {
        $hits = [];
        foreach ($markers as $marker) {
            if ($marker === '') {
                continue;
            }
            if (stripos($visible, $marker) !== false) {
                $hits[] = $marker;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * 可见整信（subject + wrapped HTML）上的壳层英标命中。
     * 不扫描 href/URL 原文：先 visibleText 去标签，且模式要求冒号/问号，避免 brand URL 误杀。
     *
     * @return list<string>
     */
    public static function findShellEnLabels(string $visible): array
    {
        $hits = [];
        foreach (self::SHELL_EN_LABEL_PATTERNS as $row) {
            $id = (string)($row['id'] ?? '');
            $pattern = (string)($row['pattern'] ?? '');
            if ($id === '' || $pattern === '') {
                continue;
            }
            if (preg_match($pattern, $visible) === 1) {
                $hits[] = $id;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param array<string, mixed> $ch
     * @return array<string, string>
     */
    public static function latinSamplesFromChannel(array $ch, string $locale = 'en_US'): array
    {
        $out = [];
        foreach ($ch['variables'] ?? [] as $var) {
            if (!is_array($var)) {
                continue;
            }
            $code = trim((string)($var['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            // topics_label：非 en 强制 locale 本地化样例，避免「Offers / New arrivals」注入假绿/误报
            if ($code === 'topics_label'
                && !self::isEnglishLocale($locale)
                && !self::isChineseLocale($locale)
            ) {
                $out[$code] = self::localizedTopicsLabel($locale);
                continue;
            }
            if ($code === 'topics_label' && self::isChineseLocale($locale)) {
                $out[$code] = '优惠 / 新品';
                continue;
            }
            $sample = (string)($var['sample'] ?? '');
            if ($sample === '' || self::findCjk($sample) !== []) {
                $out[$code] = match ($code) {
                    'topics_label' => 'Offers / New arrivals',
                    'discount_label' => '10%',
                    'email' => 'guest@example.com',
                    'coupon_code' => 'MWABCD1234',
                    'valid_until' => '2026-10-06',
                    'shop_url' => 'https://example.com/',
                    'customer_name' => 'Guest',
                    'order_number' => 'ORD-MATRIX-001',
                    default => 'sample',
                };
            } else {
                $out[$code] = $sample;
            }
        }

        return $out;
    }

    /**
     * 默认站启用 locale 的 topics_label 本地化样例（ru 不得用 Offers / New arrivals）。
     */
    public static function localizedTopicsLabel(string $locale): string
    {
        $map = [
            'ar_SA' => 'عروض / وصول جديد',
            'bn_BD' => 'অফার / নতুন আগমন',
            'bg_BG' => 'Оферти / Нови продукти',
            'ca_ES' => 'Ofertes / Novetats',
            'cs_CZ' => 'Nabídky / Novinky',
            'da_DK' => 'Tilbud / Nyheder',
            'de_DE' => 'Angebote / Neuheiten',
            'el_GR' => 'Προσφορές / Νέα άφιξη',
            'es_ES' => 'Ofertas / Novedades',
            'es_MX' => 'Ofertas / Novedades',
            'et_EE' => 'Pakkumised / Uued tooted',
            'fi_FI' => 'Tarjoukset / Uutuudet',
            'fr_FR' => 'Offres / Nouveautés',
            'fr_CA' => 'Offres / Nouveautés',
            'ga_IE' => 'Tairiscintí / Earraí nua',
            'hi_IN' => 'ऑफ़र / नए आगमन',
            'hr_HR' => 'Ponude / Noviteti',
            'hu_HU' => 'Ajánlatok / Újdonságok',
            'id_ID' => 'Penawaran / Kedatangan baru',
            'is_IS' => 'Tilboð / Nýjar vörur',
            'it_IT' => 'Offerte / Novità',
            'lt_LT' => 'Pasiūlymai / Nauji produktai',
            'lv_LV' => 'Piedāvājumi / Jaunumi',
            'mt_MT' => 'Offerti / Arrivi ġodda',
            'nb_NO' => 'Tilbud / Nyheter',
            'nl_NL' => 'Aanbiedingen / Nieuw',
            'pl_PL' => 'Oferty / Nowości',
            'pt_BR' => 'Ofertas / Novidades',
            'pt_PT' => 'Ofertas / Novidades',
            'ro_RO' => 'Oferte / Noutăți',
            'ru_RU' => 'Акции / Новинки',
            'sk_SK' => 'Ponuky / Novinky',
            'sl_SI' => 'Ponudbe / Novosti',
            'sv_SE' => 'Erbjudanden / Nyheter',
            'tr_TR' => 'Teklifler / Yeni gelenler',
            'uk_UA' => 'Пропозиції / Новинки',
            'ur_PK' => 'آفرز / نئی آمد',
        ];
        if (isset($map[$locale])) {
            return $map[$locale];
        }
        $lang = strtolower(explode('_', str_replace('-', '_', $locale))[0] ?? '');
        foreach ($map as $code => $label) {
            if (str_starts_with(strtolower($code), $lang . '_')) {
                return $label;
            }
        }

        return 'sample-topics';
    }

    public static function isChineseLocale(string $locale): bool
    {
        return (bool)preg_match('/^zh([_-]|$)/i', trim($locale));
    }

    public static function isEnglishLocale(string $locale): bool
    {
        return (bool)preg_match('/^en([_-]|$)/i', trim($locale));
    }

    public static function visibleText(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    public static function findCjk(string $text): array
    {
        foreach (self::CJK_WHITELIST as $ok) {
            if ($ok !== '') {
                $text = str_replace($ok, '', $text);
            }
        }
        if ($text === '' || !preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text, $m)) {
            return [];
        }

        return array_values(array_unique($m[0]));
    }

    /**
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s): bool => $s !== ''));
    }

    private static function progress(bool $quiet, string $msg): void
    {
        if ($quiet) {
            return;
        }
        fwrite(STDOUT, $msg . "\n");
        fflush(STDOUT);
    }

    private static function tick(bool $quiet, int $done, int $planned, int $pass, int $fail): void
    {
        if ($quiet) {
            return;
        }
        if ($done % 40 === 0 || $done === $planned) {
            self::progress($quiet, "progress {$done}/{$planned} pass={$pass} fail={$fail}");
        }
    }
}
