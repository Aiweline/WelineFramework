<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPublicRouteProbe;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class GatewayPublicRouteProbeTest extends TestCase
{
    private string $root = '';
    private GatewayRegistrationBuilder $builder;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-public-probe-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            0700,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->builder = new GatewayRegistrationBuilder(
            new ProjectIdentityStore(
                $this->root,
                $this->root . DIRECTORY_SEPARATOR . 'host-state',
                $this->root . DIRECTORY_SEPARATOR . 'legacy-desired-state.json',
            ),
            new ProjectCertificateGenerationStore($this->root),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testMissingCertificateReferenceIsFailClosedWithoutThrowing(): void
    {
        $registration = [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'instance_id' => 'gateway-probe-test',
            'routes' => [[
                'route_id' => \str_repeat('c', 32),
                'domain' => 'probe.example.test',
                'backend_identity' => [
                    'generation' => 1,
                    'launch_id' => \str_repeat('a', 32),
                    'master_epoch' => 1,
                ],
                'certificate' => [
                    'cert' => [],
                    'leaf_fingerprint_sha256' => '',
                ],
            ]],
        ];

        self::assertFalse(
            (new GatewayPublicRouteProbe())->registrationIsHealthy(
                $registration,
                21443,
            ),
        );
    }

    public function testCallerDeadlineIsNeverExtendedByTheLocalProbeBudget(): void
    {
        $registration = [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'instance_id' => 'gateway-probe-deadline',
            'routes' => [[
                'route_id' => \str_repeat('d', 32),
                'domain' => 'deadline.example.test',
                'backend_identity' => [
                    'generation' => 1,
                    'launch_id' => \str_repeat('e', 32),
                    'master_epoch' => 1,
                ],
                'certificate' => [
                    'leaf_fingerprint_sha256' => \str_repeat('f', 64),
                ],
            ]],
        ];
        $started = \hrtime(true);
        self::assertFalse((new GatewayPublicRouteProbe())->registrationIsHealthy(
            $registration,
            21443,
            null,
            (\hrtime(true) / 1_000_000_000) - 1.0,
        ));
        self::assertLessThan(100_000_000, \hrtime(true) - $started);
        self::assertFalse((new GatewayPublicRouteProbe())->registrationIsHealthy(
            $registration,
            21443,
            null,
            INF,
        ));

        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway';
        $hostManager = (string)\file_get_contents(
            $gateway . '/GatewayHostManager.php',
        );
        $agent = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Console/Server/Gateway/Agent.php',
        );
        self::assertMatchesRegularExpression(
            '/registrationIsHealthy\(\s*\$registration,.*?null,\s*\$operationDeadline,\s*\)/s',
            $hostManager,
        );
        self::assertMatchesRegularExpression(
            '/registrationIsHealthy\(\s*\$probeRegistration,.*?\$publicProbeRouteIds,\s*\$tickDeadline,\s*\)/s',
            $agent,
        );
    }

    public function testCertificateReferenceTraversalAndUnknownAliasAreRejected(): void
    {
        self::assertNull($this->builder->resolveCertificateSourceReference([
            'root_alias' => 'project_ssl',
            'relative_path' => '../outside.pem',
        ]));
        self::assertNull($this->builder->resolveCertificateSourceReference([
            'root_alias' => 'unknown',
            'relative_path' => 'certificate.pem',
        ]));
        self::assertNull($this->builder->resolveCertificateSourceReference([
            'root_alias' => 'project_ssl',
            'relative_path' => '',
        ]));
    }

    public function testPinnedCertificateWithWrongHostnameIsRejected(): void
    {
        if (!\function_exists('pcntl_fork')
            || !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')
        ) {
            self::markTestSkipped('The live TLS hostname probe requires pcntl and TLS 1.3.');
        }
        $certificate = $this->createCertificate('served.example.test', 'wrong-host');
        $context = \stream_context_create(['ssl' => [
            'local_cert' => $certificate['cert'],
            'local_pk' => $certificate['key'],
            'verify_peer' => false,
            'allow_self_signed' => true,
            'disable_compression' => true,
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        ]]);
        $server = @\stream_socket_server(
            'tls://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        self::assertIsResource($server, $error);
        $endpoint = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);

        $projectUuid = '123e4567-e89b-42d3-a456-426614174000';
        $instanceId = 'gateway-probe-test';
        $launchId = \str_repeat('a', 32);
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $client = @\stream_socket_accept($server, 2.0);
            if (\is_resource($client)) {
                @\stream_set_timeout($client, 1);
                $request = '';
                while (!\str_contains($request, "\r\n\r\n") && \strlen($request) < 65_536) {
                    $chunk = @\fread($client, 8192);
                    if (!\is_string($chunk) || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                if (\preg_match(
                    '/\AGET \/__wls_gateway_sentinel\?nonce=([a-f0-9]{32}) HTTP\/1\.1\r\n/D',
                    $request,
                    $matches,
                ) === 1) {
                    $nonce = (string)$matches[1];
                    $body = \json_encode([
                        'instance' => $instanceId,
                        'launch_id' => $launchId,
                        'master_epoch' => 1,
                        'nonce' => $nonce,
                        'status' => 'healthy',
                    ], JSON_THROW_ON_ERROR);
                    $response = "HTTP/1.1 200 OK\r\n"
                        . 'Content-Length: ' . \strlen($body) . "\r\n"
                        . "Connection: close\r\n"
                        . "X-Wls-Probe-Nonce: {$nonce}\r\n"
                        . "X-Wls-Backend-Generation: 1\r\n"
                        . "X-Wls-Project-Uuid: {$projectUuid}\r\n"
                        . "X-Wls-Instance-Id: {$instanceId}\r\n\r\n"
                        . $body;
                    @\fwrite($client, $response);
                }
                @\fclose($client);
            }
            @\fclose($server);
            exit(0);
        }
        @\fclose($server);

        $route = [
            'route_id' => \str_repeat('c', 32),
            'domain' => 'claimed.example.test',
            'backend_identity' => [
                'generation' => 1,
                'launch_id' => $launchId,
                'master_epoch' => 1,
            ],
            'certificate' => [
                'leaf_fingerprint_sha256' => \strtolower((string)\openssl_x509_fingerprint(
                    (string)\file_get_contents($certificate['cert']),
                    'sha256',
                )),
            ],
        ];
        $probe = new GatewayPublicRouteProbe();
        $routeIsHealthy = new \ReflectionMethod($probe, 'routeIsHealthy');
        self::assertFalse($routeIsHealthy->invoke(
            $probe,
            $route,
            $projectUuid,
            $instanceId,
            $port,
            \hrtime(true) / 1_000_000_000 + 2.0,
        ));
        self::assertSame($pid, \pcntl_waitpid($pid, $status));
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
    }

    /** @return array{cert:string,key:string} */
    private function createCertificate(string $domain, string $name): array
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('The OpenSSL extension is required.');
        }
        $directory = $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/' . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $config = $directory . DIRECTORY_SEPARATOR . 'openssl.cnf';
        self::assertNotFalse(\file_put_contents($config, <<<CONF
[req]
distinguished_name = dn
prompt = no
req_extensions = server_ext
x509_extensions = server_ext

[dn]
CN = {$domain}

[server_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = {$domain}
CONF
        ));
        $arguments = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'req_extensions' => 'server_ext',
            'x509_extensions' => 'server_ext',
        ];
        $key = \openssl_pkey_new($arguments);
        self::assertNotFalse($key);
        $request = \openssl_csr_new(['commonName' => $domain], $key, $arguments);
        self::assertNotFalse($request);
        $signed = \openssl_csr_sign($request, null, $key, 30, $arguments);
        self::assertNotFalse($signed);
        self::assertTrue(\openssl_x509_export($signed, $certificatePem));
        self::assertTrue(\openssl_pkey_export($key, $keyPem, null, $arguments));
        $certificatePath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertNotFalse(\file_put_contents($certificatePath, $certificatePem));
        self::assertNotFalse(\file_put_contents($keyPath, $keyPem));
        self::assertTrue(\chmod($certificatePath, 0600));
        self::assertTrue(\chmod($keyPath, 0600));
        return ['cert' => $certificatePath, 'key' => $keyPath];
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
