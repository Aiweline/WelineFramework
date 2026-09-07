<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\SocialLogin\SocialLoginGuideI18nCatalog;
use Weline\Customer\Service\SocialLogin\SocialLoginGuideTranslationQueueService;

final class SocialLoginGuideI18nContractTest extends TestCase
{
    public function testCatalogAndQueueMirrorPaymentGuideDomainContract(): void
    {
        $root = dirname(__DIR__, 4);
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
        self::assertMatchesRegularExpression('/Google 登录指南,"?Google sign-in guide"?/', $enCsv);
        self::assertMatchesRegularExpression('/开始前准备,"?Before you start"?/', $enCsv);
        self::assertStringNotContainsString('Google 登录指南,Google 登录指南', $enCsv);
        self::assertStringNotContainsString('"Google 登录指南","Google 登录指南"', $enCsv);
    }

    public function testGuideTemplatesUseLangTagsNotPhpTranslate(): void
    {
        $root = dirname(__DIR__, 4) . '/view/templates/frontend/guide/social-login';
        foreach (['google/guide.phtml', 'google/policy.phtml', 'facebook/guide.phtml', 'index.phtml'] as $rel) {
            $src = (string) file_get_contents($root . '/' . $rel);
            self::assertStringContainsString('<lang>', $src);
            self::assertStringNotContainsString("<?= __('", $src);
        }

        self::assertSame('zh_Hans_CN', SocialLoginGuideI18nCatalog::SOURCE_LOCALE);
        self::assertSame('Weline_Customer', SocialLoginGuideI18nCatalog::HUB_MODULE);
    }
}
