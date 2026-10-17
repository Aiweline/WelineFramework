<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionMethod as ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability\FpcCapability;
use Weline\Framework\Http\ContentEncodingNegotiator;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Http\ErrorPageRenderer;
use Weline\Framework\Http\Fpc\Bake\FpcConnectOnlyHttpClient;
use Weline\Framework\Http\Fpc\Bake\LoadTargetConcurrencyController;
use Weline\Framework\Http\Fpc\Bake\SitemapFpcBakeEngine;
use Weline\Framework\Http\Fpc\FpcBypassEvaluator;
use Weline\Framework\Http\Fpc\FpcBypassFactsBuilder;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Http\Fpc\FpcMode;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Framework\Http\Fpc\FpcStaticDiskStore;
use Weline\Framework\Http\Fpc\FpcStoreAdapterInterface;
use Weline\Framework\Http\Fpc\FpcStoreAdapterRegistry;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeExporter;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeImporter;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeOrigin;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeSchema;
use Weline\Framework\Http\Fpc\SharedFpcSetCookieSuppress;
use Weline\Framework\Http\Fpc\StaticAssetBrotliSidecar;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Security\SecurityHeaderDefaults;
use Weline\Framework\Http\StaticErrorPagePublisher;
use Weline\Framework\Http\StorefrontGenerationOutbound;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\ProjectRoot;
use Weline\Server\Extends\Module\Weline_Framework\Fpc\Bypass\WlsTransportFpcBypassProvider;
use Weline\Theme\Extends\Module\Weline_Framework\Fpc\Bypass\ThemeEditorFpcBypassProvider;

/**
 * Aggregated contract suite (merged from 27 *ContractTest files).
 * Observe one overall pass/fail via testAllHttpContractsPass().
 */
