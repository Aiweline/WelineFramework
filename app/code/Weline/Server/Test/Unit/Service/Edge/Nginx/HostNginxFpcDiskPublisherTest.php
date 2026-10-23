<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\HostNginxFpcDiskPublisher;
use Weline\Server\Service\Edge\Nginx\HostNginxFpcTarget;

final class HostNginxFpcDiskPublisherTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    \rmdir($item->getPathname());
                } else {
                    \unlink($item->getPathname());
                }
            }
            \rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testHttpIncludeSeesVhostGlobWhenBraceIsOnTheNextLine(): void
    {
        $this->root = $this->makeTree();
        $conf = "events {\n    worker_connections 1;\n}\nhttp\n    {\n        include mime.types;\n    server { listen 80; }\ninclude " . $this->root . "/vhost/*.conf;\n}\n";
        $files = (new HostNginxFpcDiskPublisher())->httpIncludeFiles($conf, $this->root);
        self::assertContains($this->root . '/vhost/shop.conf', $files);
    }

    public function testInstallWritesMapAndReplacesLoopbackLocation(): void
    {
        $this->root = $this->makeTree();
        $project = $this->root . '/project';
        $snippet = $this->root . '/snippet.conf';
        \file_put_contents($snippet, "location / {\n    proxy_pass http://127.0.0.1:23594;\n}\n");
        $publisher = new HostNginxFpcDiskPublisher();
        $result = $publisher->install(new HostNginxFpcTarget(
            '/usr/sbin/nginx',
            $this->root . '/vhost',
            $snippet,
            $project,
            'shop.test',
            '127.0.0.1',
            23594,
        ), false);
        self::assertTrue($result['ok']);
        $map = (string)\file_get_contents($this->root . '/vhost/' . HostNginxFpcDiskPublisher::MAP_BASENAME);
        self::assertStringContainsString('map $arg_w_pg $wls_fpc_page_gen', $map);
        $body = (string)\file_get_contents($snippet);
        self::assertStringContainsString('pg/$wls_fpc_page_gen', $body);
        self::assertStringContainsString('proxy_pass http://127.0.0.1:23594;', $body);
        $again = $publisher->install(new HostNginxFpcTarget(
            '/usr/sbin/nginx',
            $this->root . '/vhost',
            $snippet,
            $project,
            'shop.test',
            '127.0.0.1',
            23594,
        ), false);
        self::assertSame('unchanged', $again['message']);
    }

    public function testUnwritableMapPrintsWhatNginxStillNeeds(): void
    {
        $this->root = $this->makeTree();
        $locked = $this->root . '/locked-vhost';
        \mkdir($locked, 0777, true);
        \chmod($locked, 0555);
        $snippet = $this->root . '/snippet.conf';
        \file_put_contents($snippet, "location / {\n    proxy_pass http://127.0.0.1:23594;\n}\n");
        $result = (new HostNginxFpcDiskPublisher())->install(new HostNginxFpcTarget(
            '/usr/sbin/nginx',
            $locked,
            $snippet,
            $this->root . '/project',
            'shop.test',
            '127.0.0.1',
            23594,
        ), false);
        \chmod($locked, 0755);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('无权写入', $result['notice']);
        self::assertStringContainsString($locked . '/' . HostNginxFpcDiskPublisher::MAP_BASENAME, $result['notice']);
        self::assertStringContainsString('pg/$wls_fpc_page_gen', $result['notice']);
        self::assertStringContainsString('server:nginx:host-fpc --host=shop.test -p=23594', $result['notice']);
        self::assertStringNotContainsString('pg/$wls_fpc_page_gen', (string)\file_get_contents($snippet));
    }

    public function testProjectIncludePathsAndDiscoveryNoticeNameExactTargets(): void
    {
        $paths = HostNginxFpcDiskPublisher::projectIncludePaths('/srv/weline');
        self::assertSame('/srv/weline/var/server/nginx/conf/host-fpc-http.include.conf', $paths['http']);
        self::assertSame('/srv/weline/var/server/nginx/conf/host-fpc-server.include.conf', $paths['server']);

        $notice = (new HostNginxFpcDiskPublisher())->discoveryNotice(
            '/srv/weline',
            'shop.test',
            9555,
            '/usr/sbin/nginx',
        );
        self::assertStringContainsString('sudo bash /srv/weline/bin/wls-host-nginx-fpc', $notice);
        self::assertStringContainsString('--host=shop.test -p=9555', $notice);
        self::assertStringContainsString($paths['http'], $notice);
        self::assertStringContainsString($paths['server'], $notice);
    }

    private function makeTree(): string
    {
        $root = \sys_get_temp_dir() . '/wls-fpc-nginx-' . \bin2hex(\random_bytes(4));
        \mkdir($root . '/vhost', 0777, true);
        \mkdir($root . '/project', 0777, true);
        \file_put_contents($root . '/vhost/shop.conf', "server {\n    server_name shop.test;\n}\n");

        return $root;
    }
}
