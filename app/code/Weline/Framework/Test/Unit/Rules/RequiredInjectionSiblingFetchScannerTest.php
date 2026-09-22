<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Rules;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Rules\Frontend\RequiredInjectionSiblingFetchScanner;

final class RequiredInjectionSiblingFetchScannerTest extends TestCase
{
    public function testDetectsFetchBesideRequiredInjectionSlot(): void
    {
        $scanner = new RequiredInjectionSiblingFetchScanner();
        $index = [
            'by_code' => [
                'account-social-login' => [
                    'module' => 'Weline_Customer',
                    'slots' => ['account-login-social-providers'],
                    'template' => 'Weline_Customer::templates/frontend/widgets/account-social-login.phtml',
                ],
            ],
            'by_template_suffix' => [
                'templates/frontend/widgets/account-social-login.phtml' => 'account-social-login',
                'widgets/account-social-login.phtml' => 'account-social-login',
                'account-social-login' => 'account-social-login',
            ],
        ];

        $tmp = sys_get_temp_dir() . '/weline-sibling-fetch-' . getmypid() . '.phtml';
        file_put_contents($tmp, <<<'PHTML'
<div>
  <?php echo $this->fetch('Weline_Customer::templates/frontend/widgets/account-social-login.phtml'); ?>
  <w:slot id="account-login-social-providers" accept="account-social-login,social-login"></w:slot>
</div>
PHTML);
        try {
            $violations = $scanner->scanFile($tmp, 'Customer/view/templates/frontend/account/login.phtml', $index);
            self::assertNotSame([], $violations);
            self::assertSame(RequiredInjectionSiblingFetchScanner::TYPE_FETCH, $violations[0]['type']);
            self::assertSame('account-social-login', $violations[0]['code']);
            self::assertSame('account-login-social-providers', $violations[0]['slot']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testEmptySlotWithoutFetchIsClean(): void
    {
        $scanner = new RequiredInjectionSiblingFetchScanner();
        $index = [
            'by_code' => [
                'account-social-login' => [
                    'module' => 'Weline_Customer',
                    'slots' => ['account-login-social-providers'],
                    'template' => 'Weline_Customer::templates/frontend/widgets/account-social-login.phtml',
                ],
            ],
            'by_template_suffix' => [
                'account-social-login' => 'account-social-login',
            ],
        ];
        $tmp = sys_get_temp_dir() . '/weline-sibling-clean-' . getmypid() . '.phtml';
        file_put_contents($tmp, '<w:slot id="account-login-social-providers" accept="account-social-login"></w:slot>');
        try {
            self::assertSame([], $scanner->scanFile($tmp, 'x.phtml', $index));
        } finally {
            @unlink($tmp);
        }
    }

    public function testProjectScanFindsNoLoginSiblingFetch(): void
    {
        $scanner = new RequiredInjectionSiblingFetchScanner();
        $root = dirname(__DIR__, 5);
        if (!is_dir($root . '/Weline')) {
            self::markTestSkipped('app/code missing');
        }
        $violations = $scanner->scanProject($root);
        $loginHits = array_values(array_filter(
            $violations,
            static fn(array $v): bool => str_contains((string)($v['path'] ?? ''), 'account/login.phtml')
                || str_contains((string)($v['code'] ?? ''), 'account-social-login'),
        ));
        self::assertSame([], $loginHits, json_encode($loginHits, JSON_UNESCAPED_UNICODE));
        $pdpHits = array_values(array_filter(
            $violations,
            static fn(array $v): bool => str_contains((string)($v['path'] ?? ''), 'product-info.phtml')
                && in_array((string)($v['code'] ?? ''), ['product-add-to-cart', 'product-buy-now'], true),
        ));
        self::assertSame([], $pdpHits, json_encode($pdpHits, JSON_UNESCAPED_UNICODE));
    }
}