final class HttpContractsTest extends TestCase
{
    public function testAllHttpContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BackendUrlLocalePrefix::testGetBackendUrlKeepsNonDefaultLanguageSegment' => function () {
            $this->setUp_BackendUrlLocalePrefix();
            try {
                $this->assert_BackendUrlLocalePrefix_testGetBackendUrlKeepsNonDefaultLanguageSegment();
            } finally {
                $this->tearDown_BackendUrlLocalePrefix();
            }
        },
            'BackendUrlLocalePrefix::testGetBackendUrlOmitsDefaultLanguageSegment' => function () {
            $this->setUp_BackendUrlLocalePrefix();
            try {
                $this->assert_BackendUrlLocalePrefix_testGetBackendUrlOmitsDefaultLanguageSegment();
            } finally {
                $this->tearDown_BackendUrlLocalePrefix();
            }
        },
            'BackendUrlLocalePrefix::testBackendDefaultLanguageIsIndependentOfWebsiteLanguage' => function () {
            $this->setUp_BackendUrlLocalePrefix();
            try {
                $this->assert_BackendUrlLocalePrefix_testBackendDefaultLanguageIsIndependentOfWebsiteLanguage();
            } finally {
                $this->tearDown_BackendUrlLocalePrefix();
            }
        },
            'BinaryOutputGuard::testGuardSourceUsesFiberCaptureAndNoPersistentStackDrain' => function () {
            $this->assert_BinaryOutputGuard_testGuardSourceUsesFiberCaptureAndNoPersistentStackDrain();
        },
            'BinaryOutputGuard::testBinQueryAndQueryBinDelegateToBinaryOutputGuard' => function () {
            $this->assert_BinaryOutputGuard_testBinQueryAndQueryBinDelegateToBinaryOutputGuard();
        },
            'BinaryOutputGuard::testPcControllerFetchJsonPersistentSkipsObClean' => function () {
            $this->assert_BinaryOutputGuard_testPcControllerFetchJsonPersistentSkipsObClean();
        },
            'SitemapFpcBakeEngine::testRewriteOriginKeepsPathAndSwapsHost' => function () {
            $this->assert_SitemapFpcBakeEngine_testRewriteOriginKeepsPathAndSwapsHost();
        },
            'SitemapFpcBakeEngine::testConnectPeerMustNotEqualBakeOriginHost' => function () {
            $this->assert_SitemapFpcBakeEngine_testConnectPeerMustNotEqualBakeOriginHost();
        },
            'SitemapFpcBakeEngine::testDryRunPlansRewrittenUrlsWithoutHttp' => function () {
            $this->assert_SitemapFpcBakeEngine_testDryRunPlansRewrittenUrlsWithoutHttp();
        },
            'SitemapFpcBakeEngine::testEngineSourceHasNoSeoOrWebsitesImports' => function () {
            $this->assert_SitemapFpcBakeEngine_testEngineSourceHasNoSeoOrWebsitesImports();
        },
            'SitemapFpcBakeEngine::testLoadTargetControllerFullPowerPinsMax' => function () {
            $this->assert_SitemapFpcBakeEngine_testLoadTargetControllerFullPowerPinsMax();
        },
            'SitemapFpcBakeEngine::testBusyGateAndLoadTargetAreSeparateClasses' => function () {
            $this->assert_SitemapFpcBakeEngine_testBusyGateAndLoadTargetAreSeparateClasses();
        },
            'FpcBypassAndStore::testEvaluatorBypassesEditorQueryAndLivePreviewPath' => function () {
            try {
                $this->assert_FpcBypassAndStore_testEvaluatorBypassesEditorQueryAndLivePreviewPath();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testBuiltinFallbackPinsDeployWebhookPierceGate' => function () {
            try {
                $this->assert_FpcBypassAndStore_testBuiltinFallbackPinsDeployWebhookPierceGate();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testCoordinatorHardExcludesDeployWebhookOnFpmOnly' => function () {
            try {
                $this->assert_FpcBypassAndStore_testCoordinatorHardExcludesDeployWebhookOnFpmOnly();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testCollectorPreservesRequirePierceStamp' => function () {
            try {
                $this->assert_FpcBypassAndStore_testCollectorPreservesRequirePierceStamp();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testThemeAndWlsProvidersShareBuiltinFallbackIds' => function () {
            try {
                $this->assert_FpcBypassAndStore_testThemeAndWlsProvidersShareBuiltinFallbackIds();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testCollectorNormalizesUriPathPrefixes' => function () {
            try {
                $this->assert_FpcBypassAndStore_testCollectorNormalizesUriPathPrefixes();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testFpcCapabilitySourceDoesNotTouchCacheManagerPools' => function () {
            try {
                $this->assert_FpcBypassAndStore_testFpcCapabilitySourceDoesNotTouchCacheManagerPools();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testStoreAdapterInterfacePrefixAndWlsCode' => function () {
            try {
                $this->assert_FpcBypassAndStore_testStoreAdapterInterfacePrefixAndWlsCode();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassAndStore::testEvaluatorEmptyRulesDoesNotBypassGuestTraffic' => function () {
            try {
                $this->assert_FpcBypassAndStore_testEvaluatorEmptyRulesDoesNotBypassGuestTraffic();
            } finally {
                $this->tearDown_FpcBypassAndStore();
            }
        },
            'FpcBypassFactsBuilder::testBuildIncludesEditorAndCdnEnvKeys' => function () {
            $this->assert_FpcBypassFactsBuilder_testBuildIncludesEditorAndCdnEnvKeys();
        },
            'FpcBypassFactsBuilder::testCdnDevModeFlagTriggersBypassWithProviderRule' => function () {
            $this->assert_FpcBypassFactsBuilder_testCdnDevModeFlagTriggersBypassWithProviderRule();
        },
            'FpcGeneratedNotUnderAppCode::testHtmlDiskRootIsRepoGeneratedNotAppCodeGenerated' => function () {
            $this->assert_FpcGeneratedNotUnderAppCode_testHtmlDiskRootIsRepoGeneratedNotAppCodeGenerated();
        },
            'FpcHtmlDiskStore::testPublishReadAndPurgeUrls' => function () {
            $this->setUp_FpcHtmlDiskStore();
            try {
                $this->assert_FpcHtmlDiskStore_testPublishReadAndPurgeUrls();
            } finally {
                $this->tearDown_FpcHtmlDiskStore();
            }
        },
            'FpcHtmlDiskStore::testBrotliPublishIsBrOnlyAndReadableWithoutAcceptBr' => function () {
            $this->setUp_FpcHtmlDiskStore();
            try {
                $this->assert_FpcHtmlDiskStore_testBrotliPublishIsBrOnlyAndReadableWithoutAcceptBr();
            } finally {
                $this->tearDown_FpcHtmlDiskStore();
            }
        },
            'FpcHtmlDiskStore::testAnyQueryIsPathKeyedOnly' => function () {
            $this->setUp_FpcHtmlDiskStore();
            try {
                $this->assert_FpcHtmlDiskStore_testAnyQueryIsPathKeyedOnly();
            } finally {
                $this->tearDown_FpcHtmlDiskStore();
            }
        },
            'FpcHtmlDiskStore::testSoftPurgeAllKeepsPreviousAndRollbackRestores' => function () {
            $this->setUp_FpcHtmlDiskStore();
            try {
                $this->assert_FpcHtmlDiskStore_testSoftPurgeAllKeepsPreviousAndRollbackRestores();
            } finally {
                $this->tearDown_FpcHtmlDiskStore();
            }
        },
            'FpcHtmlDiskStore::testStagingSeedWarmWritesDoNotFlipLiveUntilPromote' => function () {
            $this->setUp_FpcHtmlDiskStore();
            try {
                $this->assert_FpcHtmlDiskStore_testStagingSeedWarmWritesDoNotFlipLiveUntilPromote();
            } finally {
                $this->tearDown_FpcHtmlDiskStore();
            }
        },
            'FpcHtmlDiskStore::testAbandonStagingKeepsLive' => function () {
            $this->setUp_FpcHtmlDiskStore();
            try {
                $this->assert_FpcHtmlDiskStore_testAbandonStagingKeepsLive();
            } finally {
                $this->tearDown_FpcHtmlDiskStore();
            }
        },
            'FpcHtmlDiskStore::testCoordinatorExternalizeNoLongerRemembersHashPayloadIndex' => function () {
            $this->setUp_FpcHtmlDiskStore();
            try {
                $this->assert_FpcHtmlDiskStore_testCoordinatorExternalizeNoLongerRemembersHashPayloadIndex();
            } finally {
                $this->tearDown_FpcHtmlDiskStore();
            }
        },
            'FpcHtmlDiskStorePurgeSlugs::testPurgeSlugsRemovesBareAndLocaleLeaves' => function () {
            $this->setUp_FpcHtmlDiskStorePurgeSlugs();
            try {
                $this->assert_FpcHtmlDiskStorePurgeSlugs_testPurgeSlugsRemovesBareAndLocaleLeaves();
            } finally {
                $this->tearDown_FpcHtmlDiskStorePurgeSlugs();
            }
        },
            'FpcHtmlDiskStorePurgeSlugs::testAdapterInterfaceDeclaresPurgeSlugs' => function () {
            $this->setUp_FpcHtmlDiskStorePurgeSlugs();
            try {
                $this->assert_FpcHtmlDiskStorePurgeSlugs_testAdapterInterfaceDeclaresPurgeSlugs();
            } finally {
                $this->tearDown_FpcHtmlDiskStorePurgeSlugs();
            }
        },
            'FpcHtmlDiskStorePurgeSlugs::testPipelineAndExpanderKeepForceReloadFalse' => function () {
            $this->setUp_FpcHtmlDiskStorePurgeSlugs();
            try {
                $this->assert_FpcHtmlDiskStorePurgeSlugs_testPipelineAndExpanderKeepForceReloadFalse();
            } finally {
                $this->tearDown_FpcHtmlDiskStorePurgeSlugs();
            }
        },
            'FpcMode::testAppendPierceWhenEnabledAndSkipWhenPresent' => function () {
            $this->setUp_FpcMode();
            try {
                $this->assert_FpcMode_testAppendPierceWhenEnabledAndSkipWhenPresent();
            } finally {
                $this->tearDown_FpcMode();
            }
        },
            'FpcMode::testAppendSkipsMailto' => function () {
            $this->setUp_FpcMode();
            try {
                $this->assert_FpcMode_testAppendSkipsMailto();
            } finally {
                $this->tearDown_FpcMode();
            }
        },
            'FpcMode::testStatusExposesPierceFields' => function () {
            $this->setUp_FpcMode();
            try {
                $this->assert_FpcMode_testStatusExposesPierceFields();
            } finally {
                $this->tearDown_FpcMode();
            }
        },
            'FpcMode::testAbsentEnvDefaultsAutoPierceOff' => function () {
            $this->setUp_FpcMode();
            try {
                $this->assert_FpcMode_testAbsentEnvDefaultsAutoPierceOff();
            } finally {
                $this->tearDown_FpcMode();
            }
        },
            'FpcPierceStamp::testEnsureAndValidateCookieOrQuery' => function () {
            $this->setUp_FpcPierceStamp();
            try {
                $this->assert_FpcPierceStamp_testEnsureAndValidateCookieOrQuery();
            } finally {
                $this->tearDown_FpcPierceStamp();
            }
        },
            'FpcPierceStamp::testDiagQueryBypassRequiresPierceStamp' => function () {
            $this->setUp_FpcPierceStamp();
            try {
                $this->assert_FpcPierceStamp_testDiagQueryBypassRequiresPierceStamp();
            } finally {
                $this->tearDown_FpcPierceStamp();
            }
        },
            'FpcPierceStamp::testPreviewPathStillBypassesWithoutPierceStamp' => function () {
            $this->setUp_FpcPierceStamp();
            try {
                $this->assert_FpcPierceStamp_testPreviewPathStillBypassesWithoutPierceStamp();
            } finally {
                $this->tearDown_FpcPierceStamp();
            }
        },
            'FpcStaticDiskStore::testPublishFromAppCodeMirrorAndSkipPub' => function () {
            $this->setUp_FpcStaticDiskStore();
            try {
                $this->assert_FpcStaticDiskStore_testPublishFromAppCodeMirrorAndSkipPub();
            } finally {
                $this->tearDown_FpcStaticDiskStore();
            }
        },
            'FpcStaticDiskStore::testPurgeAllLiveRemovesMirroredLeaves' => function () {
            $this->setUp_FpcStaticDiskStore();
            try {
                $this->assert_FpcStaticDiskStore_testPurgeAllLiveRemovesMirroredLeaves();
            } finally {
                $this->tearDown_FpcStaticDiskStore();
            }
        },
            'FpcStaticDiskStore::testUriFromAppCodeAbsoluteAndRepublishOnAllHosts' => function () {
            $this->setUp_FpcStaticDiskStore();
            try {
                $this->assert_FpcStaticDiskStore_testUriFromAppCodeAbsoluteAndRepublishOnAllHosts();
            } finally {
                $this->tearDown_FpcStaticDiskStore();
            }
        },
            'FpcStaticDiskStore::testPublishFromFileEarlyOutStillRefreshesStaleBrotliSidecar' => function () {
            $this->setUp_FpcStaticDiskStore();
            try {
                $this->assert_FpcStaticDiskStore_testPublishFromFileEarlyOutStillRefreshesStaleBrotliSidecar();
            } finally {
                $this->tearDown_FpcStaticDiskStore();
            }
        },
            'FpcStaticDiskStore::testRepublishHashMatchStillRefreshesStaleBrotliSidecar' => function () {
            $this->setUp_FpcStaticDiskStore();
            try {
                $this->assert_FpcStaticDiskStore_testRepublishHashMatchStillRefreshesStaleBrotliSidecar();
            } finally {
                $this->tearDown_FpcStaticDiskStore();
            }
        },
            'FpcVarNotUnderAppCode::testPayloadPathIndexUsesRepoVarNotAppCodeVar' => function () {
            $this->assert_FpcVarNotUnderAppCode_testPayloadPathIndexUsesRepoVarNotAppCodeVar();
        },
            'FpcOfflineBakeGates::testOriginExactMatchAndUrlBelonging' => function () {
            $this->setUp_FpcOfflineBakeGates();
            $this->assert_FpcOfflineBakeGates_testOriginExactMatchAndUrlBelonging();
        },
            'FpcOfflineBakeGates::testSchemaRejectsPathOnlyAndCrossWebsite' => function () {
            $this->setUp_FpcOfflineBakeGates();
            $this->assert_FpcOfflineBakeGates_testSchemaRejectsPathOnlyAndCrossWebsite();
        },
            'FpcOfflineBakeGates::testImportRejectsForeignWebsiteAndPathOnlyPackage' => function () {
            $this->setUp_FpcOfflineBakeGates();
            $this->assert_FpcOfflineBakeGates_testImportRejectsForeignWebsiteAndPathOnlyPackage();
        },
            'FpcOfflineBakeGates::testExportImportRoundTripHostScopedAndLangHints' => function () {
            $this->setUp_FpcOfflineBakeGates();
            $this->assert_FpcOfflineBakeGates_testExportImportRoundTripHostScopedAndLangHints();
        },
            'SharedFpcSetCookieSuppress::testArmClearsPendingCookiesAndBlocksFurtherSetCookie' => function () {
            $this->setUp_SharedFpcSetCookieSuppress();
            try {
                $this->assert_SharedFpcSetCookieSuppress_testArmClearsPendingCookiesAndBlocksFurtherSetCookie();
            } finally {
                $this->tearDown_SharedFpcSetCookieSuppress();
            }
        },
            'SharedFpcSetCookieSuppress::testFromCacheAloneArmsSuppress' => function () {
            $this->setUp_SharedFpcSetCookieSuppress();
            try {
                $this->assert_SharedFpcSetCookieSuppress_testFromCacheAloneArmsSuppress();
            } finally {
                $this->tearDown_SharedFpcSetCookieSuppress();
            }
        },
            'SharedFpcSetCookieSuppress::testWiringSourceCoversBuildHitAndPublish' => function () {
            $this->setUp_SharedFpcSetCookieSuppress();
            try {
                $this->assert_SharedFpcSetCookieSuppress_testWiringSourceCoversBuildHitAndPublish();
            } finally {
                $this->tearDown_SharedFpcSetCookieSuppress();
            }
        },
            'StaticAssetBrotliSidecar::testPublishWritesBrForLargeCssAndSkipsTiny' => function () {
            $this->setUp_StaticAssetBrotliSidecar();
            try {
                $this->assert_StaticAssetBrotliSidecar_testPublishWritesBrForLargeCssAndSkipsTiny();
            } finally {
                $this->tearDown_StaticAssetBrotliSidecar();
            }
        },
            'ResponseObservabilityLean::testProcessTimingInjectorsCallPolicy' => function () {
            $this->assert_ResponseObservabilityLean_testProcessTimingInjectorsCallPolicy();
        },
            'ResponseObservabilityLean::testWorkerIdentityHeadersCallPolicy' => function () {
            $this->assert_ResponseObservabilityLean_testWorkerIdentityHeadersCallPolicy();
        },
            'ResponseObservabilityLean::testRedirectCookieDiagHeaderIsGated' => function () {
            $this->assert_ResponseObservabilityLean_testRedirectCookieDiagHeaderIsGated();
        },
            'ResponseObservabilityLean::testAdminPerfHeadersCallPolicy' => function () {
            $this->assert_ResponseObservabilityLean_testAdminPerfHeadersCallPolicy();
        },
            'ResponseObservabilityLean::testResponsePoweredByIsGated' => function () {
            $this->assert_ResponseObservabilityLean_testResponsePoweredByIsGated();
        },
            'ResponseObservabilityLean::testFpcHitPerformanceHelperIsGated' => function () {
            $this->assert_ResponseObservabilityLean_testFpcHitPerformanceHelperIsGated();
        },
            'ResponseObservabilityLean::testPolicyDocumentsPublicAllowlist' => function () {
            $this->assert_ResponseObservabilityLean_testPolicyDocumentsPublicAllowlist();
        },
            'SecurityHeaderDefaults::testSafeCspDefaultIsNonEmptyAndAdminCompatible' => function () {
            $this->assert_SecurityHeaderDefaults_testSafeCspDefaultIsNonEmptyAndAdminCompatible();
        },
            'SecurityHeaderDefaults::testReportOnlyDefaultIsOptInEmpty' => function () {
            $this->assert_SecurityHeaderDefaults_testReportOnlyDefaultIsOptInEmpty();
        },
            'SecurityHeaderDefaults::testCorsDefaultDeniesCrossOriginEcho' => function () {
            $this->assert_SecurityHeaderDefaults_testCorsDefaultDeniesCrossOriginEcho();
        },
            'SecurityHeaderDefaults::testEnvSampleAndTemplateCarrySameSafeDefaults' => function () {
            $this->assert_SecurityHeaderDefaults_testEnvSampleAndTemplateCarrySameSafeDefaults();
        },
            'StaticErrorPagePublisher::testPublisherUsesFiberEventsAndAtomicHostMap' => function () {
            $this->assert_StaticErrorPagePublisher_testPublisherUsesFiberEventsAndAtomicHostMap();
        },
            'StaticErrorPagePublisher::testErrorPageRendererForwardsHost' => function () {
            $this->assert_StaticErrorPagePublisher_testErrorPageRendererForwardsHost();
        },
            'StaticErrorPagePublisher::testDefaultConcurrencyIsFour' => function () {
            $this->assert_StaticErrorPagePublisher_testDefaultConcurrencyIsFour();
        },
            'StorefrontGenerationOutbound::testCookieNameIsProtocolExactWireName' => function () {
            $this->setUp_StorefrontGenerationOutbound();
            try {
                $this->assert_StorefrontGenerationOutbound_testCookieNameIsProtocolExactWireName();
            } finally {
                $this->tearDown_StorefrontGenerationOutbound();
            }
        },
            'StorefrontGenerationOutbound::testApplySetsReadableCookieAndVaryCookieHeaderName' => function () {
            $this->setUp_StorefrontGenerationOutbound();
            try {
                $this->assert_StorefrontGenerationOutbound_testApplySetsReadableCookieAndVaryCookieHeaderName();
            } finally {
                $this->tearDown_StorefrontGenerationOutbound();
            }
        },
            'StorefrontGenerationOutbound::testSharedFpcSuppressBlocksIdentitySetCookie' => function () {
            $this->setUp_StorefrontGenerationOutbound();
            try {
                $this->assert_StorefrontGenerationOutbound_testSharedFpcSuppressBlocksIdentitySetCookie();
            } finally {
                $this->tearDown_StorefrontGenerationOutbound();
            }
        },
            'StorefrontGenerationOutbound::testResponsePrepareForEmissionWiresOutboundHelper' => function () {
            $this->setUp_StorefrontGenerationOutbound();
            try {
                $this->assert_StorefrontGenerationOutbound_testResponsePrepareForEmissionWiresOutboundHelper();
            } finally {
                $this->tearDown_StorefrontGenerationOutbound();
            }
        },
            'StorefrontGenerationOutbound::testOutboundResolveVersionIdOnlyUsesStorefrontGenerationIdentity' => function () {
            $this->setUp_StorefrontGenerationOutbound();
            try {
                $this->assert_StorefrontGenerationOutbound_testOutboundResolveVersionIdOnlyUsesStorefrontGenerationIdentity();
            } finally {
                $this->tearDown_StorefrontGenerationOutbound();
            }
        },
            'StorefrontGenerationOutbound::testWelineJsReadsIdentityCookieConstant' => function () {
            $this->setUp_StorefrontGenerationOutbound();
            try {
                $this->assert_StorefrontGenerationOutbound_testWelineJsReadsIdentityCookieConstant();
            } finally {
                $this->tearDown_StorefrontGenerationOutbound();
            }
        },
            'UrlExpandModuleStarPath::testBackendUrlExpandsStarViaHelper' => function () {
            $this->assert_UrlExpandModuleStarPath_testBackendUrlExpandsStarViaHelper();
        },
            'UrlFrontendMountCookieIsolation::testUrlFrontendResolveWebsiteMountPathHonorsEmptyDomAttribute' => function () {
            $this->assert_UrlFrontendMountCookieIsolation_testUrlFrontendResolveWebsiteMountPathHonorsEmptyDomAttribute();
        },
            'UrlFrontendMountCookieIsolation::testI18nResolveWebsiteMountPathHonorsEmptyDomAttribute' => function () {
            $this->assert_UrlFrontendMountCookieIsolation_testI18nResolveWebsiteMountPathHonorsEmptyDomAttribute();
        },
            'UrlMountTrailingSlashConsume::testExactMountTrailingSlashNormalizesToEmptyRemainder' => function () {
            $this->assert_UrlMountTrailingSlashConsume_testExactMountTrailingSlashNormalizesToEmptyRemainder();
        },
            'UrlNormalizeVisitorUri::testNormalizeVisitorUriHelperIsPublicAndDocumentedForSiteProbe' => function () {
            $this->assert_UrlNormalizeVisitorUri_testNormalizeVisitorUriHelperIsPublicAndDocumentedForSiteProbe();
        },
            'UrlNormalizeVisitorUri::testNormalizeVisitorUriPassthroughWithoutMountObserverMutation' => function () {
            $this->assert_UrlNormalizeVisitorUri_testNormalizeVisitorUriPassthroughWithoutMountObserverMutation();
        },
            'UrlNormalizeVisitorUri::testAppInstallsStorefrontScopeFromOriginUriSoMountsSurvive' => function () {
            $this->assert_UrlNormalizeVisitorUri_testAppInstallsStorefrontScopeFromOriginUriSoMountsSurvive();
        },
            'UrlParserDefaultLanguage::test_parser_source_avoids_premature_state_lang_seed' => function () {
            $this->setUp_UrlParserDefaultLanguage();
            try {
                $this->assert_UrlParserDefaultLanguage_test_parser_source_avoids_premature_state_lang_seed();
            } finally {
                $this->tearDown_UrlParserDefaultLanguage();
            }
        },
            'UrlParserDefaultLanguage::test_website_data_language_codes_keep_association_order' => function () {
            $this->setUp_UrlParserDefaultLanguage();
            try {
                $this->assert_UrlParserDefaultLanguage_test_website_data_language_codes_keep_association_order();
            } finally {
                $this->tearDown_UrlParserDefaultLanguage();
            }
        },
            'UrlParserDefaultLanguage::test_url_parser_uses_default_language_field_not_codes_zero' => function () {
            $this->setUp_UrlParserDefaultLanguage();
            try {
                $this->assert_UrlParserDefaultLanguage_test_url_parser_uses_default_language_field_not_codes_zero();
            } finally {
                $this->tearDown_UrlParserDefaultLanguage();
            }
        },
            'UrlPathMountOutbound::testResolveCurrentWebsiteMountPathFromWebsiteUrl' => function () {
            $this->setUp_UrlPathMountOutbound();
            try {
                $this->assert_UrlPathMountOutbound_testResolveCurrentWebsiteMountPathFromWebsiteUrl();
            } finally {
                $this->tearDown_UrlPathMountOutbound();
            }
        },
            'UrlPathMountOutbound::testPeelMountPrefixFromRelativePath' => function () {
            $this->setUp_UrlPathMountOutbound();
            try {
                $this->assert_UrlPathMountOutbound_testPeelMountPrefixFromRelativePath();
            } finally {
                $this->tearDown_UrlPathMountOutbound();
            }
        },
            'UrlPathMountOutbound::testRootSitePeelIsNoOp' => function () {
            $this->setUp_UrlPathMountOutbound();
            try {
                $this->assert_UrlPathMountOutbound_testRootSitePeelIsNoOp();
            } finally {
                $this->tearDown_UrlPathMountOutbound();
            }
        },
            'UrlProcessDecodeCacheKey::testProcessDecodeKeyIgnoresCurrentRequestUri' => function () {
            $this->setUp_UrlProcessDecodeCacheKey();
            try {
                $this->assert_UrlProcessDecodeCacheKey_testProcessDecodeKeyIgnoresCurrentRequestUri();
            } finally {
                $this->tearDown_UrlProcessDecodeCacheKey();
            }
        },
            'UrlProcessDecodeCacheKey::testProcessDecodeKeyIgnoresLangMutationAfterParser' => function () {
            $this->setUp_UrlProcessDecodeCacheKey();
            try {
                $this->assert_UrlProcessDecodeCacheKey_testProcessDecodeKeyIgnoresLangMutationAfterParser();
            } finally {
                $this->tearDown_UrlProcessDecodeCacheKey();
            }
        },
            'UrlProcessDecodeCacheKey::testProcessDecodeKeyUnifiesAbsoluteUrlAndPathOnly' => function () {
            $this->setUp_UrlProcessDecodeCacheKey();
            try {
                $this->assert_UrlProcessDecodeCacheKey_testProcessDecodeKeyUnifiesAbsoluteUrlAndPathOnly();
            } finally {
                $this->tearDown_UrlProcessDecodeCacheKey();
            }
        },
            'UrlProcessDecodeCacheKey::testProcessDecodeKeySourceDocumentsLookupPathIdentity' => function () {
            $this->setUp_UrlProcessDecodeCacheKey();
            try {
                $this->assert_UrlProcessDecodeCacheKey_testProcessDecodeKeySourceDocumentsLookupPathIdentity();
            } finally {
                $this->tearDown_UrlProcessDecodeCacheKey();
            }
        },
            'UrlWorkerListenPortStrip::testStripWorkerListenPortKeepsPathQueryAndFragmentWithoutPregWarning' => function () {
            $this->assert_UrlWorkerListenPortStrip_testStripWorkerListenPortKeepsPathQueryAndFragmentWithoutPregWarning();
        },
        ];
    }

private array $serverBackup_BackendUrlLocalePrefix = [];

private array $envSnapshot_BackendUrlLocalePrefix = [];

private function setUp_BackendUrlLocalePrefix(): void
{
    parent::setUp();
    $this->serverBackup_BackendUrlLocalePrefix = $_SERVER;
    $this->envSnapshot_BackendUrlLocalePrefix = WelineEnv::getInstance()->capture();
    $this->resetParserState_BackendUrlLocalePrefix();
}

private function tearDown_BackendUrlLocalePrefix(): void
{
    $_SERVER = $this->serverBackup_BackendUrlLocalePrefix;
    WelineEnv::getInstance()->restore($this->envSnapshot_BackendUrlLocalePrefix);
    $this->resetParserState_BackendUrlLocalePrefix();
    parent::tearDown();
}

private function assert_BackendUrlLocalePrefix_testGetBackendUrlKeepsNonDefaultLanguageSegment(): void
{
    $backendPrefix = (string) (Env::getAreaRoutePrefix('backend') ?? '');
    self::assertNotSame('', $backendPrefix);
    $parsed = $this->parsePath_BackendUrlLocalePrefix('/' . $backendPrefix . '/en_US/admin');
    WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $parsed['server']);
    WelineEnv::set('website.currency', 'CNY', 'backend url locale contract');
    WelineEnv::set('website.language', 'zh_Hans_CN', 'backend url locale contract');
    self::assertSame('en_US', State::getLang());
    self::assertSame('/en_US', Url::getPrefix());
    /** @var Url $url */
    $url = ObjectManager::getInstance(Url::class);
    $root = $url->getBackendUrl('/');
    $leaf = $url->getBackendUrl('weline_product/backend/catalog/products');
    self::assertStringContainsString('/en_US', $root);
    self::assertStringContainsString('/en_US/', $leaf);
    self::assertStringContainsString('weline_product/backend/catalog/products', $leaf);
    self::assertDoesNotMatchRegularExpression('#/en_US/.+/en_US/#', $leaf);
}

private function assert_BackendUrlLocalePrefix_testGetBackendUrlOmitsDefaultLanguageSegment(): void
{
    $backendPrefix = (string) (Env::getAreaRoutePrefix('backend') ?? '');
    self::assertNotSame('', $backendPrefix);
    $parsed = $this->parsePath_BackendUrlLocalePrefix('/' . $backendPrefix . '/admin');
    WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $parsed['server']);
    WelineEnv::set('website.currency', 'CNY', 'backend url locale contract');
    WelineEnv::set('website.language', 'zh_Hans_CN', 'backend url locale contract');
    WelineEnv::set('user.currency', 'CNY', 'backend url locale contract');
    WelineEnv::set('user.lang', 'zh_Hans_CN', 'backend url locale contract');
    self::assertSame('', Url::getPrefix());
    /** @var Url $url */
    $url = ObjectManager::getInstance(Url::class);
    $leaf = $url->getBackendUrl('admin/index');
    $path = (string) (parse_url($leaf, PHP_URL_PATH) ?: $leaf);
    self::assertStringNotContainsString('/zh_Hans_CN/', $path);
    self::assertStringContainsString('/admin/index', $path);
}

private function assert_BackendUrlLocalePrefix_testBackendDefaultLanguageIsIndependentOfWebsiteLanguage(): void
{
    WelineEnv::set('website.language', 'en_US', 'backend url locale contract');
    WelineEnv::set('website.currency', 'CNY', 'backend url locale contract');
    WelineEnv::set('user.currency', 'CNY', 'backend url locale contract');
    WelineEnv::set('user.lang', 'en_US', 'backend url locale contract');
    WelineEnv::set('url_parsed', true, 'backend url locale contract');
    WelineEnv::set('area', 'backend', 'backend url locale contract');
    WelineEnv::set('backend_default_language', 'zh_Hans_CN', 'backend url locale contract');
    self::assertSame('zh_Hans_CN', State::resolveBackendDefaultLanguage());
    $prefix = new \ReflectionMethod(Url::class, 'getBackendLocalePrefix');
    $prefix->setAccessible(true);
    self::assertSame('/en_US', $prefix->invoke(null));
}

/** @return array<string, mixed> */
private function parsePath_BackendUrlLocalePrefix(string $path): array
{
    $this->resetParserState_BackendUrlLocalePrefix();
    $parsed = Url::parser('http://localhost' . $path);
    self::assertIsArray($parsed);
    return $parsed;
}

private function resetParserState_BackendUrlLocalePrefix(): void
{
    Url::$parserServer = [];
    Url::resetWebsiteParserSites();
    Url::resetParserRequestCaches();
    State::resetRequestPathLocalizationCache();
}

private function assert_BinaryOutputGuard_testGuardSourceUsesFiberCaptureAndNoPersistentStackDrain(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/BinaryOutputGuard.php');
    self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $source);
    self::assertStringContainsString('FiberOutputBuffer::endCapture()', $source);
    self::assertStringContainsString('Runtime::isPersistent()', $source);
    // Persistent branch: beginCapture then return — no while(ob_get_level) inside that arm.
    self::assertMatchesRegularExpression('/if\s*\(\s*Runtime::isPersistent\(\)\s*\)\s*\{\s*' . 'FiberOutputBuffer::beginCapture\(\);\s*' . 'return\s*\[/', $source);
    $persistentArm = '';
    if (\preg_match('/if\s*\(\s*Runtime::isPersistent\(\)\s*\)\s*\{([\s\S]*?)\n\s*\}/', $source, $m)) {
        $persistentArm = $m[1];
    }
    self::assertNotSame('', $persistentArm);
    self::assertStringNotContainsString('ob_get_level', $persistentArm);
    self::assertStringNotContainsString('ini_set', $persistentArm);
}

private function assert_BinaryOutputGuard_testBinQueryAndQueryBinDelegateToBinaryOutputGuard(): void
{
    $binQuery = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/Controller/Api/BinQuery.php');
    $queryBin = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/Controller/Api/QueryBin.php');
    self::assertStringContainsString('BinaryOutputGuard::begin(', $binQuery);
    self::assertStringContainsString('BinaryOutputGuard::end(', $binQuery);
    self::assertStringNotContainsString('while (\ob_get_level() > 0)', $binQuery);
    self::assertStringContainsString('BinaryOutputGuard::begin(', $queryBin);
    self::assertStringContainsString('BinaryOutputGuard::end(', $queryBin);
    self::assertStringNotContainsString('while (\ob_get_level() > 0)', $queryBin);
}

private function assert_BinaryOutputGuard_testPcControllerFetchJsonPersistentSkipsObClean(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Controller/PcController.php');
    $pos = \strpos($source, 'function fetchJson');
    self::assertNotFalse($pos);
    $chunk = \substr($source, (int) $pos, 900);
    self::assertStringContainsString('Runtime::isPersistent()', $chunk);
    self::assertStringContainsString('FiberOutputBuffer::resetCurrent()', $chunk);
    self::assertMatchesRegularExpression('/isPersistent\(\)\s*\)\s*\{[\s\S]*?resetCurrent\(\)/', $chunk);
    self::assertDoesNotMatchRegularExpression('/isPersistent\(\)\s*\)\s*\{[\s\S]{0,250}?\\\\ob_clean\(\)/', $chunk);
}

private function assert_SitemapFpcBakeEngine_testRewriteOriginKeepsPathAndSwapsHost(): void
{
    $out = FpcConnectOnlyHttpClient::rewriteOrigin('https://p05113ef3.test.weline.com/en_US/product/a', 'https://www.changanhanfu.com');
    self::assertSame('https://www.changanhanfu.com/en_US/product/a', $out);
}

private function assert_SitemapFpcBakeEngine_testConnectPeerMustNotEqualBakeOriginHost(): void
{
    $engine = new SitemapFpcBakeEngine();
    self::assertTrue($engine->isSafeConnectPeer('127.0.0.1', 'https://www.changanhanfu.com'));
    self::assertTrue($engine->isSafeConnectPeer('p05113ef3.test.weline.com', 'https://www.changanhanfu.com'));
    self::assertFalse($engine->isSafeConnectPeer('www.changanhanfu.com', 'https://www.changanhanfu.com'));
}

private function assert_SitemapFpcBakeEngine_testDryRunPlansRewrittenUrlsWithoutHttp(): void
{
    $engine = new SitemapFpcBakeEngine();
    $result = $engine->bake(['https://local.test/a', 'https://local.test/b', 'https://local.test/a'], 'https://www.changanhanfu.com', '', 70.0, 2, false, true);
    self::assertTrue($result['ok']);
    self::assertSame(['https://www.changanhanfu.com/a', 'https://www.changanhanfu.com/b'], $result['planned']);
    self::assertSame(0, $result['warmed']);
}

private function assert_SitemapFpcBakeEngine_testEngineSourceHasNoSeoOrWebsitesImports(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Fpc/Bake/SitemapFpcBakeEngine.php';
    $src = (string) \file_get_contents($path);
    self::assertStringNotContainsString('Weline\Seo\\', $src);
    self::assertStringNotContainsString('Weline\Websites\\', $src);
    $clientPath = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Fpc/Bake/FpcConnectOnlyHttpClient.php';
    $clientSrc = (string) \file_get_contents($clientPath);
    self::assertStringNotContainsString('Fallback: direct absolute', $clientSrc);
    self::assertStringContainsString('Never falls back', $clientSrc);
    self::assertStringContainsString('X-Forwarded-Proto:', $clientSrc);
    self::assertStringContainsString('X-Forwarded-Port:', $clientSrc);
}

private function assert_SitemapFpcBakeEngine_testLoadTargetControllerFullPowerPinsMax(): void
{
    $c = new LoadTargetConcurrencyController(0.7, 4, true);
    $c->tick();
    self::assertSame(4, $c->concurrency());
}

private function assert_SitemapFpcBakeEngine_testBusyGateAndLoadTargetAreSeparateClasses(): void
{
    $busy = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Service/StorefrontWarmup/StorefrontWarmupBusyGate.php';
    $load = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Fpc/Bake/LoadTargetConcurrencyController.php';
    self::assertFileExists($busy);
    self::assertFileExists($load);
    $busySrc = (string) \file_get_contents($busy);
    self::assertStringContainsString('abort', $busySrc);
    $loadSrc = (string) \file_get_contents($load);
    self::assertStringContainsString('targetLoad', $loadSrc);
    self::assertStringNotContainsString('StorefrontWarmupBusyGate', $loadSrc);
}

private function tearDown_FpcBypassAndStore(): void
{
    FpcBypassEvaluator::clearCache();
    parent::tearDown();
}

private function assert_FpcBypassAndStore_testEvaluatorBypassesEditorQueryAndLivePreviewPath(): void
{
    FpcBypassEvaluator::clearCache();
    $rules = FpcBypassEvaluator::builtinFallbackRules();
    // Diag/editor query keys require install pierce stamp.
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['query' => ['editor_mode' => '1']], $rules));
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['query' => ['nocache' => '1']], $rules));
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['query' => ['_wb_nc' => '1']], $rules));
    self::assertTrue(FpcBypassEvaluator::shouldBypass(['query' => ['_wb_nc' => '1'], 'pierce_stamp_valid' => true], $rules));
    self::assertTrue(FpcBypassEvaluator::shouldBypass(['query' => ['nocache' => '1'], 'env' => ['fpc_pierce_stamp' => '1']], $rules));
    // Legacy preview cookie alone must NOT bypass — identity is /~preview/{token}/ or query/header.
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['cookie_header' => 'a=1; weline_preview_token_w0=abc; b=2'], $rules));
    self::assertTrue(FpcBypassEvaluator::shouldBypass(['headers' => ['x-wls-fpc-bypass' => '1']], $rules));
    self::assertTrue(FpcBypassEvaluator::shouldBypass(['request_path' => '/~preview/pv_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG/~site/grocery/'], $rules));
    // Deploy /~wh~: pierce required (same gate as diag); bare path must not bypass.
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['request_path' => '/~wh~deadbeef'], $rules));
    self::assertTrue(FpcBypassEvaluator::shouldBypass(['request_path' => '/~wh~deadbeef', 'pierce_stamp_valid' => true], $rules));
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['query' => ['q' => 'hanfu'], 'headers' => ['cache-control' => 'no-cache']], $rules));
    // Admin「链接自带签章」开启：无章请求也必须 BYPASS，否则 HIT 旧 HTML 永远长不出签章链接。
    self::assertTrue(FpcBypassEvaluator::shouldBypass(['env' => ['dev_auto_pierce' => '1']], $rules));
    self::assertTrue(FpcBypassEvaluator::shouldBypass(['env' => ['dev_auto_pierce' => '1']], []));
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['env' => ['dev_auto_pierce' => '0']], $rules));
    $byId = [];
    foreach ($rules as $rule) {
        $byId[(string)($rule['id'] ?? '')] = $rule;
    }
    self::assertArrayHasKey('framework.dev_auto_pierce', $byId);
}

