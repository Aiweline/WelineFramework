<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request\ParameterBag;

final class ParameterBagContextBodyTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::leave();
        WelineEnv::getInstance()->reset();
    }

    public function testInitFromGlobalsParsesBodyFromCurrentContext(): void
    {
        Context::enter(new Context([
            'input' => [
                'query' => [],
                'post' => [],
                'body' => '{"foo":"bar","count":2}',
            ],
        ]));

        $parameterBag = (new ParameterBag())->initFromGlobals();

        self::assertSame('bar', $parameterBag->getBody('foo'));
        self::assertSame(2, $parameterBag->getBody('count'));
    }
}
