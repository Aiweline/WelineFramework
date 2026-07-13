<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Compilation\ServiceProviderRegistry;

final class LocalizationProviderRegistryTest extends TestCase
{
    private string $registryFile;

    protected function setUp(): void
    {
        $this->registryFile = sys_get_temp_dir() . '/weline-localization-providers-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->registryFile)) {
            unlink($this->registryFile);
        }
    }

    public function testNarrowProviderWinsAndGlobalProviderAnswersExistence(): void
    {
        $registry = $this->registry([
            'localization_provider.global' => GlobalLocalizationProvider::class,
            'localization_provider.website' => WebsiteLocalizationProvider::class,
        ]);

        self::assertSame(['zh_Hans_CN'], $registry->preferredLanguageCodes());
        self::assertSame(['CNY'], $registry->preferredCurrencyCodes());
        self::assertTrue($registry->supportsLanguage('en_US'));
        self::assertTrue($registry->supportsCurrency('USD'));
        self::assertFalse($registry->supportsCurrency('EUR'));
    }

    public function testWorksWhenOptionalNarrowProviderIsAbsent(): void
    {
        $registry = $this->registry([
            'localization_provider.global' => GlobalLocalizationProvider::class,
        ]);

        self::assertSame(['en_US'], $registry->preferredLanguageCodes());
        self::assertSame(['USD'], $registry->preferredCurrencyCodes());
    }

    /** @param array<string, class-string> $provides */
    private function registry(array $provides): LocalizationProviderRegistry
    {
        $compiled = [
            'format' => 1,
            'order' => ['Module_Localization'],
            'modules' => ['Module_Localization' => ['provides' => $provides]],
        ];
        file_put_contents($this->registryFile, '<?php return ' . var_export($compiled, true) . ';');

        return new LocalizationProviderRegistry(new ServiceProviderRegistry($this->registryFile));
    }
}

final class WebsiteLocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int { return 100; }
    public function languageCodes(): array { return ['zh-Hans-CN']; }
    public function currencyCodes(): array { return ['cny']; }
    public function supportsLanguage(string $code): ?bool { return null; }
    public function supportsCurrency(string $code): ?bool { return null; }
}

final class GlobalLocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int { return 10; }
    public function languageCodes(): array { return ['en_US']; }
    public function currencyCodes(): array { return ['USD']; }
    public function supportsLanguage(string $code): ?bool { return $code === 'en_US'; }
    public function supportsCurrency(string $code): ?bool { return $code === 'USD'; }
}
