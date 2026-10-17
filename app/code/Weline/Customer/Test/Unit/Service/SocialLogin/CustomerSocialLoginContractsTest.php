<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\FacebookProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\GoogleProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\InstagramProvider;
use Weline\Customer\Extends\Module\Weline_Framework\Security\Csp\SocialLoginVendorsCsp;
use Weline\Customer\Interface\SocialLoginProviderInterface;
use Weline\Customer\Service\SocialLogin\AbstractSocialLoginProvider;
use Weline\Customer\Service\SocialLogin\SocialLoginGuideI18nCatalog;
use Weline\Customer\Service\SocialLogin\SocialLoginGuideTranslationQueueService;
use Weline\Customer\Service\SocialLogin\SocialLoginOAuthService;
use Weline\Customer\Service\SocialLogin\SocialLoginProviderCatalog;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerSocialLoginContractsPass().
 */
final class CustomerSocialLoginContractsTest extends TestCase
{
    public function testAllCustomerSocialLoginContractsPass(): void
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
            'SocialLoginGuideI18n::testCatalogAndQueueMirrorPaymentGuideDomainContract' => function () {
            $this->assert_SocialLoginGuideI18n_testCatalogAndQueueMirrorPaymentGuideDomainContract();
        },
            'SocialLoginGuideI18n::testGuideTemplatePhrasesHaveNonChineseEnUsTranslations' => function () {
            $this->assert_SocialLoginGuideI18n_testGuideTemplatePhrasesHaveNonChineseEnUsTranslations();
        },
            'SocialLoginGuideI18n::testGuideTemplatesUseLangTagsNotPhpTranslate' => function () {
            $this->assert_SocialLoginGuideI18n_testGuideTemplatesUseLangTagsNotPhpTranslate();
        },
            'SocialLoginOAuthIntent::testOAuthServiceDeclaresLoginAndBindIntentsAndPendingHelpers' => function () {
            $this->assert_SocialLoginOAuthIntent_testOAuthServiceDeclaresLoginAndBindIntentsAndPendingHelpers();
        },
            'SocialLoginOAuthIntent::testLinkerExposesBindCreateAndUnbindApis' => function () {
            $this->assert_SocialLoginOAuthIntent_testLinkerExposesBindCreateAndUnbindApis();
        },
            'SocialLoginProviderCspDirectives::testBuiltInProvidersDeclareCspDirectives' => function () {
            $this->assert_SocialLoginProviderCspDirectives_testBuiltInProvidersDeclareCspDirectives();
        },
            'SocialLoginProviderCspDirectives::testSocialLoginVendorsCspAggregatesProviders' => function () {
            $this->assert_SocialLoginProviderCspDirectives_testSocialLoginVendorsCspAggregatesProviders();
        },
            'SocialLoginProviderCspDirectives::testInterfaceAndAbstractDeclareCspDirectives' => function () {
            $this->assert_SocialLoginProviderCspDirectives_testInterfaceAndAbstractDeclareCspDirectives();
        },
            'SocialLoginProviderExtends::testExtendsPointDeclaresSocialLoginProvider' => function () {
            $this->assert_SocialLoginProviderExtends_testExtendsPointDeclaresSocialLoginProvider();
        },
            'SocialLoginProviderExtends::testBuiltinProvidersLiveUnderExtendsPathAndImplementInterface' => function () {
            $this->assert_SocialLoginProviderExtends_testBuiltinProvidersLiveUnderExtendsPathAndImplementInterface();
        },
            'SocialLoginProviderExtends::testInstagramProviderUsesInstagramLoginOAuthNotBasicDisplay' => function () {
            $this->assert_SocialLoginProviderExtends_testInstagramProviderUsesInstagramLoginOAuthNotBasicDisplay();
        },
            'SocialLoginQuickAuth::testQuickAuthServiceAndEndpointsExist' => function () {
            $this->assert_SocialLoginQuickAuth_testQuickAuthServiceAndEndpointsExist();
        },
        ];
    }

