<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployWebhookRefResolver;

final class DeployWebhookRefResolverTest extends TestCase
{
    public function testEmptyRefIsSkippedAsMissingRef(): void
    {
        $resolver = new DeployWebhookRefResolver();
        $result = $resolver->resolve('', [
            'deploy_trigger_mode' => DeployConfigService::TRIGGER_MODE_BRANCH,
            'webhook_branch' => 'dev',
        ]);

        self::assertTrue($result['skipped']);
        self::assertSame('missing_ref', $result['reason']);
    }

    public function testBareBranchNameStillDeploys(): void
    {
        $resolver = new DeployWebhookRefResolver();
        $result = $resolver->resolve('dev', [
            'deploy_trigger_mode' => DeployConfigService::TRIGGER_MODE_BRANCH,
            'webhook_branch' => 'dev',
        ]);

        self::assertFalse($result['skipped']);
        self::assertSame(DeployWebhookRefResolver::TYPE_BRANCH, $result['type']);
    }

    public function testDevHeadsRefDeploys(): void
    {
        $resolver = new DeployWebhookRefResolver();
        $result = $resolver->resolve('refs/heads/dev', [
            'deploy_trigger_mode' => DeployConfigService::TRIGGER_MODE_BRANCH,
            'webhook_branch' => 'dev',
        ]);

        self::assertFalse($result['skipped']);
        self::assertSame('refs/heads/dev', $result['ref']);
    }

    public function testOtherBranchIsMismatch(): void
    {
        $resolver = new DeployWebhookRefResolver();
        $result = $resolver->resolve('refs/heads/master', [
            'deploy_trigger_mode' => DeployConfigService::TRIGGER_MODE_BRANCH,
            'webhook_branch' => 'dev',
        ]);

        self::assertTrue($result['skipped']);
        self::assertSame('branch_mismatch', $result['reason']);
    }
}
