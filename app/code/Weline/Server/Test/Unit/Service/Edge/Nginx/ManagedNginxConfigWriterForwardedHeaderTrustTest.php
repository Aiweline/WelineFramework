<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxOriginMode;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

/**
 * Behind a trusted front hop (CDN / co-resident panel nginx terminating TLS) the
 * public origin comes from that hop's X-Forwarded-Proto/Port; nobody else may
 * override this listener's scheme/port.
 */
final class ManagedNginxConfigWriterForwardedHeaderTrustTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-fwd-trust-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root . DIRECTORY_SEPARATOR . 'pub', 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        self::assertNotFalse(\file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'index.php',
            "<?php echo 'ok';\n",
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testTrustGeoKeysOnTcpPeerAndIncludesLoopbackAndPublicHops(): void
    {
        $conf = $this->writeConf(ManagedNginxOriginMode::WLS, realIp: true);

        self::assertStringContainsString('geo $realip_remote_addr $wls_trusted_front_proxy {', $conf);
        $geo = $this->block($conf, 'geo $realip_remote_addr $wls_trusted_front_proxy {');
        self::assertStringContainsString('default 0;', $geo);
        self::assertStringContainsString('127.0.0.0/8 1;', $geo);
        self::assertStringContainsString('::1/128 1;', $geo);
        self::assertStringContainsString('203.0.113.10/32 1;', $geo);
        // real_ip still never peels loopback.
        self::assertStringNotContainsString('set_real_ip_from 127.0.0.0/8;', $conf);
    }

    public function testWithoutRealIpModuleTrustKeysOnRemoteAddr(): void
    {
        $conf = $this->writeConf(ManagedNginxOriginMode::WLS, realIp: false);

        self::assertStringContainsString('geo $remote_addr $wls_trusted_front_proxy {', $conf);
        self::assertStringNotContainsString('$realip_remote_addr', $conf);
    }

    public function testPublicSchemeAndPortFallBackToListenerForUntrustedPeers(): void
    {
        $conf = $this->writeConf(ManagedNginxOriginMode::WLS, realIp: true);

        $scheme = $this->block($conf, 'map "$wls_trusted_front_proxy:$wls_forwarded_proto_hint" $wls_public_scheme {');
        self::assertStringContainsString('default $scheme;', $scheme);
        self::assertStringContainsString('"1:https" https;', $scheme);
        self::assertStringContainsString('"1:http" http;', $scheme);

        $port = $this->block(
            $conf,
            'map "$wls_trusted_front_proxy:$wls_forwarded_proto_hint:$wls_forwarded_port_hint" $wls_public_port {'
        );
        self::assertStringContainsString('default $server_port;', $port);
        self::assertStringContainsString('"1:https:" 443;', $port);
        self::assertStringContainsString('"1:http:" 80;', $port);
        self::assertStringContainsString('"~^1:[a-z]*:([0-9]+)$" $1;', $port);

        $redirect = $this->block($conf, 'map "$wls_trusted_front_proxy:$scheme" $wls_edge_http_redirect {');
        self::assertStringContainsString('"0:http" 1;', $redirect);
    }

    public function testWlsProxyHeadersAndHtmlDiskGateUsePublicOrigin(): void
    {
        $conf = $this->writeConf(ManagedNginxOriginMode::WLS, realIp: true);

        self::assertStringContainsString('proxy_set_header X-Forwarded-Proto $wls_public_scheme;', $conf);
        self::assertStringContainsString('proxy_set_header X-Forwarded-Port $wls_public_port;', $conf);
        self::assertStringNotContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $conf);
        self::assertStringNotContainsString('proxy_set_header X-Forwarded-Port $server_port;', $conf);
        self::assertStringContainsString(
            'map "$wls_public_scheme:$wls_fpc_html_disk_bypass:',
            $conf,
        );
        self::assertStringNotContainsString('if ($scheme = http)', $conf);
    }

    public function testFpmFrontControllerPinsPublicSchemePortAfterFastcgiParams(): void
    {
        $conf = $this->writeConf(ManagedNginxOriginMode::FPM, realIp: false);

        self::assertStringContainsString('fastcgi_pass weline_fpm', $conf);
        self::assertStringNotContainsString('fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;', $conf);
        self::assertStringNotContainsString('fastcgi_param HTTP_X_FORWARDED_PORT $server_port;', $conf);
        self::assertMatchesRegularExpression(
            '/include [^\n]*fastcgi_params[^\n]*;[\s\S]{0,600}?'
            . 'fastcgi_param REQUEST_SCHEME \$wls_public_scheme;\s*'
            . 'fastcgi_param HTTPS \$wls_public_https if_not_empty;\s*'
            . 'fastcgi_param SERVER_PORT \$wls_public_port;\s*'
            . 'fastcgi_param HTTP_X_FORWARDED_PROTO \$wls_public_scheme;\s*'
            . 'fastcgi_param HTTP_X_FORWARDED_PORT \$wls_public_port;/',
            $conf,
        );
    }

    private function writeConf(string $originMode, bool $realIp): string
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => $originMode . '-runtime',
            'install_root' => $originMode . '-install',
            'listen_http' => 18120,
            'listen_https' => 18520,
            'origin_mode' => $originMode,
            'fpm_listen' => '127.0.0.1:9000',
        ]);
        $paths->ensureRuntimeDirectories();
        $mime = $paths->installRoot() . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'mime.types';
        if (!\is_dir(\dirname($mime))) {
            self::assertTrue(\mkdir(\dirname($mime), 0700, true));
        }
        self::assertNotFalse(\file_put_contents($mime, "types {\n    text/html html;\n}\n"));

        $written = (new ManagedNginxConfigWriter($paths))->write(
            19030,
            '127.0.0.1',
            ['_'],
            candidate: false,
            realIpSupported: $realIp,
            nginxTrustedProxyCidrs: ['203.0.113.10/32', '127.0.0.0/8'],
        );
        $conf = \file_get_contents((string)$written['conf']);
        self::assertIsString($conf);

        return $conf;
    }

    private function block(string $conf, string $opening): string
    {
        $start = \strpos($conf, $opening);
        self::assertIsInt($start, 'missing block: ' . $opening);
        $end = \strpos($conf, "\n    }", $start);
        self::assertIsInt($end);

        return \substr($conf, $start, $end - $start);
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
