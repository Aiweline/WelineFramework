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
 * A layout entity captured before the custom theme shipped its own chrome must
 * not keep painting the default Theme header/footer.
 */
final class ThemeChromeForeignSourceRebaseTest extends TestCase
{
    public function testHeaderCapturedFromDefaultThemeRebasesToActiveTheme(): void
    {
        $themePath = BP . '/app/design/Weline/hanfu';
        $themeHeader = $themePath . '/frontend/partials/header/default.phtml';
        self::assertFileExists($themeHeader);

        $theme = new class($themePath) extends WelineTheme {
            public function __construct(private string $path) {}
            public function getId(mixed $default = 0): int { return 3; }
            public function getPath(): string { return rtrim($this->path, '/\\') . DS; }
            public function getThemeChain(): array { return [$this]; }
        };

        $context = new class($theme) extends ThemeContextService {
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

        $directoryResolver = new class($theme) extends ThemeDirectoryResolver {
            public function __construct(private WelineTheme $theme) {}
            public function getThemeChain(?WelineTheme $theme = null, ?string $area = null): array
            {
                return [$this->theme];
            }
            public function resolveThemeTemplatePath(string $modulePath, WelineTheme $theme): string
            {
                if (!str_starts_with($modulePath, 'Weline_Theme::theme/frontend/')) {
                    return $modulePath;
                }
                $relative = substr($modulePath, strlen('Weline_Theme::theme/frontend/'));
                $candidate = rtrim($this->theme->getPath(), '/\\') . '/frontend/' . $relative;

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
            $origin = BP . '/app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml';
            $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 1901, 'formal', 2);
            $metadata = [
                'origin' => $origin,
                'identity' => $identity->toArray(),
                'resource_type' => 'partial',
                'partial_type' => 'header',
                'partial_option' => 'default',
            ];
            $bytes = '<?php /* weline-source:' . base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)) . ' */ ?>DEFAULT-THEME-HEADER';
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
            self::assertSame(realpath($themeHeader), realpath($path), 'resolved path=' . $path);
            self::assertStringNotContainsString('app/code/Weline/Theme/view/theme', str_replace('\\', '/', $path));
        } finally {
            ObjectManager::setInstance(ThemeContextService::class, $previousContext);
            ObjectManager::setInstance(ThemeDirectoryResolver::class, $previousResolver);
            ThemeLayoutSourceSnapshot::current()?->uninstall(Template::getInstance());
            Context::leave();
        }
    }
}
