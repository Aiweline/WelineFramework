<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\App\State;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

class BackendPersonalLanguagePriorityTest extends TestCase
{
    private string $registryFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        WelineEnv::set('area', 'backend', 'backend personal language priority');
        WelineEnv::set('website.language', 'en_US', 'backend personal language priority');
        WelineEnv::set('backend_default_language', 'zh_Hans_CN', 'backend personal language priority');
        WelineEnv::set('backend_personal_language', '', 'backend personal language priority');
        $this->installBackendTierRegistry();
        State::resetLangLocalCache();
    }

    protected function tearDown(): void
    {
        if ($this->registryFile !== '' && is_file($this->registryFile)) {
            unlink($this->registryFile);
        }
        parent::tearDown();
    }

    public function testPersonalRuntimeOverridesGlobalBackendDefault(): void
    {
        WelineEnv::set('backend_personal_language', 'fr_CA', 'backend personal language priority');
        State::resetLangLocalCache();

        self::assertSame('fr_CA', State::resolveBackendEffectiveDefaultLanguage());
        self::assertSame('zh_Hans_CN', State::resolveBackendDefaultLanguage());
        self::assertNotSame(
            State::resolveWebsiteDefaultLanguage(),
            State::resolveBackendEffectiveDefaultLanguage()
        );
    }

    public function testGlobalBackendDefaultUsedWhenNoPersonal(): void
    {
        WelineEnv::set('backend_personal_language', '', 'backend personal language priority');
        State::resetLangLocalCache();

        self::assertSame('zh_Hans_CN', State::resolveBackendEffectiveDefaultLanguage());
    }

    public function testStateDoesNotSoftPullBackendPersonalLanguageFqcn(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/App/State.php');
        self::assertStringNotContainsString('BackendPersonalLanguage::', $src);
        self::assertStringContainsString('preferredDefaultLanguageWithMinPriority', $src);
    }

    private function installBackendTierRegistry(): void
    {
        $this->registryFile = sys_get_temp_dir() . '/weline-backend-loc-' . bin2hex(random_bytes(6)) . '.php';
        $compiled = [
            'format' => 1,
            'order' => ['Module_BackendLoc'],
            'modules' => [
                'Module_BackendLoc' => [
                    'provides' => [
                        'localization_provider.backend_tier' => BackendTierPersonalLocalizationProvider::class,
                        'localization_provider.website_noise' => WebsiteNoiseLocalizationProvider::class,
                    ],
                ],
            ],
        ];
        file_put_contents($this->registryFile, '<?php return ' . var_export($compiled, true) . ';');
        $registry = new LocalizationProviderRegistry(new ServiceProviderRegistry($this->registryFile));
        ObjectManager::setInstance(LocalizationProviderRegistry::class, $registry);
    }
}

/** @internal */
final class BackendTierPersonalLocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int
    {
        return 200;
    }

    public function languageCodes(): array
    {
        return [];
    }

    public function currencyCodes(): array
    {
        return [];
    }

    public function defaultLanguage(): ?string
    {
        $code = trim((string)WelineEnv::get('backend_personal_language', ''));

        return $code !== '' ? $code : null;
    }

    public function defaultCurrency(): ?string
    {
        return null;
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    public function installedLanguageCodes(): ?array
    {
        return null;
    }
}

/** @internal website-tier noise that must not win on backend personal path */
final class WebsiteNoiseLocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int
    {
        return 100;
    }

    public function languageCodes(): array
    {
        return ['en_US'];
    }

    public function currencyCodes(): array
    {
        return ['USD'];
    }

    public function defaultLanguage(): ?string
    {
        return 'en_US';
    }

    public function defaultCurrency(): ?string
    {
        return 'USD';
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    public function installedLanguageCodes(): ?array
    {
        return null;
    }
}
