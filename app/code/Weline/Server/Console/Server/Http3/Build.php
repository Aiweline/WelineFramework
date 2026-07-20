<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Http3;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Protocol\Http3\NativeBuildProcessRunner;
use Weline\Server\Protocol\Http3\NativeTransportCompiler;
use Weline\Server\Protocol\Http3\NativeTransportLibrary;
use Weline\Server\Protocol\Http3\NativeTransportSelfTest;
use Weline\Server\Service\SslCertificateService;

/**
 * Explicit provisioner for the optional native HTTP/3 component.
 */
final class Build extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        if (isset($args['internal-verify-candidate'])) {
            return $this->verifyCandidateInIsolatedProcess($args);
        }
        if (!\in_array(\PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            $this->printer->error(__('HTTP/3 原生组件仅支持 macOS/Linux Direct 运行时。'));
            return 1;
        }

        $this->printer->setup(__('构建 WLS 可选 HTTP/3 组件'));
        $this->printer->note(__('这是显式构建命令；普通 server:start 永远不会下载或编译 HTTP/3。'));
        $this->printer->warning(__('本命令可能联网、调用平台包管理器、下载固定摘要源码，并写入 WLS 私有 HTTP/3 构建缓存。'));
        $result = (new NativeTransportCompiler())->ensure(true);
        if (!($result['ready'] ?? false)) {
            $this->printer->error(__('HTTP/3 组件构建失败：%{1}', [
                (string)($result['message'] ?? __('未知错误')),
            ]));
            if (!empty($result['output'])) {
                $this->printer->note((string)$result['output']);
            }
            return 1;
        }

        $candidateManifest = (array)($result['manifest'] ?? []);
        try {
            [$certificate, $privateKey] = $this->certificatePair($args);
            $selfTest = \PHP_OS_FAMILY === 'Darwin'
                ? $this->runIsolatedDarwinSelfTest($certificate, $privateKey, $candidateManifest)
                : (new NativeTransportSelfTest())->verify($certificate, $privateKey, $candidateManifest);
        } catch (\Throwable $exception) {
            $this->printer->error(__('HTTP/3 真实 QUIC/TLS 自检无法执行：%{1}', [$exception->getMessage()]));
            return 1;
        }
        if (!($selfTest['ready'] ?? false)) {
            $this->printer->error(__('HTTP/3 真实 QUIC/TLS 自检失败：%{1}', [
                (string)($selfTest['reason'] ?? __('未知错误')),
            ]));
            return 1;
        }

        $selected = NativeTransportLibrary::selectInstalledVerified();
        if (!($selected['ready'] ?? false)) {
            $this->printer->error(__('构建后的 HTTP/3 组件未通过只读生产选择器：%{1}', [
                (string)($selected['reason'] ?? __('未知错误')),
            ]));
            return 1;
        }
        $manifest = \is_array($selected['manifest'] ?? null) ? $selected['manifest'] : [];
        if (!\hash_equals(
            (string)($candidateManifest['fingerprint'] ?? ''),
            (string)($manifest['fingerprint'] ?? ''),
        ) || !\hash_equals(
            (string)($candidateManifest['library_sha256'] ?? ''),
            (string)($manifest['library_sha256'] ?? ''),
        )) {
            $this->printer->error(__('只读生产选择器没有返回本次精确固定的 HTTP/3 候选组件。'));
            return 1;
        }
        $this->printer->success(__('HTTP/3 组件已构建、验证并发布；后续 server:start 只读复用。'));
        $this->printer->note(__('Fingerprint：%{1}', [(string)($manifest['fingerprint'] ?? '')]));
        $this->printer->note(__('SHA-256：%{1}', [(string)($manifest['library_sha256'] ?? '')]));
        return 0;
    }

    public function tip(): string
    {
        return __('显式构建并验证可选 HTTP/3 原生组件');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:http3:build',
            __('显式预构建并验证 WLS 可选 HTTP/3 原生组件；普通 server:start 不会调用本命令'),
            [
                '--ssl-cert <path>' => __('真实 QUIC/TLS 自检使用的证书文件；必须与 --ssl-key 同时提供'),
                '--ssl-key <path>' => __('真实 QUIC/TLS 自检使用的私钥文件；必须与 --ssl-cert 同时提供'),
            ],
            [
                __('前置条件') => __('当前 PHP 必须已经预装并启用 FFI；本命令不会安装 FFI，也不会修改 ffi.enable'),
                __('副作用') => __('可能联网下载固定版本源码，调用 Homebrew/apt/dnf/apk（必要时 sudo -n），在 WLS 私有缓存内编译并发布校验清单'),
                __('普通启动') => __('server:start 只读选择已安装且验证通过的组件；组件缺失或证据失效时继续使用 HTTP/2，并自动回退 HTTP/1.1'),
                __('适用平台') => __('仅支持 macOS/Linux Direct；Windows 固定使用 Dispatcher 的 HTTP/2/HTTP/1.1'),
                __('证书') => __('未传证书时为 localhost 准备本地自签证书，仅用于真实 QUIC/TLS loopback 自检'),
            ],
            [
                __('使用 localhost 证书构建并自检') => 'php bin/w server:http3:build',
                __('使用指定证书构建并自检') => 'php bin/w server:http3:build --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem',
            ]
        );
    }

    /** @return array{0:string,1:string} */
    private function certificatePair(array $args): array
    {
        $certificate = \trim((string)($args['ssl-cert'] ?? $args['ssl_cert'] ?? ''));
        $privateKey = \trim((string)($args['ssl-key'] ?? $args['ssl_key'] ?? ''));
        if (($certificate === '') !== ($privateKey === '')) {
            throw new \InvalidArgumentException((string)__('--ssl-cert 与 --ssl-key 必须同时提供。'));
        }
        if ($certificate !== '') {
            if (!\is_file($certificate) || !\is_file($privateKey)) {
                throw new \RuntimeException((string)__('指定的证书或私钥文件不存在。'));
            }
            return $this->canonicalCertificatePair($certificate, $privateKey);
        }

        $certificateResult = (new SslCertificateService(true))->ensureCertificate('localhost');
        $certificate = (string)($certificateResult['cert_path'] ?? '');
        $privateKey = (string)($certificateResult['key_path'] ?? '');
        if (!($certificateResult['success'] ?? false)
            || !\is_file($certificate) || !\is_file($privateKey)
        ) {
            throw new \RuntimeException((string)($certificateResult['message'] ?? __('无法为 localhost 准备自检证书。')));
        }
        return $this->canonicalCertificatePair($certificate, $privateKey);
    }

    /**
     * Run the first FFI load of a newly published Darwin candidate in a fresh
     * PHP process. A failed or killed child can only update last-error.json;
     * NativeTransportSelfTest remains the sole successful active publisher.
     *
     * @param array<string,mixed> $manifest
     * @return array{ready:bool,reason:string}
     */
    private function runIsolatedDarwinSelfTest(
        string $certificate,
        string $privateKey,
        array $manifest,
    ): array {
        $fingerprint = \strtolower(\trim((string)($manifest['fingerprint'] ?? '')));
        $librarySha256 = \strtolower(\trim((string)($manifest['library_sha256'] ?? '')));
        if (\preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $librarySha256) !== 1
        ) {
            return ['ready' => false, 'reason' => 'Darwin HTTP/3 candidate has no exact immutable identity.'];
        }
        $projectRoot = \defined('BP') ? \rtrim((string)\BP, '\\/') : '';
        $entrypoint = $projectRoot . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . 'w';
        if ($projectRoot === '' || !\is_file($entrypoint)) {
            return ['ready' => false, 'reason' => 'Darwin HTTP/3 verifier entrypoint is missing.'];
        }
        $result = (new NativeBuildProcessRunner())->run([
            \PHP_BINARY,
            $entrypoint,
            'server:http3:build',
            '--internal-verify-candidate=1',
            '--fingerprint=' . $fingerprint,
            '--library-sha256=' . $librarySha256,
            '--certificate-sha256=' . (string)\hash_file('sha256', $certificate),
            '--private-key-sha256=' . (string)\hash_file('sha256', $privateKey),
            '--ssl-cert=' . $certificate,
            '--ssl-key=' . $privateKey,
        ], 180, $projectRoot, $this->isolatedVerifierEnvironment(), false);
        if (!$result['success']) {
            $tail = \trim(\substr((string)$result['output'], -4096));
            return [
                'ready' => false,
                'reason' => 'Isolated Darwin HTTP/3 self-test failed with exit '
                    . (int)$result['exit_code'] . ($tail !== '' ? ': ' . $tail : '.'),
            ];
        }
        return ['ready' => true, 'reason' => 'Isolated Darwin HTTP/3 self-test process succeeded.'];
    }

    /** @return array<string,string> */
    private function isolatedVerifierEnvironment(): array
    {
        return [
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin:/opt/homebrew/bin:/usr/local/bin',
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'DYLD_FRAMEWORK_PATH' => '',
            'DYLD_FALLBACK_FRAMEWORK_PATH' => '',
            'DYLD_LIBRARY_PATH' => '',
            'DYLD_FALLBACK_LIBRARY_PATH' => '',
            'DYLD_INSERT_LIBRARIES' => '',
            'DYLD_ROOT_PATH' => '',
            'DYLD_IMAGE_SUFFIX' => '',
            'LD_PRELOAD' => '',
        ];
    }

    private function verifyCandidateInIsolatedProcess(array $args): int
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            return 1;
        }
        $fingerprint = \strtolower(\trim((string)($args['fingerprint'] ?? '')));
        $librarySha256 = \strtolower(\trim((string)($args['library-sha256'] ?? '')));
        $certificateSha256 = \strtolower(\trim((string)($args['certificate-sha256'] ?? '')));
        $privateKeySha256 = \strtolower(\trim((string)($args['private-key-sha256'] ?? '')));
        if (\preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $librarySha256) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $certificateSha256) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $privateKeySha256) !== 1
        ) {
            return 1;
        }
        try {
            [$certificate, $privateKey] = $this->canonicalCertificatePair(
                \trim((string)($args['ssl-cert'] ?? '')),
                \trim((string)($args['ssl-key'] ?? '')),
            );
            if (!\hash_equals($certificateSha256, (string)\hash_file('sha256', $certificate))
                || !\hash_equals($privateKeySha256, (string)\hash_file('sha256', $privateKey))
            ) {
                return 1;
            }
            $manifest = NativeTransportLibrary::pinSelfTestCandidate($fingerprint, $librarySha256);
            $selfTest = (new NativeTransportSelfTest())->verify($certificate, $privateKey, $manifest);
            return ($selfTest['ready'] ?? false) ? 0 : 1;
        } catch (\Throwable) {
            NativeTransportLibrary::reset();
            return 1;
        }
    }

    /** @return array{0:string,1:string} */
    private function canonicalCertificatePair(string $certificate, string $privateKey): array
    {
        $realCertificate = \realpath($certificate);
        $realPrivateKey = \realpath($privateKey);
        if (!\is_string($realCertificate) || !\is_string($realPrivateKey)
            || !\is_file($realCertificate) || !\is_file($realPrivateKey)
        ) {
            throw new \RuntimeException((string)__('证书或私钥无法解析为规范文件。'));
        }
        return [$realCertificate, $realPrivateKey];
    }
}
