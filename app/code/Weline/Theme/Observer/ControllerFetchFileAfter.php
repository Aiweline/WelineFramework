<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;
use Weline\Theme\Service\PreparedContentStore;

/**
 * Keep layout rendering lifecycle unified for controller template fetch.
 *
 * Flow:
 * 1) render original content template
 * 2) inject backend content by request-scoped key, frontend content by direct HTML
 * 3) render layout template
 * 4) if layout requested wrapper via `$this->setLayout(...)`, continue wrapping
 */
class ControllerFetchFileAfter implements ObserverInterface
{
    private const MAX_LAYOUT_WRAP_DEPTH = 4;

    protected function getTemplateInstance(): Template
    {
        return Template::getInstance();
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject|mixed $eventData */
        $eventData = $event->getData('data');
        if (!$eventData instanceof DataObject) {
            return;
        }

        $layoutType = (string)$eventData->getData('layoutType');
        $contentTemplate = (string)$eventData->getData('contentTemplate');
        $layoutTemplate = (string)($eventData->getData('layoutTemplate') ?: $eventData->getData('fileName'));
        $fileName = (string)$eventData->getData('fileName');
        $layoutOption = (string)($eventData->getData('layoutOption') ?? '');
        if ($layoutType === '' || $contentTemplate === '' || $layoutTemplate === '') {
            return;
        }

        $template = $this->getTemplateInstance();
        $fallbackContent = (string)$eventData->getData('content');

        try {
            $contentHtml = $this->renderContentTemplate($template, $contentTemplate, $fallbackContent);
            $fastAuthHtml = $this->renderFastAccountAuthLayout($template, $layoutTemplate, $contentHtml);
            if ($fastAuthHtml !== null) {
                $eventData->setData('content', $fastAuthHtml);
                $eventData->setData('fileName', $layoutTemplate);
                return;
            }

            $this->restoreAccountRenderSnapshot($template, $eventData, $layoutTemplate, $contentTemplate);
            [$renderedHtml, $finalLayoutTemplate] = $this->renderLayoutChain(
                $template,
                $layoutTemplate,
                $contentTemplate,
                $contentHtml
            );
            if ($this->isAccountLayoutTemplate($finalLayoutTemplate, $contentTemplate)
                && !$this->htmlHasAccountSidebar($renderedHtml)
                && $this->snapshotHasAccountSidebar($eventData)
            ) {
                $this->restoreAccountRenderSnapshot($template, $eventData, $finalLayoutTemplate, $contentTemplate);
                [$renderedHtml, $finalLayoutTemplate] = $this->renderLayoutChain(
                    $template,
                    $finalLayoutTemplate,
                    $contentTemplate,
                    $contentHtml
                );
                try {
                    ObjectManager::getInstance(Request::class)->getResponse()->setHeader('X-Weline-Account-Sidebar-Restored', '1');
                } catch (\Throwable) {
                }
            }

            $this->logAccountSidebarDebug('controller_fetch_after_final', [
                'layout_template' => $finalLayoutTemplate,
                'content_template' => $contentTemplate,
                'content_len' => \strlen($renderedHtml),
                'has_account_sidebar' => $this->htmlHasAccountSidebar($renderedHtml),
            ]);
            $eventData->setData('content', $renderedHtml);
            $eventData->setData('fileName', $finalLayoutTemplate);
        } catch (\Throwable $e) {
            $this->reportLayoutWrapFailure(
                $layoutType,
                $layoutOption,
                $contentTemplate,
                $layoutTemplate,
                $fileName,
                $e
            );
            // deploy≠dev 时保留原始 event content；deploy=dev 时由 reportLayoutWrapFailure 重新抛出
        }
    }

