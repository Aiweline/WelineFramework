<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Minify;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Theme\Minify\StaticAssetMinifier;
use Weline\Theme\Observer\StaticAssetTransformMinify;

final class StaticAssetTransformMinifyTest extends TestCase
{
    public function testObserverRespectsDevMode(): void
    {
        $observer = new StaticAssetTransformMinify(new StaticAssetMinifier());
        $original = "/*x*/\n.body { color: #000; }\n";
        $payload = new DataObject([
            'source_path' => '/src/a.css',
            'target_path' => '/dest/a.css',
            'extension' => 'css',
            'content' => $original,
            'transformed' => false,
        ]);
        $event = new Event(['data' => $payload]);
        $observer->execute($event);

        if (defined('DEV') && DEV) {
            self::assertFalse((bool)$payload->getData('transformed'));
            self::assertSame($original, $payload->getData('content'));
            return;
        }

        $content = (string)$payload->getData('content');
        self::assertTrue((bool)$payload->getData('transformed'));
        self::assertStringNotContainsString('/*x*/', $content);
        self::assertLessThan(strlen($original), strlen($content));
    }

    public function testObserverSkipsMinFiles(): void
    {
        $observer = new StaticAssetTransformMinify(new StaticAssetMinifier());
        $original = "/* keep looking */\n.body{color:red}";
        $payload = new DataObject([
            'source_path' => '/src/a.min.css',
            'target_path' => '/dest/a.min.css',
            'extension' => 'css',
            'content' => $original,
            'transformed' => false,
        ]);
        $event = new Event(['data' => $payload]);
        $observer->execute($event);

        self::assertFalse((bool)$payload->getData('transformed'));
        self::assertSame($original, $payload->getData('content'));
    }
}