private function assert_FpcBypassAndStore_testBuiltinFallbackPinsDeployWebhookPierceGate(): void
{
    $byId = [];
    foreach (FpcBypassEvaluator::builtinFallbackRules() as $rule) {
        $byId[(string) ($rule['id'] ?? '')] = $rule;
    }
    self::assertArrayHasKey('deploy.webhook_path_prefix', $byId);
    $match = $byId['deploy.webhook_path_prefix']['match'] ?? [];
    self::assertTrue((bool) ($match['require_pierce_stamp'] ?? false));
    self::assertSame(['/~wh~'], $match['uri_path_prefixes'] ?? null);
}

private function assert_FpcBypassAndStore_testCoordinatorHardExcludesDeployWebhookOnFpmOnly(): void
{
    $path = BP . '/app/code/Weline/Framework/Router/FullPageCacheCoordinator.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("str_starts_with(\$firstSegment, '~wh~') && !Runtime::isWls()", $source);
}

private function assert_FpcBypassAndStore_testCollectorPreservesRequirePierceStamp(): void
{
    $collector = new \ReflectionClass(\Weline\Framework\Http\Fpc\FpcBypassCollector::class);
    $method = $collector->getMethod('normalizeRule');
    $method->setAccessible(true);
    $instance = $collector->newInstanceWithoutConstructor();
    $normalized = $method->invoke($instance, ['id' => 'theme.editor_preview_query', 'match' => ['require_pierce_stamp' => true, 'query_keys' => ['_wb_nc', 'nocache']], 'effect' => 'bypass_serve_and_publish']);
    self::assertIsArray($normalized);
    self::assertTrue((bool) ($normalized['match']['require_pierce_stamp'] ?? false));
    self::assertSame(['_wb_nc', 'nocache'], $normalized['match']['query_keys']);
}

private function assert_FpcBypassAndStore_testThemeAndWlsProvidersShareBuiltinFallbackIds(): void
{
    $themeIds = \array_column((new ThemeEditorFpcBypassProvider())->rules(), 'id');
    $wlsIds = \array_column((new WlsTransportFpcBypassProvider())->rules(), 'id');
    $builtinIds = \array_column(FpcBypassEvaluator::builtinFallbackRules(), 'id');
    foreach ($themeIds as $id) {
        self::assertContains($id, $builtinIds);
    }
    foreach ($wlsIds as $id) {
        self::assertContains($id, $builtinIds);
    }
    self::assertContains('theme.live_preview_path_prefix', $themeIds);
}

private function assert_FpcBypassAndStore_testCollectorNormalizesUriPathPrefixes(): void
{
    $collector = new \ReflectionClass(\Weline\Framework\Http\Fpc\FpcBypassCollector::class);
    $method = $collector->getMethod('normalizeRule');
    $method->setAccessible(true);
    $instance = $collector->newInstanceWithoutConstructor();
    $normalized = $method->invoke($instance, ['id' => 'theme.live_preview_path_prefix', 'match' => ['uri_path_prefixes' => ['~preview/', '/~preview/']], 'effect' => 'bypass_serve_and_publish']);
    self::assertIsArray($normalized);
    self::assertSame(['/~preview/'], $normalized['match']['uri_path_prefixes']);
}

private function assert_FpcBypassAndStore_testFpcCapabilitySourceDoesNotTouchCacheManagerPools(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . \DIRECTORY_SEPARATOR . 'Extends' . \DIRECTORY_SEPARATOR . 'module' . \DIRECTORY_SEPARATOR . 'Weline_Framework' . \DIRECTORY_SEPARATOR . 'Changed' . \DIRECTORY_SEPARATOR . 'Capability' . \DIRECTORY_SEPARATOR . 'FpcCapability.php';
    self::assertFileExists($path, 'expected at ' . $path . ' from __DIR__=' . __DIR__);
    $src = (string) \file_get_contents($path);
    self::assertStringNotContainsString('use Weline\Framework\Cache\CacheManager', $src);
    self::assertStringNotContainsString("->pool('fpc')", $src);
    self::assertStringNotContainsString('router-fpc-payloads', $src);
    self::assertStringContainsString('FpcStoreAdapterRegistry', $src);
    self::assertSame('fpc', (new FpcCapability())->code());
}

