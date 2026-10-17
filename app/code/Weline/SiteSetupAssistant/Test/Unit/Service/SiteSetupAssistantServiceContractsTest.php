<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllSiteSetupAssistantServiceContractsPass().
 */
final class SiteSetupAssistantServiceContractsTest extends TestCase
{
    public function testAllSiteSetupAssistantServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'MigratedSetupTaskProviders::testFormerHardcodedTipsHaveModuleProviders' => function () {
            $this->assert_MigratedSetupTaskProviders_testFormerHardcodedTipsHaveModuleProviders();
        },
            'SetupTaskProvider::testAbstractProviderAndCollectorReplaceLegacyStatusOverride' => function () {
            $this->assert_SetupTaskProvider_testAbstractProviderAndCollectorReplaceLegacyStatusOverride();
        },
        ];
    }

private function assert_MigratedSetupTaskProviders_testFormerHardcodedTipsHaveModuleProviders(): void
{
    $weline = '/Users/weline/Project/Official/框架/app/code/Weline';
    $expected = ['Captcha' => 'CaptchaSetupTaskProvider', 'Customer' => 'CustomerSocialLoginSetupTaskProvider', 'Social' => 'SocialPlatformSetupTaskProvider', 'Payment' => 'PaymentPaypalSetupTaskProvider', 'Seo' => 'SeoWebsiteAccountSetupTaskProvider', 'Shipping' => 'ShippingSetupTaskProvider', 'Currency' => 'CurrencySetupTaskProvider', 'Visitor' => 'VisitorPixelSetupTaskProvider', 'CustomerService' => 'CustomerServiceSetupTaskProvider', 'Cdn' => 'CdnCloudflareSetupTaskProvider', 'Websites' => 'WebsitesDomainHttpsSetupTaskProvider', 'Smtp' => 'SmtpSetupTaskProvider', 'Mail' => 'MailSetupTaskProvider', 'Tax' => 'TaxSetupTaskProvider', 'Ops' => 'OpsSetupTaskProvider', 'CacheManager' => 'CacheManagerSetupTaskProvider'];
    foreach ($expected as $module => $class) {
        $path = $weline . '/' . $module . '/extends/module/Weline_SiteSetupAssistant/SetupTask/' . $class . '.php';
        self::assertFileExists($path, $module . ' provider missing');
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('extends AbstractSetupTaskProvider', $src);
        $extends = (string) file_get_contents($weline . '/' . $module . '/extends.php');
        self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
        self::assertStringContainsString($class . '::class', $extends);
    }
    $float = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SiteSetupAssistant' . '/view/templates/backend/widgets/site-setup-assistant-float.phtml');
    self::assertStringContainsString('SetupTaskCollector', $float);
    self::assertStringContainsString('data-ssa-group="performance"', $float);
    self::assertStringContainsString('性能优化', $float);
    self::assertStringNotContainsString("'code' => 'captcha'", $float);
    self::assertStringNotContainsString("'code' => 'paypal'", $float);
    self::assertStringNotContainsString("'code' => 'domain_https'", $float);
    $cacheProvider = (string) file_get_contents($weline . '/CacheManager/extends/module/Weline_SiteSetupAssistant/SetupTask/CacheManagerSetupTaskProvider.php');
    self::assertStringContainsString('fpc_full_page_cache', $cacheProvider);
    self::assertStringContainsString('fpc_dev_auto_pierce', $cacheProvider);
    self::assertStringContainsString('GROUP_PERFORMANCE', $cacheProvider);
}

private function assert_SetupTaskProvider_testAbstractProviderAndCollectorReplaceLegacyStatusOverride(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/SiteSetupAssistant';
    $interface = (string) file_get_contents($root . '/Api/SetupTaskProviderInterface.php');
    $abstract = (string) file_get_contents($root . '/Api/AbstractSetupTaskProvider.php');
    $collector = (string) file_get_contents($root . '/Service/SetupTaskCollector.php');
    $extends = (string) file_get_contents($root . '/extends.php');
    $float = (string) file_get_contents($root . '/view/templates/backend/widgets/site-setup-assistant-float.phtml');
    self::assertFileDoesNotExist($root . '/Api/SetupTaskStatusProviderInterface.php');
    self::assertFileDoesNotExist($root . '/Service/SetupTaskStatusCollector.php');
    self::assertStringContainsString('interface SetupTaskProviderInterface', $interface);
    self::assertStringContainsString('GROUP_PERFORMANCE', $interface);
    self::assertStringContainsString('provideTasks', $interface);
    self::assertStringContainsString('abstract class AbstractSetupTaskProvider', $abstract);
    self::assertStringContainsString("'group'", $abstract);
    self::assertStringContainsString('scope_kind', $collector);
    self::assertStringContainsString('implements SetupTaskProviderInterface', $abstract);
    self::assertStringContainsString('class SetupTaskCollector', $collector);
    self::assertStringContainsString('collectGlobalOverview', $collector);
    self::assertStringContainsString('summarizeIncompleteSites', $collector);
    self::assertStringContainsString('site_coverage', $collector);
    self::assertStringContainsString('SetupTaskProviderInterface::class', $collector);
    self::assertStringContainsString("'todo' => 0", $collector);
    self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
    self::assertStringContainsString('resolveConfigProvenance', $abstract);
    self::assertStringContainsString('isEffectivelyEnabled', $abstract);
    self::assertStringContainsString('anyConfigEffectivelyTruthy', $abstract);
    self::assertStringContainsString('SetupTaskCollector', $float);
    self::assertStringContainsString('collectGlobalOverview', $float);
    self::assertStringContainsString('summarizeIncompleteSites', $float);
    self::assertStringNotContainsString("'code' => 'captcha'", $float);
    self::assertStringNotContainsString("'code' => 'paypal'", $float);
    self::assertStringNotContainsString('SetupTaskStatusCollector', $float);
    self::assertStringNotContainsString('$page(\'smtp/backend/config\')', $float);
    self::assertStringNotContainsString('dashboard_website_id', $float);
}
}
