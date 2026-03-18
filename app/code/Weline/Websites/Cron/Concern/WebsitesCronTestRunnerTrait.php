<?php
declare(strict_types=1);

namespace Weline\Websites\Cron\Concern;

use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * Cron 手动测试：将 CLI 传入的 $options 交给 {@see WebsitesCronTestContext} 后执行 {@see execute()}。
 */
trait WebsitesCronTestRunnerTrait
{
    /**
     * @param array<string, mixed> $options 含 domain、verbose、hourly 及任意自定义键
     */
    public function test(array $options): string
    {
        $domain = isset($options['domain']) ? (string) $options['domain'] : null;
        if ($domain === '') {
            $domain = null;
        }
        $verbose = !empty($options['verbose']);
        $hourly = !empty($options['hourly']);

        WebsitesCronTestContext::begin($domain, $verbose, $hourly);
        try {
            return $this->execute();
        } finally {
            WebsitesCronTestContext::end();
        }
    }
}
