<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayNativeNginxTestContractTest extends TestCase
{
    public function testProductionPublicationUsesNativeCandidateTest(): void
    {
        $publish = self::controllerMethodSource('publishIfDirty');

        self::assertStringContainsString(
            '$this->brokerActionsRequired()'
                . "\n            ? \$this->nativeNginxCandidateTest(",
            $publish,
        );
        self::assertStringContainsString(
            ': $this->runNginx([\'-t\', \'-c\', $candidate]',
            $publish,
        );
        self::assertStringContainsString(
            '// Native NGINX_RELOAD performs a fenced TEST before it',
            $publish,
        );
        self::assertStringContainsString(
            "(string)(\$test['config_digest'] ?? '')",
            $publish,
        );
        self::assertStringContainsString(
            "\\hash('sha256', \$candidateConfig)",
            $publish,
        );
    }

    public function testLkgRollbackPersistsOneShotBoundIntentBeforeNativeTest(): void
    {
        $rollback = self::controllerMethodSource('rollbackToLkg');
        $native = self::controllerMethodSource('nativeNginxCandidateTest');

        self::assertMatchesRegularExpression(
            '/\$this->state\[\'routes\'\] = \$currentRoutes;\s*'
                . '\$this->state\[\'generation\'\] = \$currentGeneration;\s*'
                . '\}\s*\$test = \$this->brokerActionsRequired\(\)\s*'
                . '\? \$this->nativeNginxCandidateTest\(/s',
            $rollback,
        );
        self::assertStringContainsString(
            "\$this->state['native_nginx_test_intent'] = \$lkgIntent;",
            $native,
        );
        self::assertStringContainsString(
            "unset(\$this->state['native_nginx_test_intent']);",
            $native,
        );
        self::assertStringContainsString('finally {', $native);
        self::assertStringNotContainsString('$this->runNginx(', $native);
        self::assertStringContainsString(
            "'config_digest' => \$candidateDigest",
            $native,
        );
        self::assertStringContainsString(
            "(string)(\$test['config_digest'] ?? '')",
            $rollback,
        );
    }

    public function testLkgIntentCanonicalBindsRetainedBundleAndCurrentFence(): void
    {
        $source = self::controllerMethodSource('nativeNginxCandidateTest');
        $fields = [
            'schema_version=1',
            'phase=LKG_ROLLBACK',
            'publication_generation=',
            'candidate_config_digest=',
            'candidate_config_path_digest=',
            'target_config_path_digest=',
            'source_lkg_manifest_relative=',
            'source_lkg_manifest_path_digest=',
            'source_lkg_manifest_digest=',
            'source_config_digest=',
            'source_routes_digest=',
            'gateway_epoch=',
            'host_boot_id=',
            'runtime_generation=',
            'created_at_monotonic_ms=',
        ];
        $offset = \strpos($source, 'WLS-NGINX-LKG-TEST-INTENT/1');
        self::assertIsInt($offset);
        foreach ($fields as $field) {
            $next = \strpos($source, $field, $offset);
            self::assertIsInt($next, 'Missing LKG intent field: ' . $field);
            self::assertGreaterThan($offset, $next);
            $offset = $next;
        }
        self::assertStringContainsString(
            "\$lkgIntent['intent_digest'] = \\hash('sha256', \$intentCanonical);",
            $source,
        );
        self::assertStringContainsString("'source_lkg_manifest_relative'", $source);
        self::assertStringContainsString("'source_config_digest'", $source);
        self::assertStringContainsString("'source_routes_digest'", $source);
    }

    private static function controllerMethodSource(string $method): string
    {
        if (!\class_exists('WlsEdgeGatewayController', false)) {
            if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
                \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
            }
            require \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php';
        }
        $reflection = new \ReflectionMethod('WlsEdgeGatewayController', $method);
        $lines = \file((string)$reflection->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
