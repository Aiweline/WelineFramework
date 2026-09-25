<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request\RequestFilter;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 6) . DIRECTORY_SEPARATOR);
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('CLI')) {
    define('CLI', true);
}
if (!defined('PROD')) {
    define('PROD', false);
}
if (!defined('DEV')) {
    define('DEV', true);
}
if (!defined('ENV_TEST')) {
    define('ENV_TEST', true);
}
if (!defined('SANDBOX')) {
    define('SANDBOX', true);
}
require_once BP . 'app/autoload.php';
if (is_file(BP . 'app/code/Weline/Framework/func_log.php')) {
    require_once BP . 'app/code/Weline/Framework/func_log.php';
}

final class RequestFilterSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestFilter::resetRequestStateStatic();
        try {
            Env::getInstance()->reload();
        } catch (\Throwable) {
            // Env may be unavailable in ultra-minimal bootstraps; pattern tests do not need it.
        }
    }

    public function testSerializeFilterIsDisabledByDefault(): void
    {
        Env::getInstance()->reload();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('security.request_filter.allow_php_unserialize');

        RequestFilter::filter('serialize', 'a:1:{s:2:"id";i:1;}');
    }

    public function testSerializeFilterCanBeExplicitlyReEnabled(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'request_filter' => [
                    'allow_php_unserialize' => true,
                ],
            ],
        ]);

        self::assertSame(['id' => 1], RequestFilter::filter('serialize', 'a:1:{s:2:"id";i:1;}'));
    }

    public function testStringFilterEncodesArrayWithoutWarning(): void
    {
        self::assertSame('{"a":1,"b":2}', RequestFilter::filter('string', ['a' => 1, 'b' => 2]));
        self::assertSame('', RequestFilter::filter('string', null));
        self::assertSame('1', RequestFilter::filter('string', true));
        self::assertSame('', RequestFilter::filter('string', false));
        self::assertSame('plain', RequestFilter::filter('string', 'plain'));
    }

    public function testEnglishProductTitlesWithApostropheAreNotAttackPayloads(): void
    {
        $filter = RequestFilter::getInstance();
        $filter->resetRequestState();

        self::assertFalse($filter->matchesAttackPattern(
            'get',
            "Yueya Nishang Si Wu Xie Jin-Style Cross-Collar Waist-High Ruqun Women's Hanfu Full Set"
        ));
        self::assertFalse($filter->matchesAttackPattern(
            'get',
            "Yueya Nishang Chang'an Memory Original Authentic Tang-Style Women's Summer Embroidered Restored Chest-High Ruqun Printed Bargain"
        ));
        self::assertFalse($filter->matchesAttackPattern('get', "O'Brien"));
        self::assertFalse($filter->matchesAttackPattern('get', 'hf-s-qian-lu12-po6-mi-bai-d3733f9'));
    }

    public function testRealSqlInjectionPayloadsStillMatch(): void
    {
        $filter = RequestFilter::getInstance();
        $filter->resetRequestState();

        self::assertTrue($filter->matchesAttackPattern('get', "1' OR '1'='1"));
        self::assertTrue($filter->matchesAttackPattern('get', 'union select user from mysql.user'));
        self::assertTrue($filter->matchesAttackPattern('get', '<script>alert(1)</script>'));
        self::assertTrue($filter->matchesAttackPattern('get', 'select id from users'));
    }

    public function testGetAttackPatternConstantOmitsBareApostrophe(): void
    {
        self::assertStringNotContainsString("'|", RequestFilter::GET_ATTACK_PATTERN);
        self::assertDoesNotMatchRegularExpression(
            '/^\'/',
            RequestFilter::GET_ATTACK_PATTERN
        );
    }
}
