<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Block\Partials;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeDirectoryResolver;

/**
 * 自定义主题(hanfu)覆写了 chrome 时，即使固化代次是从**父主题**继承来的
 * （子主题没有覆写该布局，例如 blog），也不能继续用父主题捕获的旧 header/footer。
 *
 * 规则：覆写按文件粒度生效——活动主题自己提供了 header/footer，就用活动主题的。
 * 回归场景：changanhanfu /blog header 仍是 Default 主题旧版（2026-10-27 线上实测）。
 */
final class ThemeChromeParentSourceRebaseTest extends TestCase
{
    public function testHeaderCapturedFromParentThemeRebasesToActiveThemeOverride(): void
    {
        $activePath = BP . '/app/design/Weline/hanfu';
        $activeHeader = $activePath . '/frontend/partials/header/default.phtml';
        self::assertFileExists($activeHeader);

        $parentPath = BP . '/app/code/Weline/Theme/view/theme';
        $parentHeader = $parentPath . '/frontend/partials/header/default.phtml';
        self::assertFileExists($parentHeader);

        $activeTheme = new class($activePath) extends WelineTheme {
            public function __construct(private string $path) {}
            public function getId(mixed $default = 0): int { return 2; }
            public function getPath(): string { return rtrim($this->path, '/\\') . DS; }
            public function getThemeChain(): array { return [$this]; }
        };
        $parentTheme = new class($parentPath) extends WelineTheme {
            public function __construct(private string $path) {}
            public function getId(mixed $default = 0): int { return 1; }
            public function getPath(): string { return rtrim($this->path, '/\\') . DS; }
            public function getThemeChain(): array { return [$this]; }
        };

        $context = new class($activeTheme) extends ThemeContextService {
            public function __construct(private WelineTheme $theme) {}
            public function resolveTheme(?string $area = null, ?object $theme = null, bool $allowPreview = true): ?WelineTheme
            {
                return $this->theme;
            }
            public function resolveCurrentScope(string $area, ?string $scopeParam = null): string
            {
                return 'default';
            }
        };

        $directoryResolver = new class($activeTheme, $parentTheme) extends ThemeDirectoryResolver {
            public function __construct(private WelineTheme $active, private WelineTheme $parent) {}
            // 真实链路：活动主题在前，父主题在后（Default 主题是 hanfu 的 parent_id=1）。
            public function getThemeChain(?WelineTheme $theme = null, ?string $area = null): array
            {
                return [$this->active, $this->parent];
            }
            public function resolveThemeTemplatePath(string $modulePath, WelineTheme $theme): string
            {
                if (!str_starts_with($modulePath, 'Weline_Theme::theme/frontend/')) {
                    return $modulePath;
                }
                $relative = substr($modulePath, strlen('Weline_Theme::theme/frontend/'));
                $candidate = rtrim($theme->getPath(), '/\\') . '/frontend/' . $relative;

                return is_file($candidate) ? $candidate : $modulePath;
            }
        };

        $previousContext = ObjectManager::getInstance(ThemeContextService::class);
        $previousResolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        ObjectManager::setInstance(ThemeContextService::class, $context);
        ObjectManager::setInstance(ThemeDirectoryResolver::class, $directoryResolver);

        Context::enter(new Context());
        RequestContext::init();
        try {
            $origin = $parentTheme->getPath() . 'frontend/partials/header/default.phtml';
            // 固化代次从父主题继承：identity 主题 = 父主题(1)。
            $identity = new ThemeVersionIdentity(1, 'default.default.default', 'normal', 'frontend', 77, 'formal', 2);
            $metadata = [
                'origin' => $origin,
                'identity' => $identity->toArray(),
                'resource_type' => 'partial',
                'partial_type' => 'header',
                'partial_option' => 'default',
            ];
            $bytes = '<?php /* weline-source:' . base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)) . ' */ ?>PARENT-THEME-HEADER';
            $snapshot = ThemeLayoutSourceSnapshot::fromCandidates(
                $identity,
                '',
                [$origin . '.derived.phtml' => $bytes]
            );
            $snapshot->install(Template::getInstance());

            $block = new Partials();
            $block->__init();
            $path = (string)$block->getPartialsPath('frontend', 'header', 'default');

            self::assertNotFalse(realpath($path), 'resolved path=' . $path);
            self::assertSame(
                realpath($activeHeader),
                realpath($path),
                '活动主题已覆写 header 时，必须用活动主题覆写文件，实际 resolved=' . $path
            );
            self::assertStringNotContainsString(
                'app/code/Weline/Theme/view/theme',
                str_replace('\\', '/', $path)
            );
        } finally {
            ObjectManager::setInstance(ThemeContextService::class, $previousContext);
            ObjectManager::setInstance(ThemeDirectoryResolver::class, $previousResolver);
            ThemeLayoutSourceSnapshot::current()?->uninstall(Template::getInstance());
            Context::leave();
        }
    }
}
