<?php

declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\TraceGate;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\View\Template;
use Weline\Meta\Api\ParamDefinitionNormalizerInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/** One request's immutable page/partial source bytes, captured under the owner read lock. */
final class ThemeLayoutSourceSnapshot
{
    public const REQUEST_KEY = 'theme.layout_entity.source_snapshot';
    /** @var array<string, self> request-local capture memo (same key → same instance). */
    public const REQUEST_CAPTURE_MEMO_KEY = 'theme.layout_entity.source_snapshot.capture_memo.v1';

    private array $sources = [];
    private array $partials = [];
    private array $partialDependencies = [];
    private array $pageDependencies = [];
    private string $fingerprint = '';
    private ?string $page = null;
    private readonly string $installationToken;
    /** @var array<string, array{string, string, string}> */
    private array $sourceBindings = [];

    private function __construct(public readonly ThemeVersionIdentity $identity)
    {
        $this->installationToken = bin2hex(random_bytes(16));
    }

    public static function capture(ThemeLayoutEntityPaths $paths, ThemeVersionIdentity $identity, string $layoutType, string $option = 'default', string $targetType = 'global', ?int $targetId = null): self
    {
        $layoutType = \trim($layoutType);
        $option = \trim($option) !== '' ? \trim($option) : 'default';
        $targetType = \trim($targetType) !== '' ? \trim($targetType) : 'global';
        $targetId = $targetId === null ? 0 : (int)$targetId;
        $memoKey = self::captureMemoKey($identity, $layoutType, $option, $targetType, $targetId);

        if (RequestContext::isInitialized()) {
            $memo = RequestContext::get(self::REQUEST_CAPTURE_MEMO_KEY);
            if (\is_array($memo) && ($memo[$memoKey] ?? null) instanceof self) {
                return $memo[$memoKey];
            }
        }

        $build = static function () use ($paths, $identity, $layoutType, $option, $targetType, $targetId, $memoKey): self {
            if (self::allowsPublishedSourceCandidatesHotCache($identity)) {
                try {
                    /** @var StorefrontScopeHotCache $hot */
                    $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
                    $bag = $hot->rememberPolicy(
                        StorefrontThemeCacheCoordinator::publishedLayoutSourceCandidatesPolicy(),
                        $memoKey,
                        static function () use ($paths, $identity, $layoutType, $option, $targetType, $targetId): array {
                            return self::exportCandidateBag(
                                self::captureSources($paths, $identity, $layoutType, $option, $targetType, $targetId)
                            );
                        },
                    );
                    if (\is_array($bag)
                        && isset($bag['candidates'])
                        && \is_array($bag['candidates'])
                    ) {
                        return self::fromCandidates(
                            $identity,
                            \is_string($bag['page'] ?? null) ? (string)$bag['page'] : '',
                            $bag['candidates'],
                        );
                    }
                } catch (\Throwable) {
                    // Fail-open: disk capture under owner lock.
                }
            }

            return self::captureSources($paths, $identity, $layoutType, $option, $targetType, $targetId);
        };

        $snapshot = TraceGate::isArmed()
            ? TraceGate::measurePhase('theme.source.capture', $build, [
                'layout_type' => $layoutType,
                'hot_cache_eligible' => self::allowsPublishedSourceCandidatesHotCache($identity),
            ])
            : $build();

        if (RequestContext::isInitialized()) {
            $memo = RequestContext::get(self::REQUEST_CAPTURE_MEMO_KEY);
            if (!\is_array($memo)) {
                $memo = [];
            }
            $memo[$memoKey] = $snapshot;
            RequestContext::set(self::REQUEST_CAPTURE_MEMO_KEY, $memo);
        }

        return $snapshot;
    }

    public static function captureMemoKey(
        ThemeVersionIdentity $identity,
        string $layoutType,
        string $option = 'default',
        string $targetType = 'global',
        int $targetId = 0,
    ): string {
        return \implode('|', [
            $identity->cacheKey(),
            \trim($layoutType),
            \trim($option) !== '' ? \trim($option) : 'default',
            \trim($targetType) !== '' ? \trim($targetType) : 'global',
            (string)\max(0, $targetId),
        ]);
    }

    private static function allowsPublishedSourceCandidatesHotCache(ThemeVersionIdentity $identity): bool
    {
        if ($identity->mode !== ThemeVersionIdentity::MODE_FORMAL) {
            return false;
        }
        if (self::isEditorOrPreviewCaptureContext()) {
            return false;
        }

        return true;
    }

