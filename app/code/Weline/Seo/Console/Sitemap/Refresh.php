<?php

declare(strict_types=1);

namespace Weline\Seo\Console\Sitemap;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Api\Sitemap\SitemapBakeSource;
use Weline\Seo\Service\SeoWebsiteDirectory;

/**
 * php bin/w sitemap:refresh --website=CODE|ID
 */
final class Refresh extends CommandAbstract implements CommandInterface
{
    public function tip(): string
    {
        return (string)__('Refresh sitemap URL sync (+ file generate when store mode allows) for one website');
    }

    public function help(): array|string
    {
        return [
            'php bin/w sitemap:refresh --website=CODE|ID' => (string)__('Single-site sitemap refresh (sync table; may hard_gate file publish in dev/test)'),
            '--module=Vendor_Module' => (string)__('Optional: only sync one SitemapUrlProvider module'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        // 选项只可能来自命令行：$data 是命令注册表元数据（含 'module' => 'Weline_Seo'），
        // 绝不能当作 provider 过滤值——否则 refresh 会跳过所有 provider，只把表里旧数据
        // 重新发布一遍，表现为「1 秒内成功但什么都没同步」。
        $websiteArg = \trim((string)($args['website'] ?? ''));
        if ($websiteArg === '') {
            foreach ($args as $arg) {
                if (\is_string($arg) && \str_starts_with($arg, '--website=')) {
                    $websiteArg = \trim(\substr($arg, 10));
                    break;
                }
            }
        }
        $module = \trim((string)($args['module'] ?? ''));
        if ($module === '') {
            foreach ($args as $arg) {
                if (\is_string($arg) && \str_starts_with($arg, '--module=')) {
                    $module = \trim(\substr($arg, 9));
                    break;
                }
            }
        }
        if ($websiteArg === '') {
            $this->printer->error((string)__('Required: --website=CODE|ID'));

            return 1;
        }

        /** @var SeoWebsiteDirectory $directory */
        $directory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
        $websiteId = $this->resolveWebsiteId($directory, $websiteArg);
        if ($websiteId < 0) {
            $this->printer->error((string)__('Website not found: %{1}', [$websiteArg]));

            return 1;
        }

        /** @var SitemapBakeSource $source */
        $source = ObjectManager::getInstance(SitemapBakeSource::class);
        $result = $source->refreshForBake($websiteId, $module);
        if (!($result['ok'] ?? false)) {
            $this->printer->error((string)__('Sitemap refresh failed: %{1}', [(string)($result['message'] ?? 'unknown')]));

            return 1;
        }
        $locs = $source->listActiveLocs($websiteId);
        if (!empty($result['hard_gate'])) {
            $this->printer->warning((string)__(
                'Store mode hard_gate: indexable sitemap files not materialized; table sync kept. active_locs=%{1}',
                [(string)\count($locs)]
            ));
        } else {
            $this->printer->success((string)__(
                'Sitemap refreshed for website_id=%{1}; active_locs=%{2}',
                [(string)$websiteId, (string)\count($locs)]
            ));
        }

        return 0;
    }

    private function resolveWebsiteId(SeoWebsiteDirectory $directory, string $arg): int
    {
        if (\ctype_digit($arg)) {
            $id = (int)$arg;
            $row = $directory->getWebsiteById($id);

            return \is_array($row) ? $id : -1;
        }
        $code = \strtolower($arg);
        foreach ($directory->listWebsites() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (\strtolower((string)($row['code'] ?? '')) === $code) {
                return (int)($row['website_id'] ?? $row['id'] ?? -1);
            }
        }

        return -1;
    }
}
