<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;

final class ParsedUrlOriginTraceTest extends TestCase
{
    public function testOriginSnapshotReadsEffectiveContextWithoutRetainingOrLeakingRequestData(): void
    {
        self::assertTrue(method_exists(App::class, 'parsedUrlOriginProfile'), 'URL diagnostics must expose each effective origin at observation time.');
        $snapshot = new ReflectionMethod(App::class, 'parsedUrlOriginProfile');
        $previousContext = Context::getCurrent();
        $previousServer = $_SERVER;
        if ($previousContext !== null) {
            Context::leave();
        }
        $context = new Context();
        Context::enter($context);
        $previousBase = WelineEnv::get('base_url', '');
        try {
            $ingress = [
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'shop.test:9555',
                'SERVER_PORT' => '9555',
                'WELINE_WEBSITE_URL' => 'https://shop.test:9555/private?query_secret=1',
                'HTTP_COOKIE' => 'cookie_secret=1',
            ];
            $_SERVER = $ingress;
            $context->set('input.server', $ingress);
            $context->set('route.website_url', 'https://shop.test:9555/private?query_secret=1');
            WelineEnv::set('base_url', 'https://user_secret:password_secret@base.test:9555/private?query_secret=1#fragment_secret', 'origin test');
            $parsed = array_replace($ingress, [
                'HTTP_HOST' => 'parsed.test:19655',
                'SERVER_PORT' => '19655',
                'WELINE_WEBSITE_URL' => 'https://user_secret:password_secret@parsed.test:19655/private?query_secret=1#fragment_secret',
            ]);

            $before = $snapshot->invoke(null, $parsed);
            self::assertSame('https://parsed.test:19655', $before['parsed']['http_origin']);
            self::assertSame(19655, $before['parsed']['server_port']);
            self::assertSame('https://parsed.test:19655', $before['parsed']['website_origin']);
            self::assertSame('https://shop.test:9555', $before['context']['http_origin']);
            self::assertSame('https://base.test:9555', $before['env_base_origin']);

            $context->set('input.server', $parsed);
            $afterMerge = $snapshot->invoke(null, $parsed);
            self::assertSame('https://parsed.test:19655', $afterMerge['context']['http_origin']);
            self::assertSame('https://shop.test:9555', $afterMerge['global']['http_origin']);
            self::assertSame('https://shop.test:9555', $afterMerge['route_website_origin']);

            $context->set('route.website_url', 'https://scope.test:19655/private?query_secret=1');
            $afterScope = $snapshot->invoke(null, $parsed);
            self::assertSame('https://scope.test:19655', $afterScope['route_website_origin']);
            self::assertSame('https://scope.test:19655', $afterScope['env_website_origin']);
            self::assertSame('https://shop.test:9555', $before['context']['http_origin'], 'Previously recorded snapshots remain immutable.');
            self::assertSame($ingress, $_SERVER, 'Observation must not synchronize or mutate request sources.');
            self::assertDoesNotMatchRegularExpression('/secret|private|fragment|\?|@/', json_encode([$before, $afterMerge, $afterScope], JSON_THROW_ON_ERROR));
        } finally {
            WelineEnv::set('base_url', $previousBase, 'origin test restore');
            $_SERVER = $previousServer;
            Context::leave();
            if ($previousContext !== null) {
                Context::enter($previousContext);
            }
        }
    }
}
