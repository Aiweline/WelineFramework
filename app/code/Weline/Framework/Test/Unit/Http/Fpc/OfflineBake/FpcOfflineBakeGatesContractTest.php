<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc\OfflineBake;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeExporter;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeImporter;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeOrigin;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeSchema;
use Weline\Framework\Test\ProjectRoot;

final class FpcOfflineBakeGatesContractTest extends TestCase
{
    protected function setUp(): void
    {
        ProjectRoot::defineBp(__DIR__);
    }

    public function testOriginExactMatchAndUrlBelonging(): void
    {
        self::assertSame('https://changanhanfu.com', FpcOfflineBakeOrigin::normalize('https://ChanganHanfu.com/'));
        self::assertTrue(FpcOfflineBakeOrigin::equals('https://a.test', 'https://A.TEST/'));
        self::assertFalse(FpcOfflineBakeOrigin::equals('https://a.test', 'https://b.test'));
        self::assertTrue(FpcOfflineBakeOrigin::urlBelongsTo('https://a.test/en_US/', 'https://a.test'));
        self::assertFalse(FpcOfflineBakeOrigin::urlBelongsTo('https://b.test/', 'https://a.test'));
        self::assertFalse(FpcOfflineBakeOrigin::urlBelongsTo('/', 'https://a.test'));
    }

    public function testSchemaRejectsPathOnlyAndCrossWebsite(): void
    {
        $manifest = [
            'schema' => FpcOfflineBakeSchema::SCHEMA,
            'website_code' => 'hanfu',
            'primary_origin' => 'https://changanhanfu.com',
        ];
        self::assertSame([], FpcOfflineBakeSchema::validateManifest($manifest));

        $good = [
            'full_uri' => 'https://changanhanfu.com/',
            'index_key' => 'https://changanhanfu.com/',
            'website_code' => 'hanfu',
            'lang' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'body_relpath' => 'payloads/ab/abcd.html',
            'encoding' => 'identity',
        ];
        self::assertSame([], FpcOfflineBakeSchema::validateEntry($good, 'hanfu', 'https://changanhanfu.com'));

        $pathOnly = $good;
        $pathOnly['full_uri'] = '/';
        $pathOnly['index_key'] = '/';
        self::assertContains('path_only_forbidden', FpcOfflineBakeSchema::validateEntry(
            $pathOnly,
            'hanfu',
            'https://changanhanfu.com'
        ));

        $crossSite = $good;
        $crossSite['full_uri'] = 'https://daocharms.com/';
        $crossSite['index_key'] = 'https://daocharms.com/';
        self::assertContains('origin_mismatch', FpcOfflineBakeSchema::validateEntry(
            $crossSite,
            'hanfu',
            'https://changanhanfu.com'
        ));

        $wrongWebsite = $good;
        $wrongWebsite['website_code'] = 'daocharms';
        self::assertContains('website_code_mismatch', FpcOfflineBakeSchema::validateEntry(
            $wrongWebsite,
            'hanfu',
            'https://changanhanfu.com'
        ));
    }

    public function testImportRejectsForeignWebsiteAndPathOnlyPackage(): void
    {
        $tmp = \sys_get_temp_dir() . '/fpc-bake-gate-' . \uniqid('', true);
        \mkdir($tmp . '/payloads/ab', 0775, true);
        $body = $tmp . '/payloads/ab/deadbeef.html';
        \file_put_contents($body, '<html>A</html>');
        $sha = \hash('sha256', '<html>A</html>');
        $relBody = 'payloads/ab/' . $sha . '.html';
        \rename($body, $tmp . '/' . $relBody);

        \file_put_contents($tmp . '/manifest.json', \json_encode([
            'schema' => FpcOfflineBakeSchema::SCHEMA,
            'website_code' => 'hanfu',
            'primary_origin' => 'https://changanhanfu.com',
            'entry_count' => 1,
        ], \JSON_UNESCAPED_SLASHES));
        \file_put_contents($tmp . '/entries.jsonl', \json_encode([
            'full_uri' => 'https://changanhanfu.com/',
            'index_key' => 'https://changanhanfu.com/',
            'website_code' => 'hanfu',
            'lang' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'body_relpath' => $relBody,
            'encoding' => 'identity',
            'content_sha256' => $sha,
        ], \JSON_UNESCAPED_SLASHES) . "\n");

        $importer = new FpcOfflineBakeImporter();
        $wrongSite = $importer->import($tmp, 'daocharms', 'https://changanhanfu.com', false);
        self::assertFalse($wrongSite['ok']);
        self::assertSame('website_code_mismatch', $wrongSite['reason'] ?? null);

        $wrongOrigin = $importer->import($tmp, 'hanfu', 'https://daocharms.com', false);
        self::assertFalse($wrongOrigin['ok']);
        self::assertSame('origin_mismatch', $wrongOrigin['reason'] ?? null);

        // Path-only entry must abort whole package.
        $tmp2 = \sys_get_temp_dir() . '/fpc-bake-pathonly-' . \uniqid('', true);
        \mkdir($tmp2 . '/payloads/ab', 0775, true);
        \file_put_contents($tmp2 . '/' . $relBody, '<html>A</html>');
        \file_put_contents($tmp2 . '/manifest.json', \json_encode([
            'schema' => FpcOfflineBakeSchema::SCHEMA,
            'website_code' => 'hanfu',
            'primary_origin' => 'https://changanhanfu.com',
        ], \JSON_UNESCAPED_SLASHES));
        \file_put_contents($tmp2 . '/entries.jsonl', \json_encode([
            'full_uri' => '/',
            'index_key' => '/',
            'website_code' => 'hanfu',
            'lang' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'body_relpath' => $relBody,
            'encoding' => 'identity',
            'content_sha256' => $sha,
        ], \JSON_UNESCAPED_SLASHES) . "\n");
        $pathOnly = $importer->import($tmp2, 'hanfu', 'https://changanhanfu.com', false);
        self::assertFalse($pathOnly['ok']);
        self::assertStringContainsString('path_only_forbidden', (string)($pathOnly['reason'] ?? ''));

        $this->cleanupDir($tmp);
        $this->cleanupDir($tmp2);
    }

