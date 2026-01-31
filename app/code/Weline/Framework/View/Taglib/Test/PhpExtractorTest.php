<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | PhpExtractor 单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Parser\PhpExtractor;

class PhpExtractorTest extends TestCase
{
    private PhpExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PhpExtractor();
    }

    protected function tearDown(): void
    {
        $this->extractor->reset();
    }

    public function testExtractSimpleEcho(): void
    {
        $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
        $result = $this->extractor->extract($content);

        self::assertStringContainsString('__PHP_0__', $result);
        self::assertStringNotContainsString('$name', $result);
    }

    public function testRestoreSimpleEcho(): void
    {
        $original = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
        $extracted = $this->extractor->extract($original);
        $restored = $this->extractor->restore($extracted);

        self::assertEquals($original, $restored);
    }

    public function testExtractMultiplePhpBlocks(): void
    {
        $content = '<div>' . '<' . '?= $a ?' . '>' . '</div><span>' . '<' . '?= $b ?' . '>' . '</span>';
        $result = $this->extractor->extract($content);

        self::assertStringContainsString('__PHP_0__', $result);
        self::assertStringContainsString('__PHP_1__', $result);
    }

    public function testGetExpression(): void
    {
        $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
        $this->extractor->extract($content);

        $expr = $this->extractor->getExpression('__PHP_0__');
        self::assertEquals('$name', $expr);
    }

    public function testIsPlaceholder(): void
    {
        $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
        $this->extractor->extract($content);

        self::assertTrue($this->extractor->isPlaceholder('__PHP_0__'));
        self::assertFalse($this->extractor->isPlaceholder('__PHP_999__'));
        self::assertFalse($this->extractor->isPlaceholder('not_a_placeholder'));
    }

    public function testNoPhpContent(): void
    {
        $content = '<div>Hello World</div>';
        $result = $this->extractor->extract($content);

        self::assertEquals($content, $result);
        self::assertEmpty($this->extractor->getPlaceholders());
    }

    public function testComplexExpression(): void
    {
        $content = '<div>' . '<' . '?= htmlspecialchars($user->getName()) ?' . '>' . '</div>';
        $this->extractor->extract($content);

        $expr = $this->extractor->getExpression('__PHP_0__');
        self::assertEquals("htmlspecialchars(\$user->getName())", $expr);
    }

    public function testPhpBlockInfo(): void
    {
        $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
        $this->extractor->extract($content);

        $info = $this->extractor->getPlaceholderInfo('__PHP_0__');
        
        self::assertNotNull($info);
        self::assertTrue($info['isEcho']);
        self::assertEquals('$name', $info['expression']);
    }

    public function testExtractStream(): void
    {
        $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
        $parts = iterator_to_array($this->extractor->extractStream($content), false);

        self::assertNotEmpty($parts);
        
        $combined = implode('', $parts);
        self::assertStringContainsString('__PHP_0__', $combined);
    }

    public function testStats(): void
    {
        $content = '<div>' . '<' . '?= $a ?' . '>' . '</div><span>' . '<' . '?= $b ?' . '>' . '</span>';
        $this->extractor->extract($content);

        $stats = $this->extractor->stats();
        self::assertEquals(2, $stats['count']);
        self::assertGreaterThan(0, $stats['totalSize']);
    }
}
