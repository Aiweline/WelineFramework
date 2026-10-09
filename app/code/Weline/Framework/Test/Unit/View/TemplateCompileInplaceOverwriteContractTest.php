<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\View\Template;

/**
 * v7：compileSourceBytes 写固定路径；compile_id 变化时原地覆盖，不建 __bytes_*。
 */
final class TemplateCompileInplaceOverwriteContractTest extends TestCase
{
    public function testCompileSourceBytesWritesFixedPathAndOverwritesOnIdentityChange(): void
    {
        $template = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $events = $this->getMockBuilder(EventsManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['dispatch', 'getEventObservers'])
            ->getMock();
        $events->method('getEventObservers')->willReturn([]);
        $events->method('dispatch')->willReturnSelf();
        (new ReflectionProperty(Template::class, 'eventsManager'))->setValue($template, $events);
        (new ReflectionProperty(Template::class, 'compiledSourceOrigins'))->setValue($template, []);

        $dir = \sys_get_temp_dir() . '/weline_inplace_compile_' . \uniqid('', true);
        self::assertTrue(@\mkdir($dir, 0775, true));
        $target = $dir . '/com_demo.phtml';
        $origin = $dir . '/demo.phtml';
        \file_put_contents($origin, '<div>one</div>');

        $method = new ReflectionMethod(Template::class, 'compileSourceBytes');
        $method->setAccessible(true);

        $first = $method->invoke($template, $target, '<div>one</div>', $origin, '', null);
        self::assertSame($target, $first);
        self::assertFileExists($target);
        self::assertStringNotContainsString('__bytes_', $first);
        $head1 = (string)\file_get_contents($target, false, null, 0, 200);
        self::assertMatchesRegularExpression('/compile_id:[a-f0-9]{40}/', $head1);

        $hit = $method->invoke($template, $target, '<div>one</div>', $origin, '', null);
        self::assertSame($target, $hit);
        self::assertSame($head1, (string)\file_get_contents($target, false, null, 0, 200), 'Unchanged source must reuse file without rewrite noise');

        $second = $method->invoke($template, $target, '<div>two</div>', $origin, '', null);
        self::assertSame($target, $second);
        $body = (string)\file_get_contents($target);
        self::assertStringContainsString('two', $body);
        self::assertStringNotContainsString('__bytes_', $second);
        $entries = \glob($dir . '/*') ?: [];
        self::assertCount(2, $entries, 'Only origin + one com_ file; no __bytes_ sibling dirs');

        foreach ($entries as $entry) {
            if (\is_file($entry)) {
                @\unlink($entry);
            }
        }
        @\rmdir($dir);
    }
}
