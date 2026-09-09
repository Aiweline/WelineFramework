<?php

declare(strict_types=1);

namespace Weline\Seo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Duplicate\ContentDuplicateScanner;
use Weline\Seo\Service\Duplicate\DuplicateCheckScope;
use Weline\Seo\Service\SeoWebsiteDirectory;

/**
 * Nightly sample scan of each website for near-duplicate body content.
 */
class ContentDuplicateScan implements CronTaskInterface
{
    public function name(): string
    {
        return 'SEO 站内重复内容扫描';
    }

    public function execute_name(): string
    {
        return 'seo_content_duplicate_scan';
    }

    public function tip(): string
    {
        return '按站点抽样扫描正文近重复，生成报告并通过 w_msg 附带报告查看地址';
    }

    public function cron_time(): string
    {
        return '0 4 * * *';
    }

    public function execute(): string
    {
        /** @var SeoWebsiteDirectory $directory */
        $directory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
        /** @var ContentDuplicateScanner $scanner */
        $scanner = ObjectManager::getInstance(ContentDuplicateScanner::class);

        $websites = $directory->listWebsites();
        $ran = 0;
        $issues = 0;
        $errors = 0;
        $reports = [];

        foreach ($websites as $website) {
            $websiteId = (int)($website['website_id'] ?? -1);
            if ($websiteId < 0) {
                continue;
            }
            try {
                $result = $scanner->scan(DuplicateCheckScope::fromArray([
                    'website_id' => $websiteId,
                    'mode' => DuplicateCheckScope::MODE_SAMPLE,
                    'sample_limit' => 500,
                    'notify' => true,
                ]));
                $ran++;
                $issues += (int)($result['issue_count'] ?? 0);
                if (!empty($result['report_url'])) {
                    $reports[] = (string)$result['report_url'];
                }
            } catch (\Throwable $e) {
                $errors++;
                w_log_error('seo_content_duplicate_scan failed website=' . $websiteId . ' ' . $e->getMessage());
            }
        }

        return \sprintf(
            'websites=%d ran=%d issues=%d errors=%d reports=%s',
            \count($websites),
            $ran,
            $issues,
            $errors,
            $reports === [] ? '-' : \implode(' | ', \array_slice($reports, 0, 5))
        );
    }
}