    public function testExportImportRoundTripHostScopedAndLangHints(): void
    {
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'ob';
        if (!\is_dir($payloadDir)) {
            \mkdir($payloadDir, 0775, true);
        }
        $origin = 'https://bake-test-' . \uniqid('', true) . '.example';
        $urlZh = FpcPayloadPathIndex::normalizeIndexKey($origin . '/');
        $urlEn = FpcPayloadPathIndex::normalizeIndexKey($origin . '/en_US/');
        $bodyZh = $payloadDir . \DIRECTORY_SEPARATOR . 'bake-zh-' . \uniqid('', true) . '.html';
        $bodyEn = $payloadDir . \DIRECTORY_SEPARATOR . 'bake-en-' . \uniqid('', true) . '.html';
        \file_put_contents($bodyZh, '<html lang="zh">中文</html>');
        \file_put_contents($bodyEn, '<html lang="en">English</html>');
        FpcPayloadPathIndex::remember($urlZh, 'k-zh', $bodyZh);
        FpcPayloadPathIndex::remember($urlEn, 'k-en', $bodyEn);

        $pkg = \sys_get_temp_dir() . '/fpc-bake-rt-' . \uniqid('', true);
        $exporter = new FpcOfflineBakeExporter();
        $exported = $exporter->export($pkg, 'bake_site', $origin, [$urlZh, $urlEn]);
        self::assertTrue($exported['ok'] ?? false, (string)($exported['reason'] ?? ''));
        self::assertGreaterThanOrEqual(2, (int)($exported['entry_count'] ?? 0));

        $entries = \file($pkg . '/entries.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($entries);
        $langs = [];
        foreach ($entries as $line) {
            $row = \json_decode((string)$line, true);
            self::assertIsArray($row);
            $langs[(string)$row['index_key']] = (string)$row['lang'];
        }
        self::assertArrayHasKey($urlZh, $langs, 'langs=' . \implode(',', \array_keys($langs)));
        self::assertArrayHasKey($urlEn, $langs, 'langs=' . \implode(',', \array_keys($langs)));
        self::assertSame('en_US', $langs[$urlEn]);
        self::assertNotSame($langs[$urlZh], $langs[$urlEn], 'zh/en must be distinct variant slots');

        // Purge source refs then import → html-disk only (no path-index remember).
        FpcPayloadPathIndex::purgePaths([$urlZh, $urlEn]);
        $importer = new FpcOfflineBakeImporter();
        $imported = $importer->import($pkg, 'bake_site', $origin, false);
        self::assertTrue($imported['ok'] ?? false, (string)($imported['reason'] ?? ''));
        self::assertGreaterThanOrEqual(2, (int)($imported['imported'] ?? 0));
        self::assertSame([], FpcPayloadPathIndex::lookup($urlZh));
        self::assertSame([], FpcPayloadPathIndex::lookup($urlEn));

        $zhHit = FpcHtmlDiskStore::read($urlZh, '');
        $enHit = FpcHtmlDiskStore::read($urlEn, '');
        self::assertNotNull($zhHit);
        self::assertNotNull($enHit);
        self::assertStringContainsString('中文', $this->diskHitPlaintext($zhHit));
        self::assertStringContainsString('English', $this->diskHitPlaintext($enHit));
        $zhPath = (string)($zhHit['path'] ?? '');
        $enPath = (string)($enHit['path'] ?? '');
        self::assertStringContainsString('generated' . \DIRECTORY_SEPARATOR . 'html', $zhPath);
        self::assertStringNotContainsString('router-fpc-payloads', $zhPath);
        self::assertFalse(
            ProjectRoot::isAccidentalAppCodeGenerated($zhPath),
            'FPC html must not land under app/code/generated'
        );
        self::assertFalse(
            ProjectRoot::isAccidentalAppCodeGenerated($enPath),
            'FPC html must not land under app/code/generated'
        );

        FpcHtmlDiskStore::purgeUrls([$urlZh, $urlEn]);
        $this->cleanupDir($pkg);
    }

    /**
     * @param array{body?:string,encoding?:?string,path?:string} $hit
     */
    private function diskHitPlaintext(array $hit): string
    {
        $body = (string)($hit['body'] ?? '');
        if (($hit['encoding'] ?? null) !== 'br') {
            return $body;
        }
        self::assertTrue(\function_exists('brotli_uncompress'), 'br disk leaf needs brotli_uncompress');
        $plain = \brotli_uncompress($body);
        self::assertIsString($plain);

        return $plain;
    }

    private function cleanupDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
            } else {
                @\unlink($file->getPathname());
            }
        }
        @\rmdir($dir);
    }
}
