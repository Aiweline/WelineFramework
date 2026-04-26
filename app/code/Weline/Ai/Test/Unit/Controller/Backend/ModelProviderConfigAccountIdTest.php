<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Controller\Backend\Model;

class ModelProviderConfigAccountIdTest extends TestCase
{
    public function testEmptyAccountIdClearsLegacyBinding(): void
    {
        $controller = (new ReflectionClass(Model::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Model::class, 'buildIncomingProviderConfigData');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'provider_config_json' => json_encode([
                'account_id' => '1',
                'base_url' => 'https://api.deepseek.com',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'provider_config' => [
                'account_id' => '',
            ],
        ], 'deepseek-v4-pro', []);

        $this->assertArrayNotHasKey('account_id', $result);
        $this->assertSame('deepseek-v4-pro', $result['provider_model_code'] ?? null);
    }

    public function testInactiveBoundAccountReturnsClearMessage(): void
    {
        $controller = (new ReflectionClass(Model::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Model::class, 'getBoundProviderAccountUnavailableMessage');
        $method->setAccessible(true);

        $account = new Account();
        $account->setData(Account::schema_fields_ID, 1);
        $account->setData(Account::schema_fields_ACCOUNT_NAME, 'DeepSeek');
        $account->setData(Account::schema_fields_IS_ACTIVE, 0);

        $message = $method->invoke($controller, $account, 1);

        $this->assertStringContainsString('account_id: 1', $message);
        $this->assertStringContainsString('DeepSeek', $message);
    }
}