private function assert_FpcBypassAndStore_testStoreAdapterInterfacePrefixAndWlsCode(): void
{
    self::assertStringContainsString('fpc/store', FpcStoreAdapterInterface::EXTENDS_RELATIVE_PREFIX);
    self::assertTrue(\class_exists(FpcStoreAdapterRegistry::class));
}

private function assert_FpcBypassAndStore_testEvaluatorEmptyRulesDoesNotBypassGuestTraffic(): void
{
    self::assertFalse(FpcBypassEvaluator::shouldBypass(['query' => ['q' => 'shoes'], 'headers' => ['cache-control' => 'max-age=0']], []));
}

private function assert_FpcBypassFactsBuilder_testBuildIncludesEditorAndCdnEnvKeys(): void
{
    $facts = FpcBypassFactsBuilder::build('/?x=1', ['x' => '1'], '', [], false);
    self::assertArrayHasKey('env', $facts);
    self::assertArrayHasKey('editor_mode', $facts['env']);
    self::assertArrayHasKey('cdn_fpc_dev_mode', $facts['env']);
    self::assertSame('', $facts['env']['cdn_fpc_dev_mode']);
}

private function assert_FpcBypassFactsBuilder_testCdnDevModeFlagTriggersBypassWithProviderRule(): void
{
    $facts = FpcBypassFactsBuilder::build('/', [], '', [], true);
    self::assertSame('1', $facts['env']['cdn_fpc_dev_mode']);
    self::assertTrue(FpcBypassEvaluator::shouldBypass($facts, [['id' => 'cdn.fpc_dev_mode', 'match' => ['env_flags' => ['cdn_fpc_dev_mode']], 'effect' => 'bypass_serve_and_publish']]));
    $factsOff = FpcBypassFactsBuilder::build('/', [], '', [], false);
    self::assertFalse(FpcBypassEvaluator::shouldBypass($factsOff, [['id' => 'cdn.fpc_dev_mode', 'match' => ['env_flags' => ['cdn_fpc_dev_mode']]]]));
}

private function assert_FpcGeneratedNotUnderAppCode_testHtmlDiskRootIsRepoGeneratedNotAppCodeGenerated(): void
{
    ProjectRoot::defineBp(__DIR__);
    self::assertFalse(ProjectRoot::isAccidentalAppCodeGenerated((string) BP), 'BP itself must not be app/code');
    $root = FpcHtmlDiskStore::root();
    self::assertStringContainsString(\DIRECTORY_SEPARATOR . 'generated' . \DIRECTORY_SEPARATOR . 'html', $root);
    self::assertFalse(ProjectRoot::isAccidentalAppCodeGenerated($root), 'FpcHtmlDiskStore::root must not be under app/code/generated');
    self::assertSame(\rtrim((string) Env::GENERATED_DIR, '\/') . \DIRECTORY_SEPARATOR . 'html', $root);
}

private string $fixtureHost_FpcHtmlDiskStore = '';

private function setUp_FpcHtmlDiskStore(): void
{
    $this->fixtureHost_FpcHtmlDiskStore = 'fpc-html-disk-test-' . \bin2hex(\random_bytes(4)) . '.example.test';
}

private function tearDown_FpcHtmlDiskStore(): void
{
    $url = 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/';
    try {
        FpcHtmlDiskStore::abandonStagingGeneration();
    } catch (\Throwable) {
    }
    FpcHtmlDiskStore::purgeUrls([$url, 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/demo-product', 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/path']);
}

private function assert_FpcHtmlDiskStore_testPublishReadAndPurgeUrls(): void
{
    $url = 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/demo-product';
    $html = "<!doctype html><html><head><title>t</title></head><body>ok</body></html>\n";
    self::assertTrue(FpcHtmlDiskStore::publish($url, $html, ''));
    $path = FpcHtmlDiskStore::htmlPath($url);
    self::assertIsString($path);
    self::assertFileExists($path);
    self::assertSame($html, (string) \file_get_contents($path));
    $live = FpcHtmlDiskStore::logicalLiveBodyPath($url, false);
    self::assertIsString($live);
    self::assertStringContainsString(\DIRECTORY_SEPARATOR . 'html' . \DIRECTORY_SEPARATOR . 'live' . \DIRECTORY_SEPARATOR, $live);
    self::assertStringNotContainsString(\DIRECTORY_SEPARATOR . 'generations' . \DIRECTORY_SEPARATOR, $live);
    $hit = FpcHtmlDiskStore::read($url, '');
    self::assertIsArray($hit);
    self::assertSame($html, $hit['body']);
    self::assertNull($hit['encoding']);
    FpcHtmlDiskStore::purgeUrls([$url]);
    self::assertFileDoesNotExist($path);
    self::assertNull(FpcHtmlDiskStore::read($url, ''));
}

private function assert_FpcHtmlDiskStore_testBrotliPublishIsBrOnlyAndReadableWithoutAcceptBr(): void
{
    if (!\function_exists('brotli_compress')) {
        self::markTestSkipped('brotli extension is not loaded');
    }
    $url = 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/demo-product';
    $html = "<!doctype html><html><body>br-only</body></html>\n";
    $br = \brotli_compress($html, \Weline\Framework\Http\ContentEncodingNegotiator::BROTLI_QUALITY);
    self::assertIsString($br);
    self::assertNotSame('', $br);
    // Seed a legacy plaintext leaf that br publish must unlink.
    self::assertTrue(FpcHtmlDiskStore::publish($url, $html, ''));
    $htmlPath = FpcHtmlDiskStore::htmlPath($url);
    self::assertIsString($htmlPath);
    self::assertFileExists($htmlPath);
    self::assertTrue(FpcHtmlDiskStore::publish($url, $html, $br));
    $brPath = $htmlPath . '.br';
    self::assertFileExists($brPath);
    self::assertFileDoesNotExist($htmlPath);
    $hitIdentity = FpcHtmlDiskStore::read($url, '');
    self::assertIsArray($hitIdentity);
    self::assertSame('br', $hitIdentity['encoding']);
    self::assertSame($br, $hitIdentity['body']);
    self::assertSame($brPath, $hitIdentity['path']);
    $hitBr = FpcHtmlDiskStore::read($url, 'gzip, deflate, br');
    self::assertIsArray($hitBr);
    self::assertSame('br', $hitBr['encoding']);
    self::assertSame($br, $hitBr['body']);
    FpcHtmlDiskStore::purgeUrls([$url]);
    self::assertFileDoesNotExist($brPath);
    self::assertNull(FpcHtmlDiskStore::read($url, ''));
}

private function assert_FpcHtmlDiskStore_testAnyQueryIsPathKeyedOnly(): void
{
    $canonical = 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/path';
    $withQuery = $canonical . '?size=s&style_type=hong-se-jiu&_wb_nc=1';
    $html = "<html><body>path</body></html>\n";
    self::assertTrue(FpcHtmlDiskStore::publish($withQuery, $html, ''));
    $path = FpcHtmlDiskStore::htmlPath($withQuery);
    self::assertIsString($path);
    self::assertSame(FpcHtmlDiskStore::htmlPath($canonical), $path);
    self::assertStringNotContainsString('~q', $path);
    self::assertFileExists($path);
    FpcHtmlDiskStore::purgeUrls([$canonical]);
    self::assertFileDoesNotExist($path);
}

private function assert_FpcHtmlDiskStore_testSoftPurgeAllKeepsPreviousAndRollbackRestores(): void
{
    $url = 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/demo-product';
    $html = "<html><body>gen-a</body></html>\n";
    self::assertTrue(FpcHtmlDiskStore::publish($url, $html, ''));
    $beforeGen = FpcHtmlDiskStore::currentGenerationId();
    self::assertNotSame('', $beforeGen);
    FpcHtmlDiskStore::purgeAll();
    self::assertNull(FpcHtmlDiskStore::read($url, ''));
    self::assertSame($beforeGen, FpcHtmlDiskStore::previousGenerationId());
    $prevLink = FpcHtmlDiskStore::root() . \DIRECTORY_SEPARATOR . FpcHtmlDiskStore::PREVIOUS_NAME;
    self::assertTrue(\is_link($prevLink));
    self::assertTrue(FpcHtmlDiskStore::rollbackToPrevious());
    $hit = FpcHtmlDiskStore::read($url, '');
    self::assertIsArray($hit);
    self::assertSame($html, $hit['body']);
}

private function assert_FpcHtmlDiskStore_testStagingSeedWarmWritesDoNotFlipLiveUntilPromote(): void
{
    $url = 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/demo-product';
    $old = "<html><body>live-old</body></html>\n";
    $neu = "<html><body>staging-new</body></html>\n";
    self::assertTrue(FpcHtmlDiskStore::publish($url, $old, ''));
    $liveBefore = FpcHtmlDiskStore::currentGenerationId();
    $stagingId = FpcHtmlDiskStore::beginStagingGeneration(true);
    self::assertNotSame('', $stagingId);
    self::assertSame($liveBefore, FpcHtmlDiskStore::currentGenerationId());
    self::assertTrue(FpcHtmlDiskStore::publish($url, $neu, ''));
    // Readers still follow live until promote.
    $hit = FpcHtmlDiskStore::read($url, '');
    self::assertIsArray($hit);
    self::assertSame($old, $hit['body']);
    self::assertTrue(FpcHtmlDiskStore::promoteStagingGeneration());
    $hit2 = FpcHtmlDiskStore::read($url, '');
    self::assertIsArray($hit2);
    self::assertSame($neu, $hit2['body']);
    self::assertSame($liveBefore, FpcHtmlDiskStore::previousGenerationId());
}

private function assert_FpcHtmlDiskStore_testAbandonStagingKeepsLive(): void
{
    $url = 'https://' . $this->fixtureHost_FpcHtmlDiskStore . '/en/demo-product';
    $old = "<html><body>keep-live</body></html>\n";
    self::assertTrue(FpcHtmlDiskStore::publish($url, $old, ''));
    FpcHtmlDiskStore::beginStagingGeneration(true);
    self::assertTrue(FpcHtmlDiskStore::publish($url, "<html><body>discard</body></html>\n", ''));
    FpcHtmlDiskStore::abandonStagingGeneration();
    $hit = FpcHtmlDiskStore::read($url, '');
    self::assertIsArray($hit);
    self::assertSame($old, $hit['body']);
}

private function assert_FpcHtmlDiskStore_testCoordinatorExternalizeNoLongerRemembersHashPayloadIndex(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . \DIRECTORY_SEPARATOR . 'Router' . \DIRECTORY_SEPARATOR . 'FullPageCacheCoordinator.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringNotContainsString('FpcPayloadPathIndex::remember', $src);
    self::assertStringContainsString('FpcHtmlDiskStore::publish', $src);
    self::assertStringContainsString('logicalLiveBodyPath', $src);
    self::assertStringContainsString('stagingGenerationId', $src);
}

private string $fixtureHost_FpcHtmlDiskStorePurgeSlugs = '';

private function setUp_FpcHtmlDiskStorePurgeSlugs(): void
{
    $this->fixtureHost_FpcHtmlDiskStorePurgeSlugs = 'fpc-slug-test-' . \bin2hex(\random_bytes(4)) . '.example.test';
}

private function tearDown_FpcHtmlDiskStorePurgeSlugs(): void
{
    try {
        FpcHtmlDiskStore::abandonStagingGeneration();
    } catch (\Throwable) {
    }
    FpcHtmlDiskStore::purgeSlugs([$this->fixtureHost_FpcHtmlDiskStorePurgeSlugs], ['policy/privacy', 'terms']);
}

private function assert_FpcHtmlDiskStorePurgeSlugs_testPurgeSlugsRemovesBareAndLocaleLeaves(): void
{
    $html = "<!doctype html><html><body>policy</body></html>\n";
    $bare = 'https://' . $this->fixtureHost_FpcHtmlDiskStorePurgeSlugs . '/policy/privacy';
    $locale = 'https://' . $this->fixtureHost_FpcHtmlDiskStorePurgeSlugs . '/en_US/policy/privacy';
    $other = 'https://' . $this->fixtureHost_FpcHtmlDiskStorePurgeSlugs . '/terms';
    self::assertTrue(FpcHtmlDiskStore::publish($bare, $html, ''));
    self::assertTrue(FpcHtmlDiskStore::publish($locale, $html, ''));
    self::assertTrue(FpcHtmlDiskStore::publish($other, $html, ''));
    $barePath = FpcHtmlDiskStore::htmlPath($bare);
    $localePath = FpcHtmlDiskStore::htmlPath($locale);
    $otherPath = FpcHtmlDiskStore::htmlPath($other);
    self::assertIsString($barePath);
    self::assertIsString($localePath);
    self::assertIsString($otherPath);
    self::assertFileExists($barePath);
    self::assertFileExists($localePath);
    self::assertFileExists($otherPath);
    FpcHtmlDiskStore::purgeSlugs([$this->fixtureHost_FpcHtmlDiskStorePurgeSlugs], ['policy/privacy']);
    self::assertFileDoesNotExist($barePath);
    self::assertFileDoesNotExist($localePath);
    self::assertFileExists($otherPath);
}

private function assert_FpcHtmlDiskStorePurgeSlugs_testAdapterInterfaceDeclaresPurgeSlugs(): void
{
    $src = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Fpc/FpcStoreAdapterInterface.php');
    self::assertIsString($src);
    self::assertStringContainsString('function purgeSlugs(array $hosts, array $slugs): void;', $src);
    self::assertTrue(\interface_exists(FpcStoreAdapterInterface::class));
}

private function assert_FpcHtmlDiskStorePurgeSlugs_testPipelineAndExpanderKeepForceReloadFalse(): void
{
    $expander = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/Changed/UrlMatrixExpander.php');
    $pipeline = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/Changed/ChangedPipeline.php');
    self::assertIsString($expander);
    self::assertIsString($pipeline);
    self::assertStringContainsString("'force_reload' => false", $expander);
    self::assertStringNotContainsString("'force_reload' => true", $expander);
    self::assertStringContainsString("'slugs'", $pipeline);
}

private string $secretPath_FpcMode = '';

private function setUp_FpcMode(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架/app/code' . \DIRECTORY_SEPARATOR);
    }
    FpcPierceStamp::clearMemo();
    $this->secretPath_FpcMode = FpcPierceStamp::secretFilePath();
    if (\is_file($this->secretPath_FpcMode)) {
        @\rename($this->secretPath_FpcMode, $this->secretPath_FpcMode . '.bak-fpc-mode-test');
    }
}

private function tearDown_FpcMode(): void
{
    FpcPierceStamp::clearMemo();
    if (\is_file($this->secretPath_FpcMode)) {
        @\unlink($this->secretPath_FpcMode);
    }
    $bak = $this->secretPath_FpcMode . '.bak-fpc-mode-test';
    if (\is_file($bak)) {
        @\rename($bak, $this->secretPath_FpcMode);
    }
}