private function assert_SocialLoginGuideI18n_testCatalogAndQueueMirrorPaymentGuideDomainContract(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
    $catalog = $root . '/Service/SocialLogin/SocialLoginGuideI18nCatalog.php';
    $queue = $root . '/Service/SocialLogin/SocialLoginGuideTranslationQueueService.php';
    $service = $root . '/Service/SocialLogin/SocialLoginGuideI18nService.php';
    $auditCli = $root . '/Console/Customer/Guide/SocialLogin/I18nAudit.php';
    $translateCli = $root . '/Console/Customer/Guide/SocialLogin/I18nTranslate.php';
    self::assertFileExists($catalog);
    self::assertFileExists($queue);
    self::assertFileExists($service);
    self::assertFileExists($auditCli);
    self::assertFileExists($translateCli);
    $queueSrc = (string) file_get_contents($queue);
    self::assertSame('social_login_guide', SocialLoginGuideTranslationQueueService::DOMAIN);
    self::assertStringContainsString("DOMAIN . ':all'", $queueSrc);
    self::assertStringContainsString('社媒登录指南 AI 翻译', $queueSrc);
    $auditSrc = (string) file_get_contents($auditCli);
    self::assertStringContainsString('customer:guide:social-login:i18n-audit', $auditSrc);
    $translateSrc = (string) file_get_contents($translateCli);
    self::assertStringContainsString('customer:guide:social-login:i18n-translate', $translateSrc);
    $i18nQueue = dirname($root) . '/I18n/Queue/AiTranslateQueue.php';
    $i18nSrc = (string) file_get_contents($i18nQueue);
    self::assertStringContainsString("'social_login_guide'", $i18nSrc);
    $enCsv = (string) file_get_contents($root . '/i18n/en_US.csv');
    self::assertMatchesRegularExpression('/"?Google 登录指南"?,"?Google sign-in guide"?/', $enCsv);
    self::assertMatchesRegularExpression('/"?开始前准备"?,"?Before you start"?/', $enCsv);
    self::assertStringNotContainsString('Google 登录指南,Google 登录指南', $enCsv);
    self::assertStringNotContainsString('"Google 登录指南","Google 登录指南"', $enCsv);
    self::assertMatchesRegularExpression('/"?用户数据删除"?,"?User data deletion"?/', $enCsv);
    self::assertStringNotContainsString('用户数据删除,用户数据删除', $enCsv);
    self::assertStringNotContainsString('"用户数据删除","用户数据删除"', $enCsv);
}

private function assert_SocialLoginGuideI18n_testGuideTemplatePhrasesHaveNonChineseEnUsTranslations(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
    $enPath = $moduleRoot . '/i18n/en_US.csv';
    $translations = [];
    $fp = fopen($enPath, 'r');
    self::assertNotFalse($fp);
    while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        if (count($row) >= 2) {
            $translations[$row[0]] = $row[1];
        }
    }
    fclose($fp);
    $files = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleRoot . '/view/templates/frontend/guide/social-login'));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'phtml') {
            $files[] = $file->getPathname();
        }
    }
    $bad = [];
    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        if (!preg_match_all('/<lang(?:\s[^>]*)?>(.*?)<\/lang>/su', $content, $m)) {
            continue;
        }
        foreach ($m[1] as $phrase) {
            if (!preg_match('/\p{Han}/u', $phrase)) {
                continue;
            }
            $trans = $translations[$phrase] ?? null;
            if ($trans === null || preg_match('/\p{Han}/u', $trans)) {
                $bad[] = $phrase;
            }
        }
    }
    self::assertSame([], $bad, 'Social-login guide phrases still have Chinese placeholders in en_US.csv');
}

private function assert_SocialLoginGuideI18n_testGuideTemplatesUseLangTagsNotPhpTranslate(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/guide/social-login';
    foreach (['google/guide.phtml', 'google/policy.phtml', 'facebook/guide.phtml', 'index.phtml'] as $rel) {
        $src = (string) file_get_contents($root . '/' . $rel);
        self::assertStringContainsString('<lang>', $src);
        self::assertStringNotContainsString("<?= __('", $src);
    }
    self::assertSame('zh_Hans_CN', SocialLoginGuideI18nCatalog::SOURCE_LOCALE);
    self::assertSame('Weline_Customer', SocialLoginGuideI18nCatalog::HUB_MODULE);
}

private function assert_SocialLoginOAuthIntent_testOAuthServiceDeclaresLoginAndBindIntentsAndPendingHelpers(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Service/SocialLogin/SocialLoginOAuthService.php');
    self::assertStringContainsString("INTENT_LOGIN = 'login'", $src);
    self::assertStringContainsString("INTENT_BIND = 'bind'", $src);
    self::assertStringContainsString('function storePending', $src);
    self::assertStringContainsString('function peekPending', $src);
    self::assertStringContainsString('function consumePending', $src);
    self::assertStringContainsString('SocialLoginTransientStore', $src);
    self::assertStringContainsString("'intent' => \$intent", $src);
    self::assertStringContainsString('getOriginUrl', $src);
    self::assertStringContainsString('withoutStorefrontLocalizationPrefix', $src);
    self::assertStringContainsString("\$provider === 'google'", $src);
    self::assertStringContainsString("'locale_prefix'", $src);
    self::assertStringContainsString('firstNonEmpty(', $src);
    self::assertStringContainsString('$returnUrl', $src);
    self::assertSame(SocialLoginOAuthService::INTENT_LOGIN, 'login');
    self::assertSame(SocialLoginOAuthService::INTENT_BIND, 'bind');
}

