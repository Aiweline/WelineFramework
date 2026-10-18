<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\ProjectRoot;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxOriginMode;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
ProjectRoot::defineBp(__DIR__);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('PUB') || \define('PUB', PUB);
\defined('VAR_DIR') || \define('VAR_DIR', VAR_DIR);

/**
 * FPM origin conf contract: zero wls_backend proxy, fastcgi front-controller,
 * local /_wls and ACME challenge roots.
 */
final class ManagedNginxOriginModeFpmConfContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-fpm-contract-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        self::assertTrue(\mkdir($this->root . DIRECTORY_SEPARATOR . 'pub', 0700, true));
        self::assertNotFalse(\file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'index.php',
            "<?php echo 'ok';\n",
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testOriginModeNormalizeAcceptsAliases(): void
    {
        self::assertSame(ManagedNginxOriginMode::FPM, ManagedNginxOriginMode::normalize('fpm'));
        self::assertSame(ManagedNginxOriginMode::FPM, ManagedNginxOriginMode::normalize('PHP-FPM'));
        self::assertSame(ManagedNginxOriginMode::WLS, ManagedNginxOriginMode::normalize('wls'));
        self::assertSame(ManagedNginxOriginMode::WLS, ManagedNginxOriginMode::normalize(''));
        self::assertTrue(ManagedNginxOriginMode::isFpm('php_fpm'));
    }

    public function testWriterGatesOriginModeHeaderWithIdentityObservability(): void
    {
        $src = \file_get_contents(
            BP . 'app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php',
        );
        self::assertIsString($src);
        self::assertStringContainsString('X-Wls-Origin-Mode', $src);
        self::assertStringContainsString('identityHeadersEnabled()', $src);
        // Origin-mode must share the identity gate — not always-on like /_wls/ probe.
        self::assertMatchesRegularExpression(
            '/\$emitNginxConfigHeader\s*=\s*.*?identityHeadersEnabled[\s\S]{0,400}?X-Wls-Origin-Mode/',
            $src,
        );
    }

    public function testFpmConfHasFastcgiFrontControllerAndNoWlsBackendProxy(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'fpm-runtime',
            'install_root' => 'fpm-install',
            'listen_http' => 18110,
            'listen_https' => 18510,
            'origin_mode' => ManagedNginxOriginMode::FPM,
            'fpm_listen' => '127.0.0.1:9000',
        ]);
        $paths->ensureRuntimeDirectories();
        $this->seedMimeTypes($paths);

        $written = (new ManagedNginxConfigWriter($paths))->write(
            19020,
            candidate: false,
        );
        $conf = \file_get_contents((string)$written['conf']);
        self::assertIsString($conf);

        self::assertStringNotContainsString('proxy_pass http://wls_backend', $conf);
        self::assertStringNotContainsString('upstream wls_backend', $conf);
        self::assertStringContainsString('upstream weline_fpm', $conf);
        self::assertStringContainsString('fastcgi_pass weline_fpm', $conf);
        self::assertStringContainsString('fastcgi_param SCRIPT_FILENAME $document_root/index.php', $conf);
        self::assertStringContainsString('location ^~ /_wls/', $conf);
        self::assertStringContainsString('return 200 "ok\\n"', $conf);
        self::assertStringContainsString('location ^~ /.well-known/acme-challenge/', $conf);
        self::assertStringContainsString('/acme/challenge', $conf);
        // ACME must not proxy to Workers in FPM mode.
        self::assertDoesNotMatchRegularExpression(
            '/location \^~ \/\.well-known\/acme-challenge\/ \{[^}]*proxy_pass/s',
            $conf,
        );
        // FPM rewrite must not leave dead proxy_* or stacked identity headers.
        self::assertDoesNotMatchRegularExpression(
            '/fastcgi_pass weline_fpm;[\s\S]{0,800}?proxy_pass |fastcgi_pass weline_fpm;[\s\S]{0,800}?proxy_set_header /',
            $conf,
            'FPM rewrite must consume residual proxy_* after proxy_pass replacement.',
        );
    }

    public function testWlsConfStillUsesWlsBackendUpstream(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'wls-runtime',
            'install_root' => 'wls-install',
            'listen_http' => 18111,
            'listen_https' => 18511,
            'origin_mode' => ManagedNginxOriginMode::WLS,
        ]);
        $paths->ensureRuntimeDirectories();
        $this->seedMimeTypes($paths);

        $written = (new ManagedNginxConfigWriter($paths))->write(
            19021,
            candidate: false,
        );
        $conf = \file_get_contents((string)$written['conf']);
        self::assertIsString($conf);
        self::assertStringContainsString('upstream wls_backend', $conf);
        self::assertStringContainsString('proxy_pass http://wls_backend', $conf);
        self::assertStringNotContainsString('upstream weline_fpm', $conf);
    }

    public function testFpmConfBindsHostLeafNotLocalhostWhenServerNameProvided(): void
    {
        $host = 'demo-fpm.test.weline.com';
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'fpm-ssl-runtime',
            'install_root' => 'fpm-ssl-install',
            'listen_http' => 18112,
            'listen_https' => 18512,
            'origin_mode' => ManagedNginxOriginMode::FPM,
            'fpm_listen' => '127.0.0.1:9000',
        ]);
        $paths->ensureRuntimeDirectories();
        $this->seedMimeTypes($paths);
        $this->seedHostSslMaterial($host);

        $written = (new ManagedNginxConfigWriter($paths))->write(
            19022,
            '127.0.0.1',
            [$host],
            candidate: false,
        );
        $conf = \file_get_contents((string)$written['conf']);
        self::assertIsString($conf);
        self::assertTrue((bool)($written['ssl'] ?? false));
        self::assertStringContainsString('/app/etc/ssl/' . $host . '/', $conf);
        self::assertStringNotContainsString('/app/etc/ssl/localhost/', $conf);
        self::assertStringContainsString('server_name ' . $host, $conf);
        self::assertStringContainsString('fastcgi_pass weline_fpm', $conf);
    }

    private function seedMimeTypes(ManagedNginxPaths $paths): void
    {
        $mime = $paths->installRoot() . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'mime.types';
        $dir = \dirname($mime);
        if (!\is_dir($dir)) {
            self::assertTrue(\mkdir($dir, 0700, true));
        }
        self::assertNotFalse(\file_put_contents($mime, "types {\n    text/html html;\n}\n"));
    }

    private function seedHostSslMaterial(string $hostname): void
    {
        $dir = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc'
            . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . $hostname;
        self::assertTrue(\mkdir($dir, 0700, true));
        $keyFile = $dir . DIRECTORY_SEPARATOR . 'privkey.pem';
        $certFile = $dir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $csr = \openssl_csr_new(['CN' => $hostname], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $cert = \openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        self::assertTrue(\openssl_pkey_export_to_file($key, $keyFile));
        self::assertTrue(\openssl_x509_export_to_file($cert, $certFile));
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