private function assert_FpcMode_testAppendPierceWhenEnabledAndSkipWhenPresent(): void
{
    $secret = FpcPierceStamp::ensureSecret();
    // Force-on path via reflection of env is brittle; exercise append helper with
    // a temporary override by calling the public method after ensuring secret.
    // When auto-pierce is off (prod default without explicit env), append is no-op.
    $base = 'https://example.test/product/1';
    $with = FpcMode::appendPierceQueryIfEnabled($base);
    if (FpcMode::isDevAutoPierceEnabled()) {
        self::assertStringContainsString(FpcPierceStamp::QUERY_NAME . '=', $with);
        self::assertStringContainsString($secret, $with);
        $again = FpcMode::appendPierceQueryIfEnabled($with);
        self::assertSame($with, $again);
    } else {
        self::assertSame($base, $with);
    }
}

private function assert_FpcMode_testAppendSkipsMailto(): void
{
    $url = 'mailto:ops@example.test';
    self::assertSame($url, FpcMode::appendPierceQueryIfEnabled($url));
}

private function assert_FpcMode_testStatusExposesPierceFields(): void
{
    FpcPierceStamp::ensureSecret();
    $status = FpcMode::status();
    self::assertArrayHasKey('fpc_enabled', $status);
    self::assertArrayHasKey('auto_pierce_enabled', $status);
    self::assertArrayHasKey('auto_pierce_defaults_off', $status);
    self::assertTrue($status['auto_pierce_defaults_off']);
    self::assertArrayHasKey('pierce', $status);
    self::assertSame(FpcPierceStamp::QUERY_NAME, $status['pierce']['query_name']);
    self::assertNotSame('', $status['pierce']['query_suffix']);
}

private function assert_FpcMode_testAbsentEnvDefaultsAutoPierceOff(): void
{
    // Source contract: absent key must not default-on from DEV.
    $src = \file_get_contents((new \ReflectionClass(FpcMode::class))->getFileName() ?: '');
    self::assertIsString($src);
    self::assertStringContainsString('When env key is absent: always false', $src);
    self::assertStringNotContainsString('DEV → true', $src);
    $status = FpcMode::status();
    self::assertTrue(!empty($status['auto_pierce_defaults_off']));
    $factsSrc = (string)\file_get_contents((new \ReflectionClass(FpcBypassFactsBuilder::class))->getFileName() ?: '');
    self::assertStringContainsString("'dev_auto_pierce'", $factsSrc);
    self::assertStringContainsString('isDevAutoPierceEnabled', $factsSrc);
    $evalSrc = (string)\file_get_contents((new \ReflectionClass(FpcBypassEvaluator::class))->getFileName() ?: '');
    self::assertStringContainsString("envFlagOn(\$env, 'dev_auto_pierce')", $evalSrc);
    $clientSrc = (string)\file_get_contents(
        \dirname((string)(new \ReflectionClass(FpcMode::class))->getFileName()) . '/FpcDevAutoPierceClientScript.php'
    );
    self::assertStringContainsString('injectIntoHtml', $clientSrc);
    self::assertStringContainsString('data-weline-fpc-dev-auto-pierce', $clientSrc);
    $jsPath = \dirname((string)(new \ReflectionClass(FpcMode::class))->getFileName(), 3)
        . '/View/statics/js/fpc-dev-auto-pierce.js';
    self::assertFileExists($jsPath);
    $js = (string)\file_get_contents($jsPath);
    self::assertStringContainsString('searchParams.set', $js);
    self::assertStringContainsString('a[href]', $js);
    self::assertStringContainsString('MutationObserver', $js);
    self::assertStringContainsString("attributeFilter: ['href', 'action', 'data-href', 'data-url']", $js);
    self::assertStringContainsString('installSetAttributeHook', $js);
    self::assertStringContainsString('__welineFpcPierceHooked', $js);
    self::assertStringContainsString('data-weline-fpc-dev-auto-pierce', $js);
    self::assertStringContainsString('document.currentScript', $js);
    self::assertDoesNotMatchRegularExpression(
        '/<script\b[^>]*\bdefer\b/i',
        $clientSrc,
        'loader script tag must not use defer (end-of-body + currentScript)'
    );
    $responseSrc = (string)\file_get_contents((new \ReflectionClass(\Weline\Framework\Http\Response::class))->getFileName() ?: '');
    self::assertStringContainsString('injectFpcDevAutoPierceClientIfNeeded', $responseSrc);
    $finalizer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme/Service/StorefrontLayoutHtmlFinalizer.php';
    self::assertStringContainsString('FpcDevAutoPierceClientScript::injectIntoHtml', (string)\file_get_contents($finalizer));
}

private string $secretPath_FpcPierceStamp = '';

private function setUp_FpcPierceStamp(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架/app/code' . \DIRECTORY_SEPARATOR);
    }
    FpcPierceStamp::clearMemo();
    FpcBypassEvaluator::clearCache();
    $this->secretPath_FpcPierceStamp = FpcPierceStamp::secretFilePath();
    if (\is_file($this->secretPath_FpcPierceStamp)) {
        @\rename($this->secretPath_FpcPierceStamp, $this->secretPath_FpcPierceStamp . '.bak-pierce-test');
    }
}

private function tearDown_FpcPierceStamp(): void
{
    FpcPierceStamp::clearMemo();
    FpcBypassEvaluator::clearCache();
    if (\is_file($this->secretPath_FpcPierceStamp)) {
        @\unlink($this->secretPath_FpcPierceStamp);
    }
    $bak = $this->secretPath_FpcPierceStamp . '.bak-pierce-test';
    if (\is_file($bak)) {
        @\rename($bak, $this->secretPath_FpcPierceStamp);
    }
}

private function assert_FpcPierceStamp_testEnsureAndValidateCookieOrQuery(): void
{
    $secret = FpcPierceStamp::ensureSecret();
    self::assertNotSame('', $secret);
    self::assertTrue(FpcPierceStamp::isValidRequest(FpcPierceStamp::COOKIE_NAME . '=' . $secret, []));
    self::assertTrue(FpcPierceStamp::isValidRequest(null, [FpcPierceStamp::QUERY_NAME => $secret]));
    self::assertFalse(FpcPierceStamp::isValidRequest(null, ['_wb_nc' => '1']));
    self::assertFalse(FpcPierceStamp::isValidRequest(FpcPierceStamp::COOKIE_NAME . '=deadbeef', []));
}

private function assert_FpcPierceStamp_testDiagQueryBypassRequiresPierceStamp(): void
{
    $secret = FpcPierceStamp::ensureSecret();
    $without = FpcBypassFactsBuilder::build('/products?nocache=1', ['nocache' => '1'], '');
    self::assertFalse((bool) ($without['pierce_stamp_valid'] ?? false));
    self::assertFalse(FpcBypassEvaluator::shouldBypass($without, FpcBypassEvaluator::builtinFallbackRules()));
    $with = FpcBypassFactsBuilder::build('/products?nocache=1&' . FpcPierceStamp::QUERY_NAME . '=' . $secret, ['nocache' => '1', FpcPierceStamp::QUERY_NAME => $secret], '');
    self::assertTrue((bool) ($with['pierce_stamp_valid'] ?? false));
    self::assertTrue(FpcBypassEvaluator::shouldBypass($with, FpcBypassEvaluator::builtinFallbackRules()));
}

private function assert_FpcPierceStamp_testPreviewPathStillBypassesWithoutPierceStamp(): void
{
    $facts = FpcBypassFactsBuilder::build('/~preview/abc123/en_US/', [], '');
    self::assertFalse((bool) ($facts['pierce_stamp_valid'] ?? false));
    self::assertTrue(FpcBypassEvaluator::shouldBypass($facts, FpcBypassEvaluator::builtinFallbackRules()));
}

private string $fixtureHost_FpcStaticDiskStore = '';

private string $sourceFile_FpcStaticDiskStore = '';

private function setUp_FpcStaticDiskStore(): void
{
    ProjectRoot::defineBp(__DIR__);
    $this->fixtureHost_FpcStaticDiskStore = 'fpc-static-disk-' . \bin2hex(\random_bytes(4)) . '.example.test';
    $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'tmp' . \DIRECTORY_SEPARATOR . 'fpc-static-src';
    if (!\is_dir($dir)) {
        @\mkdir($dir, 0775, true);
    }
    $this->sourceFile_FpcStaticDiskStore = $dir . \DIRECTORY_SEPARATOR . 'geo-fixture.js';
    // ≥ MIN_BYTES so StaticAssetBrotliSidecar will write `.br`.
    \file_put_contents($this->sourceFile_FpcStaticDiskStore, \str_repeat("console.log('fpc-static');\n", 80));
}

private function tearDown_FpcStaticDiskStore(): void
{
    FpcStaticDiskStore::purgePaths($this->fixtureHost_FpcStaticDiskStore, ['/Weline/Geo/view/statics/frontend/js/geo-fixture.js', '/Weline/Geo/view/statics/frontend/js/geo-fixture-live-mirror.js', '/Weline/Geo/view/statics/frontend/js/geo-fixture-br-heal.js']);
    if ($this->sourceFile_FpcStaticDiskStore !== '' && \is_file($this->sourceFile_FpcStaticDiskStore)) {
        @\unlink($this->sourceFile_FpcStaticDiskStore);
    }
}

private function assert_FpcStaticDiskStore_testPublishFromAppCodeMirrorAndSkipPub(): void
{
    $storeSrc = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Http/Fpc/FpcStaticDiskStore.php');
    self::assertStringContainsString('StaticAssetBrotliSidecar::publish($target)', $storeSrc);
    self::assertGreaterThanOrEqual(3, \substr_count($storeSrc, 'StaticAssetBrotliSidecar::publish($target)'), 'publishFromFile early-out + copy path + republish hash-match must all refresh .br');
    $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture.js';
    self::assertTrue(FpcStaticDiskStore::shouldMirrorResolvedFile($this->sourceFile_FpcStaticDiskStore));
    self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost_FpcStaticDiskStore, $uri, $this->sourceFile_FpcStaticDiskStore));
    $target = FpcStaticDiskStore::absolutePath($this->fixtureHost_FpcStaticDiskStore, $uri);
    self::assertIsString($target);
    self::assertFileExists($target);
    self::assertStringContainsString(\DIRECTORY_SEPARATOR . 'static' . \DIRECTORY_SEPARATOR . 'live' . \DIRECTORY_SEPARATOR, $target);
    self::assertStringContainsString("console.log('fpc-static');", (string) \file_get_contents($target));
    $pubProbe = BP . 'pub' . \DIRECTORY_SEPARATOR . 'static' . \DIRECTORY_SEPARATOR . 'probe-skip.js';
    $pubDir = \dirname($pubProbe);
    if (!\is_dir($pubDir)) {
        @\mkdir($pubDir, 0775, true);
    }
    \file_put_contents($pubProbe, 'skip');
    try {
        self::assertFalse(FpcStaticDiskStore::shouldMirrorResolvedFile($pubProbe));
    } finally {
        @\unlink($pubProbe);
    }
    FpcStaticDiskStore::purgePaths($this->fixtureHost_FpcStaticDiskStore, [$uri]);
    self::assertFileDoesNotExist($target);
}

private function assert_FpcStaticDiskStore_testPurgeAllLiveRemovesMirroredLeaves(): void
{
    $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture.js';
    self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost_FpcStaticDiskStore, $uri, $this->sourceFile_FpcStaticDiskStore));
    $target = FpcStaticDiskStore::absolutePath($this->fixtureHost_FpcStaticDiskStore, $uri);
    self::assertIsString($target);
    self::assertFileExists($target);
    $removed = FpcStaticDiskStore::purgeAllLive();
    self::assertGreaterThan(0, $removed);
    self::assertFileDoesNotExist($target);
}

private function assert_FpcStaticDiskStore_testUriFromAppCodeAbsoluteAndRepublishOnAllHosts(): void
{
    $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture.js';
    // Seed a live host leaf with stale bytes (simulates Nginx try_files lag).
    self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost_FpcStaticDiskStore, $uri, $this->sourceFile_FpcStaticDiskStore));
    $target = FpcStaticDiskStore::absolutePath($this->fixtureHost_FpcStaticDiskStore, $uri);
    self::assertIsString($target);
    \file_put_contents($target, "console.log('STALE');\n");
    $moduleSrcDir = BP . 'app' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR . 'Weline' . \DIRECTORY_SEPARATOR . 'Geo' . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'statics' . \DIRECTORY_SEPARATOR . 'frontend' . \DIRECTORY_SEPARATOR . 'js';
    if (!\is_dir($moduleSrcDir)) {
        @\mkdir($moduleSrcDir, 0775, true);
    }
    $moduleSrc = $moduleSrcDir . \DIRECTORY_SEPARATOR . 'geo-fixture-live-mirror.js';
    $fresh = "console.log('FRESH-LIVE-MIRROR');\n";
    \file_put_contents($moduleSrc, $fresh);
    $mappedUri = '/Weline/Geo/view/statics/frontend/js/geo-fixture-live-mirror.js';
    try {
        self::assertSame($mappedUri, FpcStaticDiskStore::uriFromAppCodeAbsolute($moduleSrc));
        self::assertContains($this->fixtureHost_FpcStaticDiskStore, FpcStaticDiskStore::listLiveHosts());
        // Stale leaf for the mapped URI on the same host.
        self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost_FpcStaticDiskStore, $mappedUri, $this->sourceFile_FpcStaticDiskStore));
        $mappedTarget = FpcStaticDiskStore::absolutePath($this->fixtureHost_FpcStaticDiskStore, $mappedUri);
        self::assertIsString($mappedTarget);
        \file_put_contents($mappedTarget, "console.log('STALE');\n");
        $n = FpcStaticDiskStore::republishFromFileOnAllHosts($mappedUri, $moduleSrc);
        self::assertGreaterThan(0, $n);
        self::assertSame($fresh, (string) \file_get_contents($mappedTarget));
        $purged = FpcStaticDiskStore::purgeUriOnAllHosts([$mappedUri]);
        self::assertGreaterThan(0, $purged);
        self::assertFileDoesNotExist($mappedTarget);
    } finally {
        FpcStaticDiskStore::purgePaths($this->fixtureHost_FpcStaticDiskStore, [$mappedUri, $uri]);
        if (\is_file($moduleSrc)) {
            @\unlink($moduleSrc);
        }
    }
}

private function assert_FpcStaticDiskStore_testPublishFromFileEarlyOutStillRefreshesStaleBrotliSidecar(): void
{
    if (!ContentEncodingNegotiator::brotliAvailable()) {
        self::markTestSkipped('ext-brotli required');
    }
    $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture-br-heal.js';
    self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost_FpcStaticDiskStore, $uri, $this->sourceFile_FpcStaticDiskStore));
    $target = FpcStaticDiskStore::absolutePath($this->fixtureHost_FpcStaticDiskStore, $uri);
    self::assertIsString($target);
    $br = $target . '.br';
    self::assertFileExists($br);
    // Poison sidecar while leaving plaintext identical to source (early-out path).
    \file_put_contents($br, 'NOT-BROTLI-STALE');
    @\touch($br, \time() - 3600);
    self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost_FpcStaticDiskStore, $uri, $this->sourceFile_FpcStaticDiskStore));
    $decoded = ContentEncodingNegotiator::decode((string) \file_get_contents($br), ContentEncodingNegotiator::ENCODING_BROTLI);
    self::assertSame((string) \file_get_contents($target), $decoded);
    FpcStaticDiskStore::purgePaths($this->fixtureHost_FpcStaticDiskStore, [$uri]);
}