    /**
     * 布局包装失败时写日志、响应头；deploy=dev 时抛出以便本地立刻发现。
     */
    private function reportLayoutWrapFailure(
        string $layoutType,
        string $layoutOption,
        string $contentTemplate,
        string $layoutTemplate,
        string $fileName,
        \Throwable $e
    ): void {
        $uri = '';
        try {
            $request = ObjectManager::getInstance(Request::class);
            $uri = (string)($request->getServer('REQUEST_URI') ?? $request->getUri() ?? '');
            if ($this->shouldEmitLayoutWrapResponseHeaders()) {
                $response = $request->getResponse();
                $response->setHeader('X-Weline-Layout-Wrap-Failed', '1');
                $response->setHeader('X-Weline-Layout-Type', $layoutType);
                if ($layoutOption !== '') {
                    $response->setHeader('X-Weline-Layout-Option', $layoutOption);
                }
                $response->setHeader('X-Weline-Layout-Template', $layoutTemplate);
                $response->setHeader('X-Weline-Content-Template', $contentTemplate);
                $response->setHeader('X-Weline-File-Name', $fileName);
            }
        } catch (\Throwable) {
        }

        try {
            Env::getInstance()->getLogger()?->error('[Theme Layout Wrap Failed]', [
                'uri' => $uri,
                'layoutType' => $layoutType,
                'layoutOption' => $layoutOption,
                'contentTemplate' => $contentTemplate,
                'layoutTemplate' => $layoutTemplate,
                'fileName' => $fileName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (\Throwable) {
        }

        if ($this->shouldRethrowLayoutWrapFailure()) {
            throw $e;
        }
    }

    private function shouldRethrowLayoutWrapFailure(): bool
    {
        if (defined('ENV_TEST') && constant('ENV_TEST')) {
            return false;
        }
        try {
            return (Env::system('deploy') ?? '') === 'dev';
        } catch (\Throwable) {
            return false;
        }
    }

    /** deploy=dev 或 dev.theme_layout_wrap_response_headers=true（PHPUnit 永不输出） */
    private function shouldEmitLayoutWrapResponseHeaders(): bool
    {
        if (defined('ENV_TEST') && constant('ENV_TEST')) {
            return false;
        }
        try {
            if (Env::getInstance()->getConfig('dev.theme_layout_wrap_response_headers', false)) {
                return true;
            }
            return (Env::system('deploy') ?? '') === 'dev';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 将控制器 assign 到 Template 根上的变量并入 meta，便于布局层 {{meta.xxx}} 与根变量同源。
     */
    private function mergeTemplateRootAssignsIntoMeta(Template $template, array $metaData): array
    {
        $skip = [
            'meta',
            'theme',
            'colors',
            'layout',
            'child_html',
            'content',
            'contentTemplate',
            'layoutTemplate',
            'fileName',
            'contentRenderKey',
            'controller',
            'request',
            'req',
            'session',
            'eventsManager',
            'viewCache',
            'taglib',
            'compile_dir',
            'template_dir',
            'statics_dir',
            'view_dir',
        ];
        try {
            $all = $template->getData('');
            if (!is_array($all)) {
                return $metaData;
            }
            foreach ($all as $key => $value) {
                if (!is_string($key) || str_starts_with($key, '__')) {
                    continue;
                }
                if (in_array($key, $skip, true)) {
                    continue;
                }
                if ($value instanceof Request || $value instanceof PcController) {
                    continue;
                }
                $metaData[$key] = $value;
            }
        } catch (\Throwable) {
        }

        return $metaData;
    }

    private function renderContentTemplate(Template $template, string $contentTemplate, string $fallbackContent): string
    {
        if ($fallbackContent !== '') {
            return $fallbackContent;
        }

        try {
            $rendered = (string)$template->fetch($contentTemplate);
            if ($rendered !== '') {
                return $rendered;
            }
        } catch (\Throwable) {
        }

        return $fallbackContent;
    }

    private function renderFastAccountAuthLayout(Template $template, string $layoutTemplate, string $contentHtml): ?string
    {
        $normalizedLayout = \str_replace('\\', '/', $layoutTemplate);
        if (!\str_contains($normalizedLayout, 'Weline_Theme::theme/frontend/layouts/account/auth.phtml')
            && !\str_contains($normalizedLayout, 'Weline_Theme::theme/frontend/layouts/account_auth/default.phtml')
        ) {
            return null;
        }

        $traceEnabled = RequestLifecycleTrace::isEnabled();
        $traceStart = $traceEnabled ? \microtime(true) : 0.0;

        try {
            $meta = $template->getData('meta');
            if (!\is_array($meta)) {
                $meta = [];
            }
            $locale = (string)($template->getData('locale') ?: \Weline\Framework\App\State::getLangLocal() ?: 'zh_Hans_CN');
            $lang = \str_replace('_', '-', $locale);
            $title = (string)($template->getData('title') ?: ($meta['title'] ?? 'Weline Framework'));
            $description = (string)($meta['description'] ?? '');

            $langEsc = \htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $localeEsc = \htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $titleEsc = \htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $descriptionHtml = $description !== ''
                ? '    <meta name="description" content="' . \htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . "\n"
                : '';

            return <<<HTML
<!DOCTYPE html>
<html lang="{$langEsc}" data-local="{$localeEsc}" data-lang="{$localeEsc}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$titleEsc}</title>
{$descriptionHtml}    <link rel="icon" type="image/svg+xml" href="/Weline/Theme/view/statics/theme/frontend/assets/img/favicon.svg">
    <link href="/Weline/Theme/view/theme/frontend/assets/css/theme.css" rel="stylesheet" type="text/css">
    <link href="/Weline/Theme/view/theme/frontend/assets/css/auth-form-wflash.css" rel="stylesheet" type="text/css">
    <style data-auth-theme-vars>
        :root {
            --color-primary: #f0c14b;
            --color-primary-dark: #d99a05;
            --color-primary-border: #a88734;
            --color-text-primary: #111827;
            --color-text-secondary: #64748b;
            --color-text-tertiary: #94a3b8;
            --color-text-light: #fff;
            --color-bg-primary: #fff;
            --color-bg-secondary: #f8fafc;
            --color-border-default: #d1d5db;
            --color-border-focus: #f59e0b;
            --color-border-light: #e5e7eb;
            --color-link: #0f62ba;
            --color-link-hover: #b45309;
            --color-success: #22c55e;
            --color-accent-light: rgba(59, 130, 246, .12);
            --spacing-xs: .25rem;
            --spacing-sm: .5rem;
            --spacing-md: 1rem;
            --spacing-lg: 1.5rem;
            --spacing-xl: 2rem;
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-md: 0 12px 32px rgba(15, 23, 42, .12);
            --font-size-sm: .875rem;
            --font-size-base: 1rem;
            --font-size-xl: 1.5rem;
            --font-size-xxl: 1.875rem;
            --font-weight-semibold: 600;
            --font-weight-bold: 700;
            --auth-input-height: 44px;
            --auth-input-padding-y: .75rem;
            --auth-input-radius: 8px;
            --auth-input-border-width: 2px;
        }
        body.account-auth-layout {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
            font-family: "Segoe UI", sans-serif;
            color: var(--color-text-primary);
        }
        .weline-page-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            box-sizing: border-box;
        }
        .weline-main-content,
        .auth-container {
            width: 100%;
        }
    </style>
</head>
<body class="account-auth-layout">
    <div class="weline-page-wrapper">
        <div class="weline-main-content">
            <main class="account-auth-content" id="account-auth-content" data-layout="account_auth_content">
                <div class="auth-container">
                    {$contentHtml}
                </div>
            </main>
        </div>
    </div>
</body>
</html>
HTML;
        } finally {
            if ($traceEnabled) {
                RequestLifecycleTrace::recordSpan(
                    'theme::ControllerFetchFileAfter::fastAccountAuthLayout',
                    (\microtime(true) - $traceStart) * 1000,
                    'theme'
                );
            }
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function renderLayoutChain(
        Template $template,
        string $layoutTemplate,
        string $contentTemplate,
        string $contentHtml
    ): array {
        $traceEnabled = RequestLifecycleTrace::isEnabled();
        $traceName = 'theme::ControllerFetchFileAfter::renderLayoutChain';
        $traceStart = $traceEnabled ? microtime(true) : 0.0;
        if ($traceEnabled) {
            RequestLifecycleTrace::pushCurrentParent($traceName);
        }

        $currentLayoutTemplate = $layoutTemplate;
        $currentContentTemplate = $contentTemplate;
        $currentContentHtml = $contentHtml;
        $renderedHtml = $contentHtml;
        $layoutProfiles = [];

        try {
            for ($depth = 0; $depth < self::MAX_LAYOUT_WRAP_DEPTH; $depth++) {
                $layoutDepthStart = \microtime(true);
                $contentRenderKey = $this->primeTemplateData(
                    $template,
                    $currentLayoutTemplate,
                    $currentContentTemplate,
                    $currentContentHtml
                );

                // Clear previous layout directive before rendering.
                $template->setData('layout', null);
                $layoutFetchSpan = 'theme::ControllerFetchFileAfter::layoutFetch::'
                    . $this->traceTemplateLabel($currentLayoutTemplate)
                    . "::depth_{$depth}";
                $layoutFetchStart = $traceEnabled ? microtime(true) : 0.0;
                if ($traceEnabled) {
                    RequestLifecycleTrace::pushCurrentParent($layoutFetchSpan);
                }
                $layoutFetchWallStart = \microtime(true);
                try {
                    $renderedHtml = (string)$template->fetch(
                        $currentLayoutTemplate,
                        $this->buildLayoutFetchData($currentLayoutTemplate, $currentContentHtml, $contentRenderKey)
                    );
                    $layoutProfiles[] = [
                        'depth' => $depth,
                        'layout' => $currentLayoutTemplate,
                        'content' => $currentContentTemplate,
                        'fetch_ms' => \round((\microtime(true) - $layoutFetchWallStart) * 1000, 2),
                        'total_ms' => \round((\microtime(true) - $layoutDepthStart) * 1000, 2),
                        'bytes' => \strlen($renderedHtml),
                    ];
                    if ($this->isAccountLayoutTemplate($currentLayoutTemplate, $currentContentTemplate)) {
                        $meta = $template->getData('meta');
                        $metaSidebar = \is_array($meta) ? ($meta['sidebar'] ?? null) : null;
                        $rootSidebar = $template->getData('sidebar');
                        $hasAccountSidebar = $this->htmlHasAccountSidebar($renderedHtml);
                        RequestLifecycleTrace::recordSpan(
                            'theme::accountLayout::afterFetch',
                            0.0,
                            'theme',
                            $layoutFetchSpan,
                            [
                                'rendered_len' => \strlen($renderedHtml),
                                'has_account_sidebar' => $hasAccountSidebar,
                                'meta_sidebar_len' => \is_string($metaSidebar) ? \strlen(\trim($metaSidebar)) : 0,
                                'root_sidebar_len' => \is_string($rootSidebar) ? \strlen(\trim($rootSidebar)) : 0,
                                'template_id' => \spl_object_id($template),
                            ]
                        );
                        if (!$hasAccountSidebar && \function_exists('w_log_warning')) {
                            \w_log_warning('[AccountSidebar] account layout rendered without sidebar', [
                                'uri' => $this->currentUri(),
                                'layout_template' => $currentLayoutTemplate,
                                'content_template' => $currentContentTemplate,
                                'rendered_len' => \strlen($renderedHtml),
                                'meta_sidebar_len' => \is_string($metaSidebar) ? \strlen(\trim($metaSidebar)) : 0,
                                'root_sidebar_len' => \is_string($rootSidebar) ? \strlen(\trim($rootSidebar)) : 0,
                                'meta_keys' => \is_array($meta) ? \array_keys($meta) : [],
                                'template_id' => \spl_object_id($template),
                            ], 'account_sidebar');
                        }
                        $this->logAccountSidebarDebug('layout_after_fetch', [
                            'layout_template' => $currentLayoutTemplate,
                            'content_template' => $currentContentTemplate,
                            'rendered_len' => \strlen($renderedHtml),
                            'has_account_sidebar' => $hasAccountSidebar,
                            'meta_sidebar_len' => \is_string($metaSidebar) ? \strlen(\trim($metaSidebar)) : 0,
                            'root_sidebar_len' => \is_string($rootSidebar) ? \strlen(\trim($rootSidebar)) : 0,
                            'template_id' => \spl_object_id($template),
                        ]);
                    }
                } finally {
                    if ($traceEnabled) {
                        RequestLifecycleTrace::popCurrentParent();
                        RequestLifecycleTrace::recordSpan(
                            $layoutFetchSpan,
                            (microtime(true) - $layoutFetchStart) * 1000,
                            'theme'
                        );
                    }
                }

                $nextLayout = trim((string)$template->getData('layout'));
                if ($nextLayout === '') {
                    $this->logLayoutProfiles($layoutProfiles, $contentTemplate, $renderedHtml);
                    return [$renderedHtml, $currentLayoutTemplate];
                }

                $nextLayoutTemplate = $this->resolveNextLayoutTemplate($currentLayoutTemplate, $nextLayout);
                if ($nextLayoutTemplate === '' || $nextLayoutTemplate === $currentLayoutTemplate) {
                    $this->logLayoutProfiles($layoutProfiles, $contentTemplate, $renderedHtml);
                    return [$renderedHtml, $currentLayoutTemplate];
                }

                // Next wrapper uses current layout output as its content.
                $currentContentTemplate = $currentLayoutTemplate;
                $currentContentHtml = $renderedHtml;
                $currentLayoutTemplate = $nextLayoutTemplate;
            }

            $this->logLayoutProfiles($layoutProfiles, $contentTemplate, $renderedHtml);
            return [$renderedHtml, $currentLayoutTemplate];
        } finally {
            if ($traceEnabled) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan(
                    $traceName,
                    (microtime(true) - $traceStart) * 1000,
                    'theme'
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $layoutProfiles
     */
    private function logLayoutProfiles(array $layoutProfiles, string $contentTemplate, string $renderedHtml): void
    {
        if ($layoutProfiles === []) {
            return;
        }

        $totalMs = 0.0;
        foreach ($layoutProfiles as $profile) {
            $totalMs += (float)($profile['total_ms'] ?? 0);
        }

        $uri = $this->currentUri();
        if (
            $totalMs < 20.0
            && !\str_contains($uri, 'customer/account')
            && !\str_contains($contentTemplate, 'account')
        ) {
            return;
        }

        \error_log('[LayoutPerf] chain ' . \json_encode([
            'uri' => $uri,
            'content_template' => $contentTemplate,
            'total_ms' => \round($totalMs, 2),
            'bytes' => \strlen($renderedHtml),
            'layouts' => $layoutProfiles,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private function primeTemplateData(
        Template $template,
        string $layoutTemplate,
        string $contentTemplate,
        string $contentHtml
    ): string
    {
        $isBackendLayout = $this->isBackendLayoutTemplate($layoutTemplate);
        $contentRenderKey = $isBackendLayout ? PreparedContentStore::put($contentHtml) : '';

        $metaData = $template->getData('meta');
        if (!is_array($metaData)) {
            $metaData = [];
        }
        $metaData['contentTemplate'] = $contentTemplate;
        if ($contentRenderKey !== '') {
            unset($metaData['content']);
            $metaData['contentRenderKey'] = $contentRenderKey;
        } else {
            unset($metaData['contentRenderKey']);
            $metaData['content'] = $contentHtml;
        }
        $metaData = $this->mergeTemplateRootAssignsIntoMeta($template, $metaData);
        $metaData = $this->ensureAccountSidebarMeta($template, $layoutTemplate, $contentTemplate, $metaData, $contentHtml);
        $template->setData('meta', $metaData);

        $childHtml = $template->getData('child_html');
        if (!is_array($childHtml)) {
            $childHtml = [];
        }
        if ($contentRenderKey !== '') {
            unset($childHtml['content']);
            $childHtml['contentRenderKey'] = $contentRenderKey;
        } else {
            unset($childHtml['contentRenderKey']);
            $childHtml['content'] = $contentHtml;
        }
        $template->setData('child_html', $childHtml);

        if ($contentRenderKey !== '') {
            $template->setData('content', null);
            $template->setData('contentRenderKey', $contentRenderKey);
        } else {
            $template->setData('content', $contentHtml);
            $template->setData('contentRenderKey', null);
        }
        $template->setData('contentTemplate', $contentTemplate);

        return $contentRenderKey;
    }

    /**
     * Account layouts read sidebar from meta. Keep the controller's root assign
     * and meta assign in sync before layout rendering, and log the rare empty
     * state with enough request context to identify the producer.
     *
     * @param array<string, mixed> $metaData
     * @return array<string, mixed>
     */
    private function ensureAccountSidebarMeta(
        Template $template,
        string $layoutTemplate,
        string $contentTemplate,
        array $metaData,
        string $contentHtml
    ): array {
        if (!$this->isAccountLayoutTemplate($layoutTemplate, $contentTemplate)) {
            return $metaData;
        }

        $metaSidebar = $metaData['sidebar'] ?? null;
        $metaSidebarLength = \is_string($metaSidebar) ? \strlen(\trim($metaSidebar)) : 0;
        $rootSidebar = $template->getData('sidebar');
        $rootSidebarLength = \is_string($rootSidebar) ? \strlen(\trim($rootSidebar)) : 0;
        if ($metaSidebarLength === 0 && $rootSidebarLength > 0) {
            $metaData['sidebar'] = $rootSidebar;
            $metaSidebarLength = $rootSidebarLength;
        }

        RequestLifecycleTrace::recordSpan('theme::accountLayout::primeSidebar', 0.0, 'theme', null, [
            'meta_sidebar_len' => $metaSidebarLength,
            'root_sidebar_len' => $rootSidebarLength,
            'content_len' => \strlen($contentHtml),
            'template_id' => \spl_object_id($template),
        ]);
        $this->logAccountSidebarDebug('prime_sidebar', [
            'layout_template' => $layoutTemplate,
            'content_template' => $contentTemplate,
            'meta_sidebar_len' => $metaSidebarLength,
            'root_sidebar_len' => $rootSidebarLength,
            'content_len' => \strlen($contentHtml),
            'template_id' => \spl_object_id($template),
            'meta_keys' => \array_keys($metaData),
        ]);

        if ($metaSidebarLength === 0 && \function_exists('w_log_warning')) {
            try {
                $request = ObjectManager::getInstance(Request::class);
                \w_log_warning('[AccountSidebar] layout meta sidebar missing before render', [
                    'uri' => (string)($request->getServer('REQUEST_URI') ?? $request->getUri() ?? ''),
                    'lang' => (string)($request->getServer('WELINE_USER_LANG') ?? ''),
                    'layout_template' => $layoutTemplate,
                    'content_template' => $contentTemplate,
                    'template_id' => \spl_object_id($template),
                    'root_sidebar_len' => $rootSidebarLength,
                    'meta_keys' => \array_keys($metaData),
                ], 'account_sidebar');
            } catch (\Throwable) {
            }
        }

        return $metaData;
    }

    private function restoreAccountRenderSnapshot(
        Template $template,
        DataObject $eventData,
        string $layoutTemplate,
        string $contentTemplate
    ): void {
        if (!$this->isAccountLayoutTemplate($layoutTemplate, $contentTemplate)) {
            return;
        }

        $snapshot = $eventData->getData('templateSnapshot');
        if (!\is_array($snapshot) || $snapshot === []) {
            return;
        }

        $meta = $template->getData('meta');
        if (!\is_array($meta)) {
            $meta = [];
        }

        $rootSidebar = $template->getData('sidebar');
        $rootSidebarLength = \is_string($rootSidebar) ? \strlen(\trim($rootSidebar)) : 0;
        $metaSidebar = $meta['sidebar'] ?? null;
        $metaSidebarLength = \is_string($metaSidebar) ? \strlen(\trim($metaSidebar)) : 0;
        $snapshotSidebar = $snapshot['sidebar'] ?? ($snapshot['meta_sidebar'] ?? null);
        if (\is_string($snapshotSidebar) && \trim($snapshotSidebar) !== '' && ($rootSidebarLength === 0 || $metaSidebarLength === 0)) {
            if ($rootSidebarLength === 0) {
                $template->setData('sidebar', $snapshotSidebar);
            }
            if ($metaSidebarLength === 0) {
                $meta['sidebar'] = $snapshotSidebar;
            }
        }

        if (isset($snapshot['meta']) && \is_array($snapshot['meta'])) {
            foreach (['showHeader', 'showFooter', 'sidebarCollapsed'] as $key) {
                if (!\array_key_exists($key, $meta) && \array_key_exists($key, $snapshot['meta'])) {
                    $meta[$key] = $snapshot['meta'][$key];
                }
            }
        }

        $title = \trim((string)($meta['title'] ?? ''));
        $snapshotTitle = (string)($snapshot['meta_title'] ?? ($snapshot['title'] ?? ''));
        if ($title === '' && \trim($snapshotTitle) !== '') {
            $meta['title'] = $snapshotTitle;
        }

        $template->setData('meta', $meta);
    }

    private function snapshotHasAccountSidebar(DataObject $eventData): bool
    {
        $snapshot = $eventData->getData('templateSnapshot');
        if (!\is_array($snapshot)) {
            return false;
        }

        foreach (['sidebar', 'meta_sidebar'] as $key) {
            $value = $snapshot[$key] ?? null;
            if (\is_string($value) && \trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isAccountLayoutTemplate(string $layoutTemplate, string $contentTemplate = ''): bool
    {
        $normalized = \str_replace('\\', '/', $layoutTemplate);
        $normalizedContent = \str_replace('\\', '/', $contentTemplate);
        return \str_contains($normalized, 'Weline_Theme::theme/frontend/layouts/account/')
            || \str_contains($normalized, '/Weline/Theme/view/theme/frontend/layouts/account/')
            || \str_contains($normalizedContent, 'Weline_Customer::templates/frontend/account/');
    }

    private function htmlHasAccountSidebar(string $html): bool
    {
        return \str_contains($html, 'class="account-sidebar')
            || \str_contains($html, "class='account-sidebar");
    }

    private function currentUri(): string
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
            return (string)($request->getServer('REQUEST_URI') ?? $request->getUri() ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function shouldDebugAccountSidebar(): bool
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
            return (string)$request->getGet('debug_sidebar', '') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Debug-only trace for WLS account sidebar disappearance. Enabled with
     * `debug_sidebar=1` so normal requests do not get noisy logs.
     */
    private function logAccountSidebarDebug(string $stage, array $context = []): void
    {
        if (!$this->shouldDebugAccountSidebar()) {
            return;
        }

        try {
            $request = ObjectManager::getInstance(Request::class);
            $context += [
                'request_id' => (string)(\Weline\Framework\Runtime\RequestContext::getId() ?? ''),
                'uri' => (string)($request->getServer('REQUEST_URI') ?? $request->getUri() ?? ''),
                'lang' => (string)\Weline\Framework\App\State::getLang(),
                'lang_local' => (string)\Weline\Framework\App\State::getLangLocal(),
                'currency' => (string)\Weline\Framework\App\State::getCurrency(),
            ];
        } catch (\Throwable) {
        }

        \error_log('[AccountSidebarTrace] ' . $stage . ' ' . (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'));
    }

    private function buildLayoutFetchData(string $layoutTemplate, string $contentHtml, string $contentRenderKey): array
    {
        if ($this->isBackendLayoutTemplate($layoutTemplate)) {
            return [
                'contentRenderKey' => $contentRenderKey,
            ];
        }

        return [
            'content' => $contentHtml,
        ];
    }

    private function isBackendLayoutTemplate(string $layoutTemplate): bool
    {
        return $this->detectAreaFromTemplatePath($layoutTemplate) === 'backend';
    }

    /**
     * 布局链内下一层必须使用 Weline_Theme::theme/{area}/... 模块路径。
     * 裸路径 theme/... 会被 Template::convertFetchFileName 解析到「当前请求模块/view/」，导致 Customer 等控制器下错解析、外层 base 等与主题不一致。
     */
    private function toWelineThemeLayoutModulePath(string $area, string $pathUnderArea): string
    {
        $pathUnderArea = str_replace('\\', '/', trim($pathUnderArea, '/'));

        return 'Weline_Theme::theme/' . $area . '/' . $pathUnderArea;
    }

    private function resolveNextLayoutTemplate(string $currentLayoutTemplate, string $layoutSpec): string
    {
        $layoutSpec = trim($layoutSpec);
        if ($layoutSpec === '') {
            return '';
        }

        if (str_contains($layoutSpec, '::')) {
            return $layoutSpec;
        }

        $layoutSpec = str_replace('\\', '/', $layoutSpec);
        if (str_starts_with($layoutSpec, 'theme/')) {
            $relative = str_ends_with($layoutSpec, '.phtml') ? $layoutSpec : ($layoutSpec . '.phtml');

            return 'Weline_Theme::' . $relative;
        }

        $area = $this->detectAreaFromTemplatePath($currentLayoutTemplate);
        $layoutSpec = trim($layoutSpec, '/');

        if (str_ends_with($layoutSpec, '.phtml')) {
            if (str_starts_with($layoutSpec, 'layouts/')) {
                return $this->toWelineThemeLayoutModulePath($area, $layoutSpec);
            }

            return $this->toWelineThemeLayoutModulePath($area, 'layouts/' . $layoutSpec);
        }

        if (!str_contains($layoutSpec, '.')) {
            if ($layoutSpec === 'base') {
                return $this->toWelineThemeLayoutModulePath($area, 'layouts/base.phtml');
            }

            return $this->toWelineThemeLayoutModulePath($area, 'layouts/' . $layoutSpec . '/default.phtml');
        }

        return $this->toWelineThemeLayoutModulePath($area, 'layouts/' . str_replace('.', '/', $layoutSpec) . '.phtml');
    }

    private function detectAreaFromTemplatePath(string $templatePath): string
    {
        $normalizedPath = str_replace('\\', '/', strtolower($templatePath));
        if (str_contains($normalizedPath, '/templates/frontend/theme-preview/content.phtml')
            || str_contains($normalizedPath, '/templates/backend/theme-preview/content.phtml')
        ) {
            $requestArea = $this->resolveEditorAreaFromRequest();
            if ($requestArea !== '') {
                return $requestArea;
            }
        }
        if (str_contains($normalizedPath, '/theme/backend/layouts/')
            || str_contains($normalizedPath, 'theme/backend/layouts/')) {
            return 'backend';
        }

        return 'frontend';
    }

    private function resolveEditorAreaFromRequest(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $area = strtolower(trim((string)$request->getParam('editor_area', '')));
            if ($area === '') {
                $area = strtolower(trim((string)$request->getParam('preview_area', '')));
            }

            return $area === 'backend' ? 'backend' : ($area === 'frontend' ? 'frontend' : '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function traceTemplateLabel(string $templatePath): string
    {
        $normalizedPath = str_replace('\\', '/', $templatePath);
        if (str_contains($normalizedPath, '::')) {
            [$module, $path] = explode('::', $normalizedPath, 2);
            return $module . '::' . basename($path);
        }

        return basename($normalizedPath);
    }
}
