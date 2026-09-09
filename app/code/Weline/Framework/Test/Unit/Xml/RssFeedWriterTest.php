<?php

declare(strict_types=1);

namespace Weline\Framework\Xml\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Xml\RssFeedWriter;

final class RssFeedWriterTest extends TestCase
{
    public function testEmptyChannelProducesValidRss(): void
    {
        $xml = (new RssFeedWriter())->write([
            'title' => 'Empty',
            'link' => 'https://example.com/',
            'description' => 'none',
            'lastBuildDate' => 'Mon, 01 Jan 2024 00:00:00 +0000',
        ], []);

        self::assertStringContainsString('<rss version="2.0"', $xml);
        self::assertStringContainsString('<title>Empty</title>', $xml);
        self::assertStringContainsString('<lastBuildDate>Mon, 01 Jan 2024 00:00:00 +0000</lastBuildDate>', $xml);
        self::assertStringNotContainsString('<item>', $xml);
    }

    public function testEscapesSpecialCharactersInTextNodes(): void
    {
        $xml = (new RssFeedWriter())->write([
            'title' => 'A & B <C>',
            'link' => 'https://example.com/?q=1&x=2',
            'description' => 'quotes "here"',
            'lastBuildDate' => 'Mon, 01 Jan 2024 00:00:00 +0000',
        ], []);

        self::assertStringContainsString('<title>A &amp; B &lt;C&gt;</title>', $xml);
        self::assertStringContainsString('<link>https://example.com/?q=1&amp;x=2</link>', $xml);
        self::assertStringContainsString('<description>quotes &quot;here&quot;</description>', $xml);
    }

    public function testCdataSplitsClosingSequence(): void
    {
        $xml = (new RssFeedWriter())->write(
            [
                'title' => 'Feed',
                'link' => 'https://example.com/',
                'description' => '',
                'lastBuildDate' => 'Mon, 01 Jan 2024 00:00:00 +0000',
            ],
            [
                [
                    'title' => 'Post',
                    'link' => 'https://example.com/p',
                    'guid' => 'https://example.com/p',
                    'pubDate' => 'Mon, 01 Jan 2024 00:00:00 +0000',
                    'description' => 'before ]]> after',
                    'content_html' => '<p>safe]]>break</p>',
                ],
            ]
        );

        self::assertStringContainsString(
            '<description><![CDATA[before ]]]]><![CDATA[> after]]></description>',
            $xml
        );
        self::assertStringContainsString(
            '<content:encoded><![CDATA[<p>safe]]]]><![CDATA[>break</p>]]></content:encoded>',
            $xml
        );
    }

    public function testItemAuthorIsEmittedWhenPresent(): void
    {
        $xml = (new RssFeedWriter())->write(
            [
                'title' => 'Feed',
                'link' => 'https://example.com/',
                'description' => '',
                'lastBuildDate' => 'Mon, 01 Jan 2024 00:00:00 +0000',
            ],
            [
                [
                    'title' => 'Post',
                    'link' => 'https://example.com/p',
                    'guid' => 'https://example.com/p',
                    'pubDate' => 'Mon, 01 Jan 2024 00:00:00 +0000',
                    'description' => 'Body',
                    'author' => 'Editor',
                ],
            ]
        );

        self::assertStringContainsString('<author>Editor</author>', $xml);
    }
}