private function assert_FpcStaticDiskStore_testRepublishHashMatchStillRefreshesStaleBrotliSidecar(): void
{
    if (!ContentEncodingNegotiator::brotliAvailable()) {
        self::markTestSkipped('ext-brotli required');
    }
    $moduleSrcDir = BP . 'app' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR . 'Weline' . \DIRECTORY_SEPARATOR . 'Geo' . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'statics' . \DIRECTORY_SEPARATOR . 'frontend' . \DIRECTORY_SEPARATOR . 'js';
    if (!\is_dir($moduleSrcDir)) {
        @\mkdir($moduleSrcDir, 0775, true);
    }
    $moduleSrc = $moduleSrcDir . \DIRECTORY_SEPARATOR . 'geo-fixture-br-heal.js';
    $body = \str_repeat("console.log('br-heal');\n", 80);
    \file_put_contents($moduleSrc, $body);
    $uri = '/Weline/Geo/view/statics/frontend/js/geo-fixture-br-heal.js';
    try {
        self::assertTrue(FpcStaticDiskStore::publishFromFile($this->fixtureHost_FpcStaticDiskStore, $uri, $moduleSrc));
        $target = FpcStaticDiskStore::absolutePath($this->fixtureHost_FpcStaticDiskStore, $uri);
        self::assertIsString($target);
        $br = $target . '.br';
        self::assertFileExists($br);
        \file_put_contents($br, 'NOT-BROTLI-STALE');
        $n = FpcStaticDiskStore::republishFromFileOnAllHosts($uri, $moduleSrc);
        self::assertGreaterThan(0, $n);
        $decoded = ContentEncodingNegotiator::decode((string) \file_get_contents($br), ContentEncodingNegotiator::ENCODING_BROTLI);
        self::assertSame($body, $decoded);
    } finally {
        FpcStaticDiskStore::purgePaths($this->fixtureHost_FpcStaticDiskStore, [$uri]);
        if (\is_file($moduleSrc)) {
            @\unlink($moduleSrc);
        }
    }
}

private function assert_FpcVarNotUnderAppCode_testPayloadPathIndexUsesRepoVarNotAppCodeVar(): void
{
    ProjectRoot::defineBp(__DIR__);
    self::assertFalse(ProjectRoot::isAccidentalAppCodeVar((string) BP), 'BP itself must not be app/code');
    $url = 'https://contract-fpc-var.test/probe-' . \uniqid('', true);
    $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads' . \DIRECTORY_SEPARATOR . 'cv';
    if (!\is_dir($payloadDir)) {
        \mkdir($payloadDir, 0775, true);
    }
    self::assertFalse(ProjectRoot::isAccidentalAppCodeVar($payloadDir), 'router-fpc-payloads must not be under app/code/var');
    $body = $payloadDir . \DIRECTORY_SEPARATOR . 'contract-body.html';
    \file_put_contents($body, '<html>ok</html>');
    FpcPayloadPathIndex::remember($url, 'k-contract-' . \uniqid('', true), $body);
    $refs = FpcPayloadPathIndex::lookup($url);
    self::assertNotEmpty($refs);
    $bodyFile = (string) ($refs[\count($refs) - 1]['body_file'] ?? '');
    self::assertFalse(ProjectRoot::isAccidentalAppCodeVar($bodyFile), 'indexed body_file must not be under app/code/var');
    FpcPayloadPathIndex::purgePaths([$url]);
    @\unlink($body);
}

private function setUp_FpcOfflineBakeGates(): void
{
    ProjectRoot::defineBp(__DIR__);
}

private function assert_FpcOfflineBakeGates_testOriginExactMatchAndUrlBelonging(): void
{
    self::assertSame('https://changanhanfu.com', FpcOfflineBakeOrigin::normalize('https://ChanganHanfu.com/'));
    self::assertTrue(FpcOfflineBakeOrigin::equals('https://a.test', 'https://A.TEST/'));
    self::assertFalse(FpcOfflineBakeOrigin::equals('https://a.test', 'https://b.test'));
    self::assertTrue(FpcOfflineBakeOrigin::urlBelongsTo('https://a.test/en_US/', 'https://a.test'));
    self::assertFalse(FpcOfflineBakeOrigin::urlBelongsTo('https://b.test/', 'https://a.test'));
    self::assertFalse(FpcOfflineBakeOrigin::urlBelongsTo('/', 'https://a.test'));
}

private function assert_FpcOfflineBakeGates_testSchemaRejectsPathOnlyAndCrossWebsite(): void
{
    $manifest = ['schema' => FpcOfflineBakeSchema::SCHEMA, 'website_code' => 'hanfu', 'primary_origin' => 'https://changanhanfu.com'];
    self::assertSame([], FpcOfflineBakeSchema::validateManifest($manifest));
    $good = ['full_uri' => 'https://changanhanfu.com/', 'index_key' => 'https://changanhanfu.com/', 'website_code' => 'hanfu', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'body_relpath' => 'payloads/ab/abcd.html', 'encoding' => 'identity'];
    self::assertSame([], FpcOfflineBakeSchema::validateEntry($good, 'hanfu', 'https://changanhanfu.com'));
    $pathOnly = $good;
    $pathOnly['full_uri'] = '/';
    $pathOnly['index_key'] = '/';
    self::assertContains('path_only_forbidden', FpcOfflineBakeSchema::validateEntry($pathOnly, 'hanfu', 'https://changanhanfu.com'));
    $crossSite = $good;
    $crossSite['full_uri'] = 'https://daocharms.com/';
    $crossSite['index_key'] = 'https://daocharms.com/';
    self::assertContains('origin_mismatch', FpcOfflineBakeSchema::validateEntry($crossSite, 'hanfu', 'https://changanhanfu.com'));
    $wrongWebsite = $good;
    $wrongWebsite['website_code'] = 'daocharms';
    self::assertContains('website_code_mismatch', FpcOfflineBakeSchema::validateEntry($wrongWebsite, 'hanfu', 'https://changanhanfu.com'));
}

private function assert_FpcOfflineBakeGates_testImportRejectsForeignWebsiteAndPathOnlyPackage(): void
{
    $tmp = \sys_get_temp_dir() . '/fpc-bake-gate-' . \uniqid('', true);
    \mkdir($tmp . '/payloads/ab', 0775, true);
    $body = $tmp . '/payloads/ab/deadbeef.html';
    \file_put_contents($body, '<html>A</html>');
    $sha = \hash('sha256', '<html>A</html>');
    $relBody = 'payloads/ab/' . $sha . '.html';
    \rename($body, $tmp . '/' . $relBody);
    \file_put_contents($tmp . '/manifest.json', \json_encode(['schema' => FpcOfflineBakeSchema::SCHEMA, 'website_code' => 'hanfu', 'primary_origin' => 'https://changanhanfu.com', 'entry_count' => 1], \JSON_UNESCAPED_SLASHES));
    \file_put_contents($tmp . '/entries.jsonl', \json_encode(['full_uri' => 'https://changanhanfu.com/', 'index_key' => 'https://changanhanfu.com/', 'website_code' => 'hanfu', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'body_relpath' => $relBody, 'encoding' => 'identity', 'content_sha256' => $sha], \JSON_UNESCAPED_SLASHES) . "\n");
    $importer = new FpcOfflineBakeImporter();
    $wrongSite = $importer->import($tmp, 'daocharms', 'https://changanhanfu.com', false);
    self::assertFalse($wrongSite['ok']);
    self::assertSame('website_code_mismatch', $wrongSite['reason'] ?? null);
    $wrongOrigin = $importer->import($tmp, 'hanfu', 'https://daocharms.com', false);
    self::assertFalse($wrongOrigin['ok']);
    self::assertSame('origin_mismatch', $wrongOrigin['reason'] ?? null);
    // Path-only entry must abort whole package.
    $tmp2 = \sys_get_temp_dir() . '/fpc-bake-pathonly-' . \uniqid('', true);
    \mkdir($tmp2 . '/payloads/ab', 0775, true);
    \file_put_contents($tmp2 . '/' . $relBody, '<html>A</html>');
    \file_put_contents($tmp2 . '/manifest.json', \json_encode(['schema' => FpcOfflineBakeSchema::SCHEMA, 'website_code' => 'hanfu', 'primary_origin' => 'https://changanhanfu.com'], \JSON_UNESCAPED_SLASHES));
    \file_put_contents($tmp2 . '/entries.jsonl', \json_encode(['full_uri' => '/', 'index_key' => '/', 'website_code' => 'hanfu', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'body_relpath' => $relBody, 'encoding' => 'identity', 'content_sha256' => $sha], \JSON_UNESCAPED_SLASHES) . "\n");
    $pathOnly = $importer->import($tmp2, 'hanfu', 'https://changanhanfu.com', false);
    self::assertFalse($pathOnly['ok']);
    self::assertStringContainsString('path_only_forbidden', (string) ($pathOnly['reason'] ?? ''));
    $this->cleanupDir_FpcOfflineBakeGates($tmp);
    $this->cleanupDir_FpcOfflineBakeGates($tmp2);
}

private function assert_FpcOfflineBakeGates_testExportImportRoundTripHostScopedAndLangHints(): void
{
    $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads' . \DIRECTORY_SEPARATOR . 'ob';
    if (!\is_dir($payloadDir)) {
        \mkdir($payloadDir, 0775, true);
    }
    $origin = 'https://bake-test-' . \uniqid('', true) . '.example';
    $urlZh = FpcPayloadPathIndex::normalizeIndexKey($origin . '/');
    $urlEn = FpcPayloadPathIndex::normalizeIndexKey($origin . '/en_US/');
    $bodyZh = $payloadDir . \DIRECTORY_SEPARATOR . 'bake-zh-' . \uniqid('', true) . '.html';
    $bodyEn = $payloadDir . \DIRECTORY_SEPARATOR . 'bake-en-' . \uniqid('', true) . '.html';
    \file_put_contents($bodyZh, '<html lang="zh">中文</html>');
    \file_put_contents($bodyEn, '<html lang="en">English</html>');
    FpcPayloadPathIndex::remember($urlZh, 'k-zh', $bodyZh);
    FpcPayloadPathIndex::remember($urlEn, 'k-en', $bodyEn);
    $pkg = \sys_get_temp_dir() . '/fpc-bake-rt-' . \uniqid('', true);
    $exporter = new FpcOfflineBakeExporter();
    $exported = $exporter->export($pkg, 'bake_site', $origin, [$urlZh, $urlEn]);
    self::assertTrue($exported['ok'] ?? false, (string) ($exported['reason'] ?? ''));
    self::assertGreaterThanOrEqual(2, (int) ($exported['entry_count'] ?? 0));
    $entries = \file($pkg . '/entries.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
    self::assertIsArray($entries);
    $langs = [];
    foreach ($entries as $line) {
        $row = \json_decode((string) $line, true);
        self::assertIsArray($row);
        $langs[(string) $row['index_key']] = (string) $row['lang'];
    }
    self::assertArrayHasKey($urlZh, $langs, 'langs=' . \implode(',', \array_keys($langs)));
    self::assertArrayHasKey($urlEn, $langs, 'langs=' . \implode(',', \array_keys($langs)));
    self::assertSame('en_US', $langs[$urlEn]);
    self::assertNotSame($langs[$urlZh], $langs[$urlEn], 'zh/en must be distinct variant slots');
    // Purge source refs then import → html-disk only (no path-index remember).
    FpcPayloadPathIndex::purgePaths([$urlZh, $urlEn]);
    $importer = new FpcOfflineBakeImporter();
    $imported = $importer->import($pkg, 'bake_site', $origin, false);
    self::assertTrue($imported['ok'] ?? false, (string) ($imported['reason'] ?? ''));
    self::assertGreaterThanOrEqual(2, (int) ($imported['imported'] ?? 0));
    self::assertSame([], FpcPayloadPathIndex::lookup($urlZh));
    self::assertSame([], FpcPayloadPathIndex::lookup($urlEn));
    $zhHit = FpcHtmlDiskStore::read($urlZh, '');
    $enHit = FpcHtmlDiskStore::read($urlEn, '');
    self::assertNotNull($zhHit);
    self::assertNotNull($enHit);
    self::assertStringContainsString('中文', $this->diskHitPlaintext_FpcOfflineBakeGates($zhHit));
    self::assertStringContainsString('English', $this->diskHitPlaintext_FpcOfflineBakeGates($enHit));
    $zhPath = (string) ($zhHit['path'] ?? '');
    $enPath = (string) ($enHit['path'] ?? '');
    self::assertStringContainsString('generated' . \DIRECTORY_SEPARATOR . 'html', $zhPath);
    self::assertStringNotContainsString('router-fpc-payloads', $zhPath);
    self::assertFalse(ProjectRoot::isAccidentalAppCodeGenerated($zhPath), 'FPC html must not land under app/code/generated');
    self::assertFalse(ProjectRoot::isAccidentalAppCodeGenerated($enPath), 'FPC html must not land under app/code/generated');
    FpcHtmlDiskStore::purgeUrls([$urlZh, $urlEn]);
    $this->cleanupDir_FpcOfflineBakeGates($pkg);
}

/**
 * @param array{body?:string,encoding?:?string,path?:string} $hit
 */
private function diskHitPlaintext_FpcOfflineBakeGates(array $hit): string
{
    $body = (string) ($hit['body'] ?? '');
    if (($hit['encoding'] ?? null) !== 'br') {
        return $body;
    }
    self::assertTrue(\function_exists('brotli_uncompress'), 'br disk leaf needs brotli_uncompress');
    $plain = \brotli_uncompress($body);
    self::assertIsString($plain);
    return $plain;
}

private function cleanupDir_FpcOfflineBakeGates(string $dir): void
{
    if (!\is_dir($dir)) {
        return;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        if ($file->isDir()) {
            @\rmdir($file->getPathname());
        } else {
            @\unlink($file->getPathname());
        }
    }
    @\rmdir($dir);
}

private function setUp_SharedFpcSetCookieSuppress(): void
{
    HeaderCollector::reset();
    RequestContext::cleanup();
    WelineEnv::set('response.from_cache', false, 'unit-test');
}

private function tearDown_SharedFpcSetCookieSuppress(): void
{
    HeaderCollector::reset();
    RequestContext::cleanup();
    WelineEnv::set('response.from_cache', false, 'unit-test');
}

private function assert_SharedFpcSetCookieSuppress_testArmClearsPendingCookiesAndBlocksFurtherSetCookie(): void
{
    $collector = HeaderCollector::getInstance();
    $collector->setCookie('WELINE_SESSID', 'before', \time() + 3600, '/', '', true, true, 'Lax');
    self::assertNotSame([], $collector->getCookies());
    SharedFpcSetCookieSuppress::arm('unit_build');
    self::assertTrue(SharedFpcSetCookieSuppress::isArmed());
    self::assertSame([], $collector->getCookies());
    $collector->setCookie('WELINE_SESSID', 'after', \time() + 3600, '/', '', true, true, 'Lax');
    self::assertSame([], $collector->getCookies());
}

private function assert_SharedFpcSetCookieSuppress_testFromCacheAloneArmsSuppress(): void
{
    self::assertFalse(SharedFpcSetCookieSuppress::isArmed());
    WelineEnv::set('response.from_cache', true, 'unit-test');
    self::assertTrue(SharedFpcSetCookieSuppress::isArmed());
}

private function assert_SharedFpcSetCookieSuppress_testWiringSourceCoversBuildHitAndPublish(): void
{
    // This file lives at Framework/Test/Unit/Http/Fpc/ → up 4 = Framework.
    $frameworkRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $corePath = $frameworkRoot . '/Router/Core.php';
    self::assertFileExists($corePath);
    $core = (string) \file_get_contents($corePath);
    self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'build_lock\')', $core);
    self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'anonymous_shareable_ssr\')', $core);
    $coordinator = (string) \file_get_contents($frameworkRoot . '/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'cache_hit\')', $coordinator);
    self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'can_publish\')', $coordinator);
    $strategy = (string) \file_get_contents($frameworkRoot . '/Session/Strategy/WlsStrategy.php');
    self::assertStringContainsString('SharedFpcSetCookieSuppress::isArmed()', $strategy);
}

