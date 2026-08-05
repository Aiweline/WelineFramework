<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge;

use PHPUnit\Framework\TestCase;

final class CertificateMaterialUpdateCoordinatorTest extends TestCase
{
    public function testStaticContractPublishesWholeProjectStateBeforeTargetedReload(): void
    {
        $source = $this->source();
        $manifest = \strpos($source, '$builder->buildServingManifest($instanceName)');
        $published = \strpos($source, '$publishedInstances[$instanceName] = $authorizedFence');
        $reload = \strpos($source, 'reloadSslCertAndWait(');

        self::assertIsInt($manifest);
        self::assertIsInt($published);
        self::assertIsInt($reload);
        self::assertLessThan($published, $manifest);
        self::assertLessThan($reload, $published);
        self::assertStringContainsString('$gatewayIntentErrors[]', $source);
        self::assertStringContainsString('$manifestErrors[]', $source);
        self::assertStringContainsString(
            '$failures[] = \'gateway renewal intent: \'',
            $source,
        );
        self::assertStringContainsString('$operationId = \\bin2hex(\\random_bytes(16))', $source);
        self::assertStringContainsString("['manifest_generation']", $source);
        self::assertStringContainsString("['manifest_digest']", $source);
        self::assertStringContainsString("['operation_id']", $source);
        self::assertStringContainsString("['expected_manifest_generation']", $source);
        self::assertStringContainsString("['expected_manifest_digest']", $source);
        self::assertStringContainsString("['eligible_workers']", $source);
        self::assertStringContainsString("['acked_workers']", $source);
        self::assertStringContainsString("['failed_workers']", $source);
    }

    public function testNativeReloadWaitRequiresAnOldTlsServingFence(): void
    {
        $source = $this->source();
        self::assertStringContainsString(
            "['native_reload_required'] ?? false) === true",
            $source,
        );
        self::assertStringContainsString('if ($nativePublications !== [])', $source);
        self::assertStringContainsString('explicitPureWlsServingEndpoint(', $source);
        self::assertStringContainsString('fallbackWlsIsServing($endpoint)', $source);
        self::assertStringContainsString("'NATIVE_EDGE_DRAINING'", $source);
        self::assertStringContainsString(
            'GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS',
            $source,
        );
        self::assertStringContainsString("['ACTIVE', 'DRAINING']", $source);
        self::assertStringNotContainsString('reloadSslCert($domains, $instanceName)', $source);
    }

    public function testStaticContractRequiresLiveLegacyMasterBeforeManagedReload(): void
    {
        $source = $this->source();
        $legacy = \strpos($source, 'if ($explicitLegacy)');
        self::assertIsInt($legacy);
        $legacyBlock = \substr($source, $legacy, 2300);
        $lease = \strpos($legacyBlock, 'validateRunningLease(');
        $authorize = \strpos($legacyBlock, "['authorized']");
        $enableReload = \strpos($legacyBlock, '$legacyManaged = true');

        self::assertIsInt($lease);
        self::assertIsInt($authorize);
        self::assertIsInt($enableReload);
        self::assertLessThan($authorize, $lease);
        self::assertLessThan($enableReload, $authorize);
        self::assertStringNotContainsString(
            "if (\$explicitLegacy) {\n                \$legacyManaged = true;",
            $source,
        );
        self::assertStringContainsString('$legacyCompatibilityProbe', $source);
        self::assertStringContainsString(
            '$sourceAdapter === EdgeAdapterInterface::NAME_NGINX',
            $source,
        );
        self::assertStringContainsString(
            'legacy managed Nginx is not installed or no longer owned',
            $source,
        );
    }

    public function testAdaptersDeclareTheirCertificateUpdateSourceMode(): void
    {
        $root = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/Edge/';
        $nginx = \file_get_contents($root . 'NginxEdgeAdapter.php');
        $native = \file_get_contents($root . 'WlsNativeEdgeAdapter.php');
        self::assertIsString($nginx);
        self::assertIsString($native);
        self::assertStringContainsString(
            'notify($domain, $paths, self::NAME_NGINX)',
            $nginx,
        );
        self::assertStringContainsString(
            'notify($domain, $paths, self::NAME_WLS)',
            $native,
        );
    }

    private function source(): string
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/Edge/CertificateMaterialUpdateCoordinator.php';
        $source = \file_get_contents($path);
        self::assertIsString($source);
        return $source;
    }
}