    private static function isEditorOrPreviewCaptureContext(): bool
    {
        try {
            if (!RequestContext::isInitialized()) {
                return false;
            }
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $editorMode = \trim((string)$request->getParam('editor_mode', ''));
            if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                return true;
            }
            return ObjectManager::getInstance(PreviewTokenService::class)->isPreviewMode();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{page: string, candidates: array<string, string>}
     */
    private static function exportCandidateBag(self $snapshot): array
    {
        $candidates = [];
        foreach ($snapshot->sources as $path => $source) {
            if (!\is_string($path) || !\is_array($source)) {
                continue;
            }
            $bytes = $source['bytes'] ?? null;
            if (\is_string($bytes)) {
                $candidates[$path] = $bytes;
            }
        }

        return [
            'page' => $snapshot->page ?? '',
            'candidates' => $candidates,
        ];
    }

    private static function captureSources(ThemeLayoutEntityPaths $paths, ThemeVersionIdentity $identity, string $layoutType, string $option, string $targetType, ?int $targetId): self
    {
        return ThemeLayoutEntityOwnerLock::read($identity, static function () use ($paths, $identity, $layoutType, $option, $targetType, $targetId): self {
            $page = $paths->pageLayoutPhtml($identity, $layoutType, $option, $targetType, $targetId);
            $files = [$page];
            $roots = [$paths->chromeRoot($identity) . 'partials', $paths->pageSourceRoot($identity, $layoutType, $option, $targetType, $targetId)];
            foreach ($roots as $root) {
                if (!is_dir($root)) { continue; }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'phtml') {
                        $files[] = $file->getPathname();
                    }
                }
            }
            $bytes = [];
            foreach ($files as $path) {
                if (!is_file($path)) { continue; }
                $content = file_get_contents($path);
                if (!is_string($content)) { throw new \RuntimeException('theme_layout_source_read_failed:' . $path); }
                $bytes[$path] = $content;
            }
            return self::fromCandidates($identity, $page, $bytes);
        });
    }

    /** In-memory historical candidates use the same validation and compiler as disk sources. */
    public static function fromCandidates(ThemeVersionIdentity $identity, string $pagePath, array $candidates): self
    {
        $snapshot = new self($identity);
        foreach ($candidates as $path => $bytes) {
            if (!is_string($bytes)) { continue; }
            $metadata = self::metadata($bytes);
            if ($metadata === null) { continue; }
            try {
                $sourceIdentity = ThemeVersionIdentity::fromArray($metadata['identity'] ?? []);
                if ($sourceIdentity->cacheKey() !== $identity->cacheKey()) { continue; }
            } catch (\InvalidArgumentException) { continue; }
            if (($metadata['resource_type'] ?? '') === 'page_dependency') {
                $prefix = substr($pagePath, 0, -6) . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR;
                if ($pagePath === '' || !str_starts_with($path, $prefix)) { continue; }
                $logical = (string)($metadata['logical_path'] ?? '');
                if ($logical !== '') { $snapshot->pageDependencies[$logical] = $path; }
            }
            $snapshot->sources[$path] = ['bytes' => $bytes, 'origin' => (string)($metadata['origin'] ?? $path)];
            if ($path === $pagePath) { $snapshot->page = $path; }
            if (in_array($metadata['resource_type'] ?? '', ['partial', 'partial_dependency'], true)) {
                $type = (string)($metadata['partial_type'] ?? $metadata['type'] ?? '');
                $option = (string)($metadata['partial_option'] ?? $metadata['option'] ?? 'default');
                if ($type !== '') {
                    if ($metadata['resource_type'] === 'partial') { $snapshot->partials[$type . '/' . $option] = $path; }
                    else { $snapshot->partialDependencies[$type . '/' . $option] = $path; }
                }
            }
        }
        $digests = array_map(static fn(array $source): string => hash('sha256', $source['origin'] . "\0" . $source['bytes']), $snapshot->sources);
        ksort($digests);
        $snapshot->fingerprint = hash('sha256', $identity->cacheKey() . "\0" . json_encode($digests, JSON_THROW_ON_ERROR));
        $snapshot->buildSourceBindings();
        return $snapshot;
    }

    public static function metadata(string $bytes): ?array
    {
        if (!preg_match('/^\s*<\?php\s*\/\*\s*weline-source:([A-Za-z0-9+\/=]+)\s*\*\/(?:\s*\?>)?/', $bytes, $match)) { return null; }
        $decoded = base64_decode($match[1], true);
        $metadata = is_string($decoded) ? json_decode($decoded, true) : null;
        return is_array($metadata) ? $metadata : null;
    }

    public function install(Template $template): void
    {
        if (TraceGate::isArmed()) {
            TraceGate::measurePhase('theme.source.install', fn() => $this->installSources($template));
            return;
        }
        $this->installSources($template);
    }

    private function installSources(Template $template): void
    {
        $template->pinSourceSet($this->installationToken, $this->sourceBindings);
        RequestContext::set(self::REQUEST_KEY, $this);
    }

    /** Preserve the original source/alias overwrite order, preparing bindings only once. */
    private function buildSourceBindings(): void
    {
        $contextKey = $this->identity->cacheKey();
        foreach ($this->sources as $path => $source) {
            $binding = [$source['bytes'], $source['origin'], $contextKey];
            $this->sourceBindings[$path] = $binding;
            $this->sourceBindings[$source['origin']] = $binding;
        }
        foreach ($this->pageDependencies as $logical => $path) {
            $source = $this->sources[$path];
            $this->sourceBindings[$logical] = [$source['bytes'], $source['origin'], $contextKey];
        }
        foreach ($this->partialDependencies as $key => $path) { $this->addPartialAliases($key, $path); }
        $byType = [];
        foreach ($this->partials as $key => $path) {
            $split = strrpos($key, '/');
            $type = substr($key, 0, $split);
            $byType[$type][] = $path;
            $this->addPartialAliases($key, $path);
        }
        foreach ($byType as $type => $paths) {
            if (count($paths) === 1) {
                // Ordinary public partial calls use default; their selected option
                // is already fixed by this version's generated partial source.
                $this->addPartialAliases($type . '/default', $paths[0]);
            }
        }
    }

    private function addPartialAliases(string $key, string $path): void
    {
        $source = $this->sources[$path];
        $logical = 'theme/' . $this->identity->area . '/partials/' . $key . '.phtml';
        foreach ([$logical, 'Weline_Theme::' . $logical] as $alias) {
            $this->sourceBindings[$alias] = [$source['bytes'], $source['origin'], $this->identity->cacheKey()];
        }
    }

    /** Parse lazily for this request, snapshot and actual normalizer object. Arrays return by value. */
    public function parseSourceMeta(array $source): array
    {
        $normalizer = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(ParamDefinitionNormalizerInterface::class);
        $cacheKey = self::REQUEST_KEY . '.meta.' . $this->installationToken;
        $memo = RequestContext::isInitialized() && $normalizer instanceof ParamDefinitionNormalizerInterface
            ? RequestContext::get($cacheKey) : null;
        if ($memo instanceof \WeakMap && isset($memo[$normalizer])) {
            foreach ($memo[$normalizer] as $entry) {
                if ($entry['origin'] === $source['origin'] && $entry['bytes'] === $source['bytes']) {
                    return $entry['parsed'];
                }
            }
        }
        $parsed = TraceGate::isArmed()
            ? TraceGate::measurePhase('theme.source.meta_parse', static fn(): array => ComponentMetaParser::parseContent($source['bytes'], $source['origin']))
            : ComponentMetaParser::parseContent($source['bytes'], $source['origin']);
        if (RequestContext::isInitialized() && $normalizer instanceof ParamDefinitionNormalizerInterface) {
            $memo ??= new \WeakMap();
            $entries = $memo[$normalizer] ?? [];
            $entries[] = ['origin' => $source['origin'], 'bytes' => $source['bytes'], 'parsed' => $parsed];
            $memo[$normalizer] = $entries;
            RequestContext::set($cacheKey, $memo);
        }
        return $parsed;
    }

    public function pagePath(): ?string { return $this->page; }
    public function partialPath(string $type, string $option = 'default'): ?string { return $this->partials[$type . '/' . $option] ?? null; }
    public function selectedPartialPath(string $type, string $option = 'default'): ?string
    {
        if (isset($this->partials[$type . '/' . $option])) { return $this->partials[$type . '/' . $option]; }
        if ($option !== 'default') { return null; }
        $matches = array_filter($this->partials, static fn(string $key): bool => dirname($key) === $type, ARRAY_FILTER_USE_KEY);
        return count($matches) === 1 ? reset($matches) : null;
    }
    public function fingerprint(): string { return $this->fingerprint; }
    public function source(string $path): ?array
    {
        if (isset($this->sources[$path])) { return $this->sources[$path]; }
        if (isset($this->pageDependencies[$path])) { return $this->sources[$this->pageDependencies[$path]]; }
        foreach ($this->sources as $source) { if ($source['origin'] === $path) { return $source; } }
        $logical = preg_replace('/^Weline_Theme::/', '', $path);
        $prefix = 'theme/' . $this->identity->area . '/partials/';
        if (str_starts_with($logical, $prefix) && str_ends_with($logical, '.phtml')) {
            $key = substr($logical, strlen($prefix), -6);
            $target = $this->partials[$key] ?? $this->partialDependencies[$key] ?? null;
            if ($target === null && str_ends_with($key, '/default')) { $target = $this->selectedPartialPath(substr($key, 0, -8)); }
            return $target === null ? null : ($this->sources[$target] ?? null);
        }
        return null;
    }
    public static function current(): ?self
    {
        $snapshot = RequestContext::get(self::REQUEST_KEY);
        return $snapshot instanceof self ? $snapshot : null;
    }
}