private string $dir_StaticAssetBrotliSidecar = '';

private function setUp_StaticAssetBrotliSidecar(): void
{
    ProjectRoot::defineBp(__DIR__);
    $this->dir_StaticAssetBrotliSidecar = BP . 'var' . \DIRECTORY_SEPARATOR . 'tmp' . \DIRECTORY_SEPARATOR . 'static-br-sidecar-' . \bin2hex(\random_bytes(4));
    @\mkdir($this->dir_StaticAssetBrotliSidecar, 0775, true);
}

private function tearDown_StaticAssetBrotliSidecar(): void
{
    if ($this->dir_StaticAssetBrotliSidecar === '' || !\is_dir($this->dir_StaticAssetBrotliSidecar)) {
        return;
    }
    foreach (\glob($this->dir_StaticAssetBrotliSidecar . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @\unlink($file);
    }
    @\rmdir($this->dir_StaticAssetBrotliSidecar);
}

private function assert_StaticAssetBrotliSidecar_testPublishWritesBrForLargeCssAndSkipsTiny(): void
{
    if (!ContentEncodingNegotiator::brotliAvailable()) {
        self::markTestSkipped('ext-brotli required');
    }
    $tiny = $this->dir_StaticAssetBrotliSidecar . \DIRECTORY_SEPARATOR . 'tiny.css';
    \file_put_contents($tiny, \str_repeat('a', 100));
    self::assertFalse(StaticAssetBrotliSidecar::publish($tiny));
    self::assertFileDoesNotExist($tiny . '.br');
    $large = $this->dir_StaticAssetBrotliSidecar . \DIRECTORY_SEPARATOR . 'large.css';
    $body = \str_repeat('body{color:red;}', 200);
    \file_put_contents($large, $body);
    self::assertTrue(StaticAssetBrotliSidecar::publish($large));
    self::assertFileExists($large . '.br');
    $decoded = ContentEncodingNegotiator::decode((string) \file_get_contents($large . '.br'), ContentEncodingNegotiator::ENCODING_BROTLI);
    self::assertSame($body, $decoded);
    self::assertFalse(StaticAssetBrotliSidecar::isEligiblePath($large . '.png'));
    self::assertFalse(StaticAssetBrotliSidecar::isEligiblePath($large . '.br'));
}

private function assert_ResponseObservabilityLean_testProcessTimingInjectorsCallPolicy(): void
{
    $http = \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_http_message.php');
    $ssl = \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl_event.php');
    self::assertIsString($http);
    self::assertIsString($ssl);
    self::assertStringContainsString('ResponseObservabilityPolicy::processTimingHeadersEnabled()', $http);
    self::assertStringContainsString('ResponseObservabilityPolicy::processTimingHeadersEnabled()', $ssl);
}

private function assert_ResponseObservabilityLean_testWorkerIdentityHeadersCallPolicy(): void
{
    $worker = \file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
    self::assertIsString($worker);
    self::assertStringContainsString('ResponseObservabilityPolicy::identityHeadersEnabled()', $worker);
}

private function assert_ResponseObservabilityLean_testRedirectCookieDiagHeaderIsGated(): void
{
    $runtime = \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    self::assertIsString($runtime);
    $headerPos = \strpos($runtime, "setHeader('X-WLS-Redirect-Cookies'");
    self::assertNotFalse($headerPos);
    $window = \substr($runtime, \max(0, $headerPos - 240), 240);
    self::assertStringContainsString('ResponseObservabilityPolicy::performanceBreakdownEnabled()', $window, 'Redirect-Cookies 写头前 240 字符内必须有 Policy 闸门');
}

private function assert_ResponseObservabilityLean_testAdminPerfHeadersCallPolicy(): void
{
    $admin = \file_get_contents(BP . 'app/code/Weline/Admin/Api/Controller/BaseController.php');
    self::assertIsString($admin);
    self::assertStringContainsString('ResponseObservabilityPolicy::performanceBreakdownEnabled()', $admin);
    self::assertStringContainsString('ResponseObservabilityPolicy::dynamicObservabilityEnabled()', $admin);
}

private function assert_ResponseObservabilityLean_testResponsePoweredByIsGated(): void
{
    $response = \file_get_contents(BP . 'app/code/Weline/Framework/Http/Response.php');
    self::assertIsString($response);
    self::assertStringContainsString('ResponseObservabilityPolicy::poweredByHeaderEnabled()', $response);
}

private function assert_ResponseObservabilityLean_testFpcHitPerformanceHelperIsGated(): void
{
    $coord = \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertIsString($coord);
    self::assertStringContainsString('ResponseObservabilityPolicy::performanceBreakdownEnabled()', $coord);
    self::assertStringContainsString('function applyFpcHitPerformanceHeaders', $coord);
}

private function assert_ResponseObservabilityLean_testPolicyDocumentsPublicAllowlist(): void
{
    $policy = \file_get_contents(BP . 'app/code/Weline/Framework/Http/ResponseObservabilityPolicy.php');
    self::assertIsString($policy);
    self::assertStringContainsString('x-weline-request-id', $policy);
    self::assertStringContainsString('x-weline-fpc', $policy);
    self::assertStringContainsString('x-wls-fpc-status', $policy);
    self::assertStringContainsString('processTimingHeadersEnabled', $policy);
}

private function assert_SecurityHeaderDefaults_testSafeCspDefaultIsNonEmptyAndAdminCompatible(): void
{
    $csp = SecurityHeaderDefaults::CSP;
    self::assertNotSame('', \trim($csp));
    self::assertStringContainsString("default-src 'self'", $csp);
    self::assertStringContainsString("img-src 'self' blob: data: https:", $csp);
    self::assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
    // Business / CDN hosts belong to module Extends contributions — not Defaults.
    self::assertStringNotContainsString('https://js.stripe.com', $csp);
    self::assertStringNotContainsString('https://api.stripe.com', $csp);
    self::assertStringNotContainsString('https://turing.captcha.qcloud.com', $csp);
    self::assertStringNotContainsString('https://www.recaptcha.net', $csp);
    self::assertStringNotContainsString('https://www.paypal.com', $csp);
    self::assertStringNotContainsString('https://www.googletagmanager.com', $csp);
    self::assertStringNotContainsString('https://cdn.jsdelivr.net', $csp);
    self::assertStringNotContainsString('https://www.youtube.com', $csp);
    self::assertStringNotContainsString('https://open.weixin.qq.com', $csp);
    self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
    self::assertStringContainsString('font-src', $csp);
    self::assertStringContainsString('media-src', $csp);
    self::assertStringContainsString('frame-src', $csp);
    self::assertStringContainsString('connect-src', $csp);
    self::assertStringContainsString("worker-src 'self' blob:", $csp);
}

private function assert_SecurityHeaderDefaults_testReportOnlyDefaultIsOptInEmpty(): void
{
    self::assertSame('', \trim(SecurityHeaderDefaults::CSP_REPORT_ONLY));
    self::assertSame(SecurityHeaderDefaults::CSP_DELIVERY_META, SecurityHeaderDefaults::CSP_DELIVERY);
}

private function assert_SecurityHeaderDefaults_testCorsDefaultDeniesCrossOriginEcho(): void
{
    self::assertSame('', SecurityHeaderDefaults::CORS_ORIGINS);
}

private function assert_SecurityHeaderDefaults_testEnvSampleAndTemplateCarrySameSafeDefaults(): void
{
    // .../Framework/Test/Unit/Http/Security
    $frameworkRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $repoRoot = \dirname($frameworkRoot, 4);
    $envClass = (string) \file_get_contents($frameworkRoot . '/App/Env.php');
    $sample = (string) \file_get_contents($repoRoot . '/app/etc/env.sample.php');
    $phtml = (string) \file_get_contents($frameworkRoot . '/Extends/module/Weline_SystemConfig/Config/backend/security-headers.phtml');
    $service = (string) \file_get_contents($frameworkRoot . '/Http/Security/SecurityHeaderPolicyService.php');
    foreach ([$envClass, $sample, $phtml] as $src) {
        self::assertNotSame('', $src);
        self::assertStringContainsString(SecurityHeaderDefaults::CSP, $src);
    }
    self::assertStringContainsString('csp_delivery', $envClass);
    self::assertStringContainsString('SecurityHeaderDefaults::CSP', $service);
    self::assertStringContainsString('SecurityHeaderDefaults::CSP_REPORT_ONLY', $service);
    self::assertStringContainsString("\$csp !== '' ? \$csp : SecurityHeaderDefaults::CSP", $service);
    self::assertStringContainsString('compactForWire', $service);
}

private function assert_StaticErrorPagePublisher_testPublisherUsesFiberEventsAndAtomicHostMap(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/StaticErrorPagePublisher.php');
    self::assertStringContainsString('FiberTaskRunner', $source);
    self::assertStringContainsString('FiberTaskBatch', $source);
    self::assertStringContainsString('settle(', $source);
    self::assertStringContainsString('writeHostMapAtomic', $source);
    self::assertStringContainsString('WELINE_STATIC_ERROR_CONCURRENCY', $source);
    self::assertStringContainsString('localesForWebsite', $source);
    self::assertStringContainsString("w_query('websites', 'getStaticErrorPublishTargets'", $source);
    self::assertStringNotContainsString('Weline\Websites\Model\Website::', $source);
    self::assertStringNotContainsString('WebsiteLanguage::', $source);
    self::assertStringNotContainsString('WebsiteDomain::', $source);
    // Must not claim multi-core parallelism in progress copy.
    self::assertStringNotContainsString('多核并行', $source);
}

private function assert_StaticErrorPagePublisher_testErrorPageRendererForwardsHost(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/ErrorPageRenderer.php');
    self::assertStringContainsString('request_host', $source);
    self::assertStringContainsString('HTTP_HOST', $source);
    self::assertStringContainsString('loadHtml(', $source);
}

private function assert_StaticErrorPagePublisher_testDefaultConcurrencyIsFour(): void
{
    $publisher = new StaticErrorPagePublisher();
    self::assertSame(4, $publisher->resolveConcurrency(null));
    self::assertSame(2, $publisher->resolveConcurrency(2));
}

private function setUp_StorefrontGenerationOutbound(): void
{
    HeaderCollector::reset();
    RequestContext::cleanup();
    CookieScope::resetRequestState();
    WelineEnv::set('response.from_cache', false, 'unit-test');
    WelineEnv::set('is_static_file', false, 'unit-test');
}

private function tearDown_StorefrontGenerationOutbound(): void
{
    HeaderCollector::reset();
    RequestContext::cleanup();
    CookieScope::resetRequestState();
    WelineEnv::set('response.from_cache', false, 'unit-test');
    WelineEnv::set('is_static_file', false, 'unit-test');
}

private function assert_StorefrontGenerationOutbound_testCookieNameIsProtocolExactWireName(): void
{
    self::assertTrue(CookieScope::isProtocolCookie(StorefrontGenerationOutbound::COOKIE_NAME));
    self::assertSame('weline_storefront_gen', StorefrontGenerationOutbound::COOKIE_NAME);
}

private function assert_StorefrontGenerationOutbound_testApplySetsReadableCookieAndVaryCookieHeaderName(): void
{
    if (!\class_exists(\Weline\Frontend\Helper\StorefrontGenerationIdentity::class)) {
        self::markTestSkipped('Frontend StorefrontGenerationIdentity unavailable');
    }
    $id = \trim((string) \Weline\Frontend\Helper\StorefrontGenerationIdentity::currentVersionId());
    if ($id === '') {
        self::markTestSkipped('No published storefront generation identity in this env');
    }
    $response = new Response(true);
    $response->setHeader('Content-Type', 'text/html; charset=utf-8');
    $response->setBody('<html></html>');
    StorefrontGenerationOutbound::applyToResponse($response);
    $cookies = $response->getCookies();
    $found = null;
    foreach ($cookies as $cookie) {
        if (($cookie['name'] ?? '') === StorefrontGenerationOutbound::COOKIE_NAME) {
            $found = $cookie;
            break;
        }
    }
    self::assertNotNull($found, 'Expected identity Set-Cookie on non-suppressed response');
    self::assertSame($id, (string) $found['value']);
    self::assertFalse((bool) $found['httpOnly'], 'Identity cookie must be JS-readable');
    $vary = (string) ($response->getHeader('Vary') ?? '');
    self::assertMatchesRegularExpression('/(^|,\s*)Cookie(\s*,|$)/i', $vary);
    self::assertStringNotContainsString($id, $vary, 'Vary must list header names, not the identity value');
}

private function assert_StorefrontGenerationOutbound_testSharedFpcSuppressBlocksIdentitySetCookie(): void
{
    SharedFpcSetCookieSuppress::arm('unit_storefront_gen');
    $response = new Response(true);
    $response->setBody('ok');
    // Even with a forced apply, suppress must win.
    $response->setCookie(StorefrontGenerationOutbound::COOKIE_NAME, 'forced-id', \time() + 60, '/', '', false, false, 'Lax');
    self::assertSame([], $response->getCookies());
}

private function assert_StorefrontGenerationOutbound_testResponsePrepareForEmissionWiresOutboundHelper(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Response.php');
    self::assertStringContainsString('applyStorefrontGenerationOutboundIfNeeded', $src);
    self::assertStringContainsString('StorefrontGenerationOutbound::applyToResponse', $src);
}

private function assert_StorefrontGenerationOutbound_testOutboundResolveVersionIdOnlyUsesStorefrontGenerationIdentity(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/StorefrontGenerationOutbound.php');
    self::assertStringContainsString('StorefrontGenerationIdentity::currentVersionId()', $src);
    self::assertStringNotContainsString('deploy_version', $src);
    self::assertStringNotContainsString('deployVersion', $src);
    // Single cookie write path — no alternate setCookie of this name in Framework Http.
    $httpDir = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http';
    $hits = 0;
    foreach (\glob($httpDir . '/*.php') ?: [] as $file) {
        $body = (string) \file_get_contents($file);
        if (\str_contains($body, "COOKIE_NAME") && \str_contains($body, 'weline_storefront_gen')) {
            ++$hits;
        }
    }
    self::assertSame(1, $hits, 'Only StorefrontGenerationOutbound may own weline_storefront_gen constant');
}

private function assert_StorefrontGenerationOutbound_testWelineJsReadsIdentityCookieConstant(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline.js');
    self::assertStringContainsString('weline_storefront_gen', $js);
    self::assertStringContainsString('promptStorefrontGenerationRefresh', $js);
    self::assertStringContainsString('themePublishedVersionId', $js);
    self::assertStringNotContainsString('window.confirm(', $js);
}

private function assert_UrlExpandModuleStarPath_testBackendUrlExpandsStarViaHelper(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Url.php');
    self::assertStringContainsString('function expandModuleStarPath', $source);
    self::assertStringContainsString('expandModuleStarPath($path)', $source);
    self::assertStringContainsString('env.php', $source);
    self::assertStringContainsString('缺 frontName', $source);
}

private function assert_UrlFrontendMountCookieIsolation_testUrlFrontendResolveWebsiteMountPathHonorsEmptyDomAttribute(): void
{
    $source = $this->read_UrlFrontendMountCookieIsolation('View/statics/js/url-frontend.js');
    $at = \strpos($source, 'function resolveWebsiteMountPath');
    self::assertNotFalse($at);
    $body = \substr($source, $at, 1400);
    self::assertStringContainsString("hasAttribute('data-website-mount')", $body);
    self::assertStringContainsString('SSR-authoritative', $body);
    self::assertStringNotContainsString("getCookie('WELINE_WEBSITE_URL')", $body);
    self::assertStringContainsString('No sticky WELINE_WEBSITE_URL', $body);
}

private function assert_UrlFrontendMountCookieIsolation_testI18nResolveWebsiteMountPathHonorsEmptyDomAttribute(): void
{
    $source = $this->read_UrlFrontendMountCookieIsolation('View/statics/js/i18n.js');
    $at = \strpos($source, 'function resolveWebsiteMountPath');
    self::assertNotFalse($at);
    $body = \substr($source, $at, 1200);
    self::assertStringContainsString("hasAttribute('data-website-mount')", $body);
    self::assertStringContainsString('SSR-authoritative', $body);
    self::assertStringNotContainsString("readCookieValue('WELINE_WEBSITE_URL')", $body);
    self::assertStringContainsString('No sticky WELINE_WEBSITE_URL', $body);
}

private function read_UrlFrontendMountCookieIsolation(string $relative): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/' . \ltrim($relative, '/');
    $content = \file_get_contents($path);
    self::assertIsString($content, $path);
    return $content;
}

