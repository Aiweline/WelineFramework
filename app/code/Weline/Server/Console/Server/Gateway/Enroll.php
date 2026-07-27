<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;

final class Enroll extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $builder = new GatewayRegistrationBuilder();
        $root = \realpath((string)BP);
        if (!\is_string($root)) {
            $this->printer->error(__('无法解析项目根目录。'));
            return 1;
        }
        $roots = [$root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'];
        $extra = $args['cert-root'] ?? $args['cert_root'] ?? [];
        foreach ((array)$extra as $path) {
            $path = (string)$path;
            if (!\str_starts_with($path, '/') && \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) !== 1) {
                $path = $root . DIRECTORY_SEPARATOR . $path;
            }
            $roots[] = $path;
        }
        try {
            $response = $this->gateway()->request('enroll', [
                'project_uuid' => $builder->projectUuid(),
                'project_root' => $root,
                'certificate_roots' => $roots,
            ]);
            if (!($response['ok'] ?? false)) {
                $this->printer->error((string)($response['error']['message'] ?? __('授权失败')));
                return 1;
            }
            $this->printer->success(__('当前项目已授权到 WLS 2.0 宿主网关。'));
            return 0;
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
            return 1;
        }
    }

    public function tip(): string
    {
        return __('授权当前项目及其证书根目录到宿主网关');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:enroll',
            $this->tip(),
            ['--cert-root <path>' => __('附加项目内证书目录，可重复')],
            [],
            [],
        );
    }
}
