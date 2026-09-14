<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Helper\MimeTypes;

final class MimeTypesTest extends TestCase
{
    public function testWildcardIsSafeAndExcludesActiveBrowserContent(): void
    {
        $extensions = MimeTypes::collectExtensions('*');

        self::assertContains('png', $extensions);
        self::assertContains('pdf', $extensions);
        self::assertNotContains('svg', $extensions);
        self::assertNotContains('html', $extensions);
        self::assertNotContains('js', $extensions);
        self::assertNotContains('css', $extensions);
        self::assertNotContains('xml', $extensions);
    }

    public function testExplicitConfigurationStillRejectsExecutableBrowserContent(): void
    {
        $extensions = MimeTypes::collectExtensions('png,svg,html,js,css,xml');

        self::assertSame(['png'], $extensions);
        self::assertSame(['image/png'], MimeTypes::collectMimes('png,svg,html,js,css,xml'));
    }

    public function testCommonFileinfoAliasesRemainConsistentWithAdvertisedExtensions(): void
    {
        self::assertContains('application/vnd.rar', MimeTypes::getMimeTypes('rar'));
        self::assertContains('application/x-gzip', MimeTypes::getMimeTypes('gz'));
        self::assertContains('audio/x-wav', MimeTypes::getMimeTypes('wav'));
        self::assertContains('application/font-sfnt', MimeTypes::getMimeTypes('ttf'));
        self::assertContains('application/font-woff2', MimeTypes::getMimeTypes('woff2'));
        self::assertContains('application/zip', MimeTypes::getMimeTypes('docx'));
        self::assertContains('application/zip', MimeTypes::getMimeTypes('xlsx'));
        self::assertContains('application/zip', MimeTypes::getMimeTypes('pptx'));
        self::assertContains('text/plain', MimeTypes::getMimeTypes('csv'));
        self::assertContains('text/plain', MimeTypes::getMimeTypes('json'));
    }

    public function testM4aAllowsCommonFileinfoContainerAliases(): void
    {
        $mimes = MimeTypes::getMimeTypes('m4a');
        self::assertContains('audio/mp4', $mimes);
        self::assertContains('audio/x-m4a', $mimes);
        // Real-world AAC-in-MP4 downloads are frequently sniffed as video/mp4.
        self::assertContains('video/mp4', $mimes);
        self::assertContains('application/mp4', $mimes);

        $allowed = MimeTypes::collectMimes('mp3,wav,ogg,oga,m4a,aac,flac,opus,wma,weba');
        self::assertContains('video/mp4', $allowed);
        self::assertContains('audio/mp4', $allowed);
    }
}
