<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme\Scope;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\Scoped\ThemeScopeMigrationService;

final class Migrate extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $action = 'preflight';
        foreach ($args as $arg) {
            $candidate = strtolower(trim((string)$arg));
            if (in_array($candidate, ['preflight', 'apply'], true)) {
                $action = $candidate;
                break;
            }
        }

        try {
            $migration = ObjectManager::getInstance(ThemeScopeMigrationService::class);
            $result = $action === 'apply' ? $migration->apply() : $migration->preflight();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        if (($result['ok'] ?? false) === true) {
            $this->printer->success($encoded);
        } else {
            $this->printer->error($encoded);
        }

        return $encoded;
    }

    public function tip(): string
    {
        return 'Theme 2.1.1 Scope 继承迁移预检与幂等回填';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'theme:scope:migrate',
            $this->tip(),
            [
                'preflight' => '只读报告 Scope 碰撞、重复 UID、歧义节点与兼容 Scope',
                'apply' => '通过预检后回填 UID、规范 Scope、Global Theme 绑定与逐值 Patch',
            ],
            [],
            [
                'php bin/w theme:scope:migrate preflight',
                'php bin/w theme:scope:migrate apply',
            ],
        );
    }
}
