<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class BlogSeoFactsBuilderAuthorDepthTest extends TestCase
{
    public function testDeepPersonFieldsAreEmittedInSource(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogSeoFactsBuilder.php');

        self::assertStringContainsString("\$person['url']", $source);
        self::assertStringContainsString("\$person['description']", $source);
        self::assertStringContainsString("\$person['jobTitle']", $source);
        self::assertStringContainsString("\$person['sameAs']", $source);
        self::assertStringContainsString('authorJobTitle', $source);
        self::assertStringContainsString('authorSameAs', $source);
    }
}
