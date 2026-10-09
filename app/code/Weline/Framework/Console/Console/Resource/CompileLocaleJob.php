<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Resource;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\View\TemplateCompileLocaleJob;

/**
 * Debug/manual entry for a locale compile job JSON (process-pool worker uses View/bin script).
 */
class CompileLocaleJob extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $jobPath = trim((string)($args['job'] ?? $args['--job'] ?? ''));
        if ($jobPath === '') {
            foreach ($args as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (str_starts_with($value, '--job=')) {
                    $jobPath = trim(substr($value, 6));
                    break;
                }
                if (str_ends_with($value, '.json') && is_file($value)) {
                    $jobPath = $value;
                    break;
                }
            }
        }
        if ($jobPath === '' || !is_file($jobPath)) {
            $this->printer->error(__('需要 --job=/path/to/job.json'));
            return 1;
        }

        $raw = file_get_contents($jobPath);
        if (!is_string($raw) || $raw === '') {
            $this->printer->error(__('无法读取 job JSON'));
            return 1;
        }
        try {
            $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($job)) {
                throw new \RuntimeException('invalid');
            }
            $compiled = TemplateCompileLocaleJob::run(
                $job,
                static function (int $done, int $total, string $locale, string $label): void {
                    TemplateCompileLocaleJob::emitProgressLine($done, $total, $locale, $label);
                },
            );
            $this->printer->success(__('语种编译完成：%{n} 个产物', ['n' => count($compiled)]));
            return 0;
        } catch (\Throwable $e) {
            $this->printer->error($e->getMessage());
            return 1;
        }
    }

    public function tip(): string
    {
        return __('执行语种模板编译 job（进程池工人 / 调试用）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'resource:compile-locale-job',
            $this->tip(),
            [
                '--job=<path>' => 'job JSON 绝对路径',
            ],
            [],
            [
                '工人脚本（池默认）' => 'php app/code/Weline/Framework/View/bin/compile-locale-job.php /tmp/job.json',
                'CLI 调试' => 'php bin/w resource:compile-locale-job --job=/tmp/job.json',
            ],
        );
    }
}