private function assert_SocialLoginOAuthIntent_testLinkerExposesBindCreateAndUnbindApis(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Service/SocialLogin/SocialLoginAccountLinker.php');
    self::assertStringContainsString('function findBoundIdentity', $src);
    self::assertStringContainsString('function createFromProfile', $src);
    self::assertStringContainsString('function bindProfileToCustomer', $src);
    self::assertStringContainsString('function authenticateLocal', $src);
    self::assertStringContainsString('function unbind', $src);
    self::assertStringContainsString('function listProviderStatusForCustomer', $src);
}

private function assert_SocialLoginProviderCspDirectives_testBuiltInProvidersDeclareCspDirectives(): void
{
    $google = (new GoogleProvider())->cspDirectives();
    self::assertContains('https://accounts.google.com', $google['script-src'] ?? []);
    self::assertContains('https://apis.google.com', $google['script-src'] ?? []);
    self::assertContains('https://accounts.google.com', $google['frame-src'] ?? []);
    self::assertContains('https://oauth2.googleapis.com', $google['connect-src'] ?? []);
    self::assertContains('https://openidconnect.googleapis.com', $google['connect-src'] ?? []);
    $facebook = (new FacebookProvider())->cspDirectives();
    self::assertContains('https://connect.facebook.net', $facebook['script-src'] ?? []);
    self::assertContains('https://www.facebook.com', $facebook['frame-src'] ?? []);
    self::assertContains('https://graph.facebook.com', $facebook['connect-src'] ?? []);
    $instagram = (new InstagramProvider())->cspDirectives();
    self::assertContains('https://www.instagram.com', $instagram['frame-src'] ?? []);
    self::assertContains('https://api.instagram.com', $instagram['connect-src'] ?? []);
    self::assertContains('https://graph.instagram.com', $instagram['connect-src'] ?? []);
}

private function assert_SocialLoginProviderCspDirectives_testSocialLoginVendorsCspAggregatesProviders(): void
{
    $contribution = (new SocialLoginVendorsCsp(static fn(): array => [new GoogleProvider(), new FacebookProvider(), new InstagramProvider()]))->contribution();
    $script = $contribution->directives['script-src'] ?? [];
    $frame = $contribution->directives['frame-src'] ?? [];
    $connect = $contribution->directives['connect-src'] ?? [];
    self::assertContains('https://accounts.google.com', $script);
    self::assertContains('https://connect.facebook.net', $script);
    self::assertContains('https://www.instagram.com', $frame);
    self::assertContains('https://graph.instagram.com', $connect);
}

private function assert_SocialLoginProviderCspDirectives_testInterfaceAndAbstractDeclareCspDirectives(): void
{
    $iface = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Interface/SocialLoginProviderInterface.php');
    $abstract = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Service/SocialLogin/AbstractSocialLoginProvider.php');
    self::assertStringContainsString('function cspDirectives(): array', $iface);
    self::assertStringContainsString('function cspDirectives(): array', $abstract);
}

private function assert_SocialLoginProviderExtends_testExtendsPointDeclaresSocialLoginProvider(): void
{
    $extends = include '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends.php';
    self::assertIsArray($extends);
    self::assertSame('module', $extends['type'] ?? null);
    self::assertArrayHasKey('SocialLoginProvider', $extends['extends'] ?? []);
    $point = $extends['extends']['SocialLoginProvider'];
    self::assertSame('extends/module/Weline_Customer/SocialLoginProvider', $point['path'] ?? null);
    self::assertSame(SocialLoginProviderInterface::class, $point['interface'] ?? null);
    self::assertTrue((bool) ($point['multiple'] ?? false));
}

