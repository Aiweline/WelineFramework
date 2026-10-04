<?php
declare(strict_types=1);
namespace Weline\I18n\Test\Unit\Query;
use PHPUnit\Framework\TestCase;
use Weline\I18n\Extends\Module\Weline_Framework\Query\I18nQueryProvider;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendAcl;
final class I18nCountryFlagAreaTest extends TestCase
{
    public function testBackendFlagsReusePayloadWithExplicitBackendAuthority(): void
    {
        $provider = (new \ReflectionClass(I18nQueryProvider::class))->newInstanceWithoutConstructor();
        $params = ['country_codes' => ['cn','us'], 'ratio' => '4x3'];
        self::assertSame($provider->execute('getCountryFlags', $params), $provider->execute('getBackendCountryFlags', $params));
        $operations = array_column($provider->getDescriptor()['operations'], null, 'name');
        $backend = $operations['getBackendCountryFlags'];
        self::assertTrue($backend['frontend']);
        self::assertSame('backend', $backend['auth']);
        self::assertSame(['kind'=>'self'], FrontendWorkerBackendAcl::normalize($backend['backend_acl'], $backend['params']));
        self::assertSame('any', $operations['getCountryFlags']['auth']);
    }
    public function testFrontendWorkerCannotUseBackendFlagOperation(): void
    {
        $provider = (new \ReflectionClass(I18nQueryProvider::class))->newInstanceWithoutConstructor();
        $ops = array_column($provider->getDescriptor()['operations'], null, 'name');
        $gateway = (new \ReflectionClass(\Weline\Framework\Service\Query\FrontendQueryGateway::class))->newInstanceWithoutConstructor();
        $authorize = new \ReflectionMethod($gateway, 'requireDescriptorAuthorization');
        $this->expectException(\Weline\Framework\Service\Query\FrontendQueryException::class);
        $authorize->invoke($gateway, 'i18n', 'getBackendCountryFlags', ['country_codes'=>['cn']], $ops['getBackendCountryFlags'],
            \Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext::frontend());
    }

}
