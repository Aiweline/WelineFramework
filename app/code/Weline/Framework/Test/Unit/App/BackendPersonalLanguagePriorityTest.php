<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;

class BackendPersonalLanguagePriorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WelineEnv::set('area', 'backend', 'backend personal language priority');
        WelineEnv::set('website.language', 'en_US', 'backend personal language priority');
        WelineEnv::set('backend_default_language', 'zh_Hans_CN', 'backend personal language priority');
        WelineEnv::set('backend_personal_language', '', 'backend personal language priority');
        State::resetLangLocalCache();
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
}