private function assert_SocialLoginProviderExtends_testBuiltinProvidersLiveUnderExtendsPathAndImplementInterface(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Customer/SocialLoginProvider';
    foreach (['GoogleProvider.php', 'FacebookProvider.php', 'InstagramProvider.php'] as $file) {
        self::assertFileExists($base . '/' . $file);
    }
    $providers = [new GoogleProvider(), new FacebookProvider(), new InstagramProvider()];
    foreach ($providers as $provider) {
        self::assertInstanceOf(SocialLoginProviderInterface::class, $provider);
        self::assertInstanceOf(AbstractSocialLoginProvider::class, $provider);
        self::assertNotSame('', $provider->getCode());
        self::assertNotSame('', $provider->getLabel());
        self::assertNotSame('', $provider->getSummary());
        self::assertNotSame('', $provider->getGuideTitle());
        self::assertNotSame('', $provider->getPolicyTitle());
        self::assertSame('guide', $provider->getGuideTemplateCode());
        self::assertSame('policy', $provider->getPolicyTemplateCode());
        self::assertSame('guide/social-login/' . $provider->getCode() . '/policy', $provider->getStorefrontPrivacyPolicyPath());
        self::assertSame('terms', $provider->getStorefrontTermsPath());
        self::assertSame('guide/social-login/' . $provider->getCode() . '/policy#data-deletion', $provider->getStorefrontDataDeletionPath());
        self::assertNotSame('', $provider->getBrandClass());
        self::assertStringContainsString('account-social-login__mark', $provider->getIconSvgMarkup());
        self::assertStringContainsString('https://', $provider->buildAuthorizationUrl('client-id', 'https://example.test/callback', 'state-token'));
        $guide = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/guide/social-login/' . $provider->getCode() . '/guide.phtml';
        $policy = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/guide/social-login/' . $provider->getCode() . '/policy.phtml';
        self::assertFileExists($guide);
        self::assertFileExists($policy);
    }
    $catalog = new SocialLoginProviderCatalog();
    self::assertSame(['google', 'facebook', 'instagram'], $catalog->codes());
    self::assertInstanceOf(GoogleProvider::class, $catalog->get('google'));
    self::assertInstanceOf(FacebookProvider::class, $catalog->get('facebook'));
    self::assertInstanceOf(InstagramProvider::class, $catalog->get('instagram'));
}

private function assert_SocialLoginProviderExtends_testInstagramProviderUsesInstagramLoginOAuthNotBasicDisplay(): void
{
    $provider = new InstagramProvider();
    $url = $provider->buildAuthorizationUrl('ig-app-id', 'https://example.test/customer/account/social-login/callback', 'state-token');
    self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
    self::assertStringContainsString('scope=instagram_business_basic', $url);
    self::assertStringNotContainsString('user_profile', $url);
    self::assertStringNotContainsString('user_media', $url);
    self::assertStringNotContainsString('api.instagram.com/oauth/authorize', $url);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Customer/SocialLoginProvider/InstagramProvider.php');
    self::assertStringContainsString("private const SCOPES = 'instagram_business_basic';", $src);
    self::assertStringContainsString('https://www.instagram.com/oauth/authorize', $src);
    self::assertStringNotContainsString('user_profile,user_media', $src);
}