private function assert_UrlMountTrailingSlashConsume_testExactMountTrailingSlashNormalizesToEmptyRemainder(): void
{
    $method = new ReflectionMethod(Url::class, 'tryConsumeWebsiteBaseUrl');
    $method->setAccessible(true);
    $base = 'https://p05113ef3.test.weline.com/~site/grocery';
    $slash = $method->invoke(null, $base . '/', $base);
    self::assertTrue($slash['matched']);
    self::assertSame('', $slash['remainder']);
    $bare = $method->invoke(null, $base, $base);
    self::assertTrue($bare['matched']);
    self::assertSame('', $bare['remainder']);
    $deeper = $method->invoke(null, $base . '/products', $base);
    self::assertTrue($deeper['matched']);
    self::assertSame('/products', $deeper['remainder']);
}

private function assert_UrlNormalizeVisitorUri_testNormalizeVisitorUriHelperIsPublicAndDocumentedForSiteProbe(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $source = (string) file_get_contents($root . '/Http/Url.php');
    self::assertStringContainsString('public static function normalizeVisitorUri(string $uri): array', $source);
    self::assertStringContainsString('public static function applyVisitorUriNormalizeToUrl(string $requestUrl): string', $source);
    self::assertStringContainsString('Weline_Framework_Url::normalize_visitor_uri', $source);
    self::assertStringContainsString('self::normalizeVisitorUri((string)$uri)', $source);
}

private function assert_UrlNormalizeVisitorUri_testNormalizeVisitorUriPassthroughWithoutMountObserverMutation(): void
{
    $normalized = Url::normalizeVisitorUri('/~site/daocharms/about');
    self::assertSame('/~site/daocharms/about', $normalized['routing_uri']);
    self::assertSame('/~site/daocharms/about', $normalized['origin_uri']);
}

private function assert_UrlNormalizeVisitorUri_testAppInstallsStorefrontScopeFromOriginUriSoMountsSurvive(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $app = (string) file_get_contents($root . '/App.php');
    self::assertStringContainsString('$routingFullRequestUri', $app);
    self::assertStringContainsString('$originFullRequestUri', $app);
    self::assertStringContainsString('$scopeInstallUri', $app);
    self::assertStringContainsString('$this->installStorefrontNavigationScope(', $app);
    // DetectWebsite re-resolves Website from the install URL — origin keeps
    // /~site/{code}; peeled routing "/" would bind the project-Host default.
    self::assertMatchesRegularExpression('/\$scopeInstallUri = \$originFullRequestUri !== \'\' \? \$originFullRequestUri : \$routingFullRequestUri;[\s\S]*?installStorefrontNavigationScope\(\s*\$scopeInstallUri/', $app);
    self::assertStringContainsString('WELINE_FULL_REQUEST_URI', $app);
    self::assertStringContainsString('DetectWebsite::installNavigationScope re-resolves', $app);
}

private function setUp_UrlParserDefaultLanguage(): void
{
    parent::setUp();
    Url::$parserServer = [];
    Url::resetWebsiteParserSites();
    Url::resetParserRequestCaches();
    State::resetRequestPathLocalizationCache();
    State::resetLangLocalCache();
}

private function tearDown_UrlParserDefaultLanguage(): void
{
    Url::$parserServer = [];
    Url::resetWebsiteParserSites();
    Url::resetParserRequestCaches();
    State::resetRequestPathLocalizationCache();
    State::resetLangLocalCache();
    parent::tearDown();
}

private function assert_UrlParserDefaultLanguage_test_parser_source_avoids_premature_state_lang_seed(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Url.php');
    self::assertStringContainsString('// Do not seed USER_LANG/CURRENCY from State before website match.', $source);
    self::assertStringContainsString("self::\$parserServer['WELINE_USER_LANG'] = '';", $source);
    self::assertStringContainsString('// Prefer website defaults for bare-path; path locale detection overwrites later.', $source);
    self::assertDoesNotMatchRegularExpression("/WELINE_AREA' = 'frontend';\\s*\\n\\s*self::\\{$parserServer}\\['WELINE_USER_CURRENCY'\\] = State::getCurrency\\(\\);\\s*\\n\\s*self::\\{$parserServer}\\['WELINE_USER_LANG'\\] = State::getLang\\(\\);/", $source);
}

private function assert_UrlParserDefaultLanguage_test_website_data_language_codes_keep_association_order(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Websites/Data/WebsiteData.php');
    self::assertStringNotContainsString('preferDefaultLanguageFirst', $source);
    self::assertStringContainsString('function defaultLanguageForWebsite', $source);
    self::assertStringContainsString('Bare-path / routing', $source);
}

private function assert_UrlParserDefaultLanguage_test_url_parser_uses_default_language_field_not_codes_zero(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Url.php');
    self::assertStringContainsString("\$site['default_language']", $source);
    self::assertStringNotContainsString('language_codes[0]', $source);
}

private array $serverBackup_UrlPathMountOutbound = [];

private array $envSnapshot_UrlPathMountOutbound = [];

private function setUp_UrlPathMountOutbound(): void
{
    parent::setUp();
    $this->serverBackup_UrlPathMountOutbound = $_SERVER;
    $this->envSnapshot_UrlPathMountOutbound = WelineEnv::getInstance()->capture();
}

private function tearDown_UrlPathMountOutbound(): void
{
    $_SERVER = $this->serverBackup_UrlPathMountOutbound;
    WelineEnv::getInstance()->restore($this->envSnapshot_UrlPathMountOutbound);
    parent::tearDown();
}

private function assert_UrlPathMountOutbound_testResolveCurrentWebsiteMountPathFromWebsiteUrl(): void
{
    $_SERVER['WELINE_WEBSITE_URL'] = 'https://pre.example.test/aisite_accept_ok';
    WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
    WelineEnv::set('website_url', 'https://pre.example.test/aisite_accept_ok', 'mount outbound test');
    self::assertSame('/aisite_accept_ok', Url::resolveCurrentWebsiteMountPath());
}

private function assert_UrlPathMountOutbound_testPeelMountPrefixFromRelativePath(): void
{
    $_SERVER['WELINE_WEBSITE_URL'] = 'https://pre.example.test/aisite_accept_ok';
    WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
    WelineEnv::set('website_url', 'https://pre.example.test/aisite_accept_ok', 'mount outbound test');
    self::assertSame('/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/about'));
    self::assertSame('/hi_IN/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/hi_IN/about'));
    self::assertSame('/about?x=1', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/about?x=1'));
    self::assertSame('/about', Url::peelWebsiteMountPathFromRelativePath('about'));
    self::assertSame('/', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok'));
    // 段边界：不误剥 /aisite_accept_ok_extra
    self::assertSame('/aisite_accept_ok_extra/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok_extra/about'));
}

private function assert_UrlPathMountOutbound_testRootSitePeelIsNoOp(): void
{
    $_SERVER['WELINE_WEBSITE_URL'] = 'https://pre.example.test';
    WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
    WelineEnv::set('website_url', 'https://pre.example.test', 'mount outbound test');
    self::assertSame('', Url::resolveCurrentWebsiteMountPath());
    self::assertSame('/aisite_accept_ok/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/about'));
}

private function setUp_UrlProcessDecodeCacheKey(): void
{
    parent::setUp();
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架/app' . \DIRECTORY_SEPARATOR);
    }
    if (!\defined('Weline\Framework\App\BP')) {
        \define('Weline\Framework\App\BP', BP);
    }
}

private function tearDown_UrlProcessDecodeCacheKey(): void
{
    Url::resetParserRequestCaches();
    parent::tearDown();
}

private function assert_UrlProcessDecodeCacheKey_testProcessDecodeKeyIgnoresCurrentRequestUri(): void
{
    $method = new ReflectionMethod(Url::class, 'buildUrlProcessDecodeCacheKey');
    $method->setAccessible(true);
    $seoUrl = '/zh_Hans_CN/product/demo-sku';
    $serverBase = ['REQUEST_SCHEME' => 'https', 'HTTP_HOST' => 'shop.test:9555', 'WELINE_AREA' => 'frontend', 'WELINE_WEBSITE_ID' => '0', 'WELINE_WEBSITE_CODE' => 'default', 'WELINE_USER_LANG' => 'zh_Hans_CN', 'WELINE_USER_CURRENCY' => 'CNY'];
    $_SERVER = \array_replace($_SERVER, $serverBase, ['REQUEST_URI' => '/zh_Hans_CN/product/aaa', 'WELINE_ORIGIN_REQUEST_URI' => '/zh_Hans_CN/product/aaa', 'WELINE_FULL_REQUEST_URI' => 'https://shop.test:9555/zh_Hans_CN/product/aaa', 'WELINE_AREA_ROUTE' => 'product/aaa']);
    Url::resetParserRequestCaches();
    $keyA = (string) $method->invoke(null, $seoUrl, '0');
    $_SERVER = \array_replace($_SERVER, $serverBase, ['REQUEST_URI' => '/zh_Hans_CN/product/bbb', 'WELINE_ORIGIN_REQUEST_URI' => '/zh_Hans_CN/product/bbb', 'WELINE_FULL_REQUEST_URI' => 'https://shop.test:9555/zh_Hans_CN/product/bbb', 'WELINE_AREA_ROUTE' => 'product/bbb']);
    Url::resetParserRequestCaches();
    $keyB = (string) $method->invoke(null, $seoUrl, '0');
    self::assertNotSame('', $keyA);
    self::assertSame($keyA, $keyB, 'process decode key must not shard by current PDP request_uri');
}

private function assert_UrlProcessDecodeCacheKey_testProcessDecodeKeyIgnoresLangMutationAfterParser(): void
{
    $method = new ReflectionMethod(Url::class, 'buildUrlProcessDecodeCacheKey');
    $method->setAccessible(true);
    $seoUrl = '/en_US/about';
    $serverBase = ['REQUEST_SCHEME' => 'https', 'HTTP_HOST' => 'shop.test:9555', 'WELINE_AREA' => 'frontend', 'WELINE_WEBSITE_ID' => '0', 'WELINE_WEBSITE_CODE' => 'default', 'WELINE_USER_CURRENCY' => 'CNY', 'REQUEST_URI' => '/en_US/about', 'WELINE_ORIGIN_REQUEST_URI' => '/en_US/about'];
    $_SERVER = \array_replace($_SERVER, $serverBase, ['WELINE_USER_LANG' => '']);
    Url::resetParserRequestCaches();
    $keyBefore = (string) $method->invoke(null, $seoUrl, '0');
    $_SERVER = \array_replace($_SERVER, $serverBase, ['WELINE_USER_LANG' => 'en_US']);
    Url::resetParserRequestCaches();
    $keyAfter = (string) $method->invoke(null, $seoUrl, '0');
    self::assertSame($keyBefore, $keyAfter, 'process decode key must not shard when WELINE_USER_LANG is written after parse');
}

private function assert_UrlProcessDecodeCacheKey_testProcessDecodeKeyUnifiesAbsoluteUrlAndPathOnly(): void
{
    $method = new ReflectionMethod(Url::class, 'buildUrlProcessDecodeCacheKey');
    $method->setAccessible(true);
    $serverBase = ['REQUEST_SCHEME' => 'https', 'HTTP_HOST' => 'shop.test:9555', 'WELINE_AREA' => 'frontend', 'WELINE_WEBSITE_ID' => '0', 'WELINE_WEBSITE_CODE' => 'default', 'WELINE_USER_LANG' => 'en_US', 'WELINE_USER_CURRENCY' => 'CNY', 'REQUEST_URI' => '/en_US/about', 'WELINE_ORIGIN_REQUEST_URI' => '/en_US/about'];
    $_SERVER = \array_replace($_SERVER, $serverBase);
    Url::resetParserRequestCaches();
    $keyPath = (string) $method->invoke(null, '/en_US/about', '0');
    $keyAbsolute = (string) $method->invoke(null, 'https://shop.test:9555/en_US/about', '0');
    self::assertSame($keyPath, $keyAbsolute, 'absolute and path-only forms must share lookup-path identity');
}

private function assert_UrlProcessDecodeCacheKey_testProcessDecodeKeySourceDocumentsLookupPathIdentity(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Url.php');
    self::assertStringContainsString('seoDecodeLookupIdentity', $src);
    self::assertStringContainsString('20261016-lookup-path-identity', $src);
    self::assertStringContainsString("'lookup_path'", $src);
}

private function assert_UrlWorkerListenPortStrip_testStripWorkerListenPortKeepsPathQueryAndFragmentWithoutPregWarning(): void
{
    $method = new \ReflectionMethod(Url::class, 'stripWorkerListenPortFromUrl');
    $method->setAccessible(true);
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $severity . ':' . $message;
        return true;
    });
    try {
        self::assertSame('https://p05113ef3.test.weline.com/product/x', $method->invoke(null, 'https://p05113ef3.test.weline.com:9555/product/x', '9555'));
        self::assertSame('https://host.example/path?q=1', $method->invoke(null, 'https://host.example:9510/path?q=1', '9510'));
        self::assertSame('https://host.example/path#section', $method->invoke(null, 'https://host.example:9510/path#section', '9510'));
        self::assertSame('https://host.example:443/ok', $method->invoke(null, 'https://host.example:443/ok', '9510'));
    } finally {
        restore_error_handler();
    }
    self::assertSame([], $warnings, 'preg_replace must not emit Unknown modifier warnings');
}
}