private function assert_SocialLoginQuickAuth_testQuickAuthServiceAndEndpointsExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
    self::assertFileExists($root . '/Service/SocialLogin/SocialLoginQuickAuthService.php');
    self::assertFileExists($root . '/view/statics/js/account-social-quick.js');
    self::assertFileExists($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    $controller = (string) file_get_contents($root . '/Controller/Account/SocialLogin.php');
    self::assertStringContainsString('function postQuickGoogle', $controller);
    self::assertStringContainsString('function postQuickFacebook', $controller);
    $config = (string) file_get_contents($root . '/Service/SocialLogin/SocialLoginConfig.php');
    self::assertStringContainsString('function isQuickPromptEnabled', $config);
    $widget = (string) file_get_contents($root . '/view/templates/frontend/widgets/account-social-login.phtml');
    self::assertStringContainsString('data-social-quick-config', $widget);
    self::assertStringContainsString('type="application/json"', $widget);
    self::assertStringContainsString('account-social-quick.js', $widget);
    self::assertStringContainsString("BP . '/app/code/Weline/Customer/view/statics/js/account-social-quick.js'", $widget);
    self::assertStringContainsString('data-no-extract="true"', $widget);
    self::assertStringContainsString('data-w-social-quick-boot', $widget);
    // Theme layouts must NOT SSR One Tap — account JS + Query own it.
    $hook = (string) file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertStringContainsString('account.socialQuickPrompt', $hook);
    self::assertStringNotContainsString('data-w-component="social-login-quick-prompt"', $hook);
    self::assertStringNotContainsString('account-social-quick.js', $hook);
    self::assertStringContainsString('return;', $hook);
    $homeHook = (string) file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/homepage/body-end.phtml');
    self::assertStringContainsString('account.socialQuickPrompt', $homeHook);
    self::assertStringNotContainsString('data-social-quick-config', $homeHook);
    $query = (string) file_get_contents($root . '/extends/module/Weline_Framework/Query/AccountQueryProvider.php');
    self::assertStringContainsString("'socialQuickPrompt'", $query);
    self::assertStringContainsString('function socialQuickPrompt', $query);
    self::assertStringContainsString('quickPromptBootstrap', $query);
    $presentation = (string) file_get_contents($root . '/Service/SocialLogin/SocialLoginPresentationService.php');
    self::assertStringContainsString("'oauth'", $presentation);
    self::assertStringContainsString("'i18n'", $presentation);
    self::assertStringContainsString("__('快捷登录')", $presentation);
    self::assertStringContainsString('startUrl(\'facebook\'', $presentation);
    $modules = (string) file_get_contents($root . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('customerSocialQuick', $modules);
    self::assertStringContainsString('WelineSocialQuick', $modules);
    $js = (string) file_get_contents($root . '/view/statics/js/account-social-quick.js');
    self::assertStringContainsString('auto_select: false', $js);
    self::assertStringNotContainsString('FB.getLoginStatus', $js);
    self::assertStringNotContainsString('auto_select: true', $js);
    self::assertStringContainsString('isAccountHomePath', $js);
    self::assertStringContainsString('currentStorefrontPath', $js);
    self::assertStringContainsString('checkFrontendUserLogin', $js);
    // Single chooser: no native One Tap / FedCM auto stack beside the bar.
    self::assertStringContainsString('startChooserOnly', $js);
    self::assertStringContainsString('chooser_only', $js);
    self::assertStringContainsString('suppressed_login_widget', $js);
    self::assertStringNotContainsString('accounts.id.prompt(', $js);
    self::assertStringNotContainsString('autoPrompt: true', $js);
    self::assertStringNotContainsString('auth.statusChange', $js);
    self::assertStringNotContainsString('FB.login(', $js);
    self::assertStringContainsString('cfg.oauth', $js);
    self::assertStringContainsString('social-login/start', $js);
    self::assertStringContainsString('data-w-social-quick-fallback-ui', $js);
    self::assertStringContainsString('MOUNT_SOCIAL_QUICK', $js);
    self::assertStringContainsString('customer/social-quick', $js);
    self::assertStringContainsString('mountIntoHost', $js);
    self::assertStringContainsString('scanMountHosts', $js);
    self::assertStringContainsString('revealVisibleFallback', $js);
    self::assertStringContainsString('weline-social-quick-bar__btn--google', $js);
    self::assertStringContainsString('weline-social-quick-bar__btn--facebook', $js);
    self::assertStringContainsString('providerButtonHtml', $js);
    self::assertStringContainsString('providerIconSvg', $js);
    self::assertStringContainsString('withStorefrontLocalePrefix', $js);
    self::assertStringContainsString('cfg.locale_prefix', $js);
    self::assertStringContainsString('stripStorefrontLocalePrefix', $js);
    self::assertStringContainsString('pagePrefix', $js);
    self::assertStringContainsString('resolveProviderOauthStart', $js);
    self::assertStringContainsString("toLowerCase() === 'google'", $js);
    self::assertStringContainsString('align-items:stretch', $js);
    self::assertStringNotContainsString('accounts.id.renderButton', $js);
    self::assertStringNotContainsString('measureQuickBarActionWidth', $js);
    self::assertStringContainsString('Never call prompt()', $js);
    self::assertStringContainsString('data-social-quick-config', $js);
    self::assertStringContainsString('whenReady', $js);
    self::assertStringContainsString('data-weline-script-ready', $js);
    self::assertStringNotContainsString("s.crossOrigin = 'anonymous'", $js);
    self::assertStringContainsString("querySelector('[data-social-quick]')", $js);
    self::assertStringContainsString('social-login-quick-prompt', $js);
    self::assertStringContainsString('WelineSocialQuick', $js);
    self::assertStringContainsString('i18nLabel', $js);
    self::assertStringContainsString('cfg.i18n', $js);
    self::assertStringContainsString('startFromConfig', $js);
    self::assertStringContainsString('skipLoginCheck', $js);
    self::assertFileExists($root . '/view/hooks/Weline_Theme/frontend/layouts/homepage/body-end.phtml');
}
}
