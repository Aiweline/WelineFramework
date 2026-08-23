<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Commerce;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Authorization\Resource\SourceIdParser;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/** Shared static gate for the R4.3 capability/menu/ACL/E2E manifest. */
final class CommerceAdminMenuContractTest extends TestCase
{
    private const MANIFEST_PATH = 'tests/e2e/manifests/commerce-kernel-r43.json';

    /** @return array<string,mixed> */
    private static function manifest(): array
    {
        $path = BP . self::MANIFEST_PATH;
        self::assertFileExists($path);
        $manifest = \json_decode((string)\file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('commerce-kernel-r43.v1', $manifest['schema'] ?? null);
        self::assertIsArray($manifest['capabilities'] ?? null);
        self::assertIsArray($manifest['accessProfiles'] ?? null);
        self::assertNotSame([], $manifest['accessProfiles'], 'Manifest accessProfiles must be explicit');
        foreach ($manifest['accessProfiles'] as $profile => $enabled) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', (string)$profile);
            self::assertTrue($enabled, 'Disabled accessProfile declaration: ' . $profile);
        }
        self::assertIsArray($manifest['globalCaseIds'] ?? null);
        self::assertNotSame([], $manifest['globalCaseIds'], 'Manifest globalCaseIds must be explicit');
        self::assertSame(
            \count($manifest['globalCaseIds']),
            \count(\array_unique($manifest['globalCaseIds'])),
            'Duplicate globalCaseIds are forbidden',
        );
        foreach ($manifest['globalCaseIds'] as $caseId) {
            self::assertMatchesRegularExpression('/^CK-R43-[A-Z0-9-]+$/', (string)$caseId);
        }
        self::assertIsArray($manifest['nonUiCapabilities'] ?? null);
        self::assertNotSame([], $manifest['nonUiCapabilities'], 'Non-UI capabilities must be explicit');
        return $manifest;
    }

    /**
     * @return array<string,list<array{source:string,parent:string,title:string,action:string,file:string}>>
     */
    private static function menus(): array
    {
        $catalog = [];
        foreach (\glob(BP . 'app/code/Weline/*/etc/backend/menu.xml') ?: [] as $file) {
            $document = \simplexml_load_file($file);
            self::assertNotFalse($document, 'Invalid menu XML: ' . $file);
            self::walkMenus($document->menu, '', $file, $catalog);
        }
        return $catalog;
    }

    /** @return array<string,string> absolute path => source */
    private static function r43SpecFiles(): array
    {
        $files = [];
        foreach ([BP . 'app/code/Weline', BP . 'tests/e2e'] as $root) {
            if (!\is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || !\str_ends_with($file->getFilename(), '.spec.js')) {
                    continue;
                }
                $source = (string)\file_get_contents($file->getPathname());
                if (\str_contains($source, 'CK-R43-')) {
                    $files[$file->getPathname()] = $source;
                }
            }
        }
        return $files;
    }

    /**
     * Build an exact original TEST-ID → current executable-source map.
     * Historical ledgers, manifests and fixtures are deliberately excluded.
     *
     * @param array<string,mixed> $traceability
     * @return array<string,list<string>>
     */
    private static function currentOriginalEvidenceMap(array $traceability): array
    {
        self::assertSame(
            'exact-id-in-current-executable-source-plus-current-head-run',
            $traceability['currentEvidencePolicy'] ?? null,
        );
        self::assertFalse($traceability['fixtureOnlyEvidenceAllowed'] ?? true);
        self::assertIsArray($traceability['currentEvidenceRoots'] ?? null);

        $evidence = [];
        foreach ($traceability['currentEvidenceRoots'] as $relativeRoot) {
            $relativeRoot = \trim((string)$relativeRoot, '/');
            $root = BP . $relativeRoot;
            self::assertDirectoryExists($root, 'Missing current evidence root: ' . $relativeRoot);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = \str_replace('\\', '/', $file->getPathname());
                $filename = $file->getFilename();
                if (\str_contains($path, '/Fixture/')
                    || \str_contains(\strtolower($filename), '-fixture.')
                ) {
                    continue;
                }
                $isPhpUnit = \str_ends_with($filename, 'Test.php')
                    && (\str_contains($path, '/Test/') || \str_contains($path, '/test/'));
                $isPlaywright = \str_ends_with($filename, '.spec.js');
                if (!$isPhpUnit && !$isPlaywright) {
                    continue;
                }

                $source = (string)\file_get_contents($path);
                \preg_match_all('/\b(TEST-[A-Z0-9][A-Z0-9-]*)\b/', $source, $matches);
                if (($matches[1] ?? []) === []) {
                    continue;
                }
                if ($isPhpUnit) {
                    self::assertMatchesRegularExpression(
                        '/extends\s+(?:\\\\)?(?:TestCase|TestCore)\b/',
                        $source,
                        'Invalid PHPUnit evidence: ' . $path,
                    );
                } elseif ($isPlaywright) {
                    self::assertMatchesRegularExpression(
                        '/\b(?:test(?:\.describe)?|moduleCase|moduleDescribe)\s*\(/',
                        $source,
                        'Invalid Playwright evidence: ' . $path,
                    );
                }
                foreach (\array_unique($matches[1] ?? []) as $caseId) {
                    $evidence[$caseId][] = \substr($path, \strlen(BP));
                }
            }
        }
        foreach ($evidence as &$paths) {
            $paths = \array_values(\array_unique($paths));
            \sort($paths, SORT_STRING);
        }
        unset($paths);

        return $evidence;
    }

    /**
     * @param iterable<\SimpleXMLElement> $nodes
     * @param array<string,list<array{source:string,parent:string,title:string,action:string,file:string}>> $catalog
     */
    private static function walkMenus(iterable $nodes, string $nestedParent, string $file, array &$catalog): void
    {
        foreach ($nodes as $menu) {
            $source = \trim((string)$menu['source']);
            self::assertNotSame('', $source, 'Menu without source: ' . $file);
            $parent = \trim((string)$menu['parent']);
            if ($parent === '') {
                $parent = $nestedParent;
            }
            $catalog[$source][] = [
                'source' => $source,
                'parent' => $parent,
                'title' => \trim((string)$menu['title']),
                'action' => \trim((string)$menu['action']),
                'file' => $file,
            ];
            self::walkMenus($menu->menu, $source, $file, $catalog);
        }
    }

    public function testManifestIsUniqueAndDecisionComplete(): void
    {
        $manifest = self::manifest();
        $capabilities = $manifest['capabilities'];
        self::assertSame($manifest['expectedVisibleCapabilityCount'] ?? null, \count($capabilities));

        $required = [
            'capabilityId', 'module', 'parentSource', 'sourceId', 'title', 'action',
            'urlIncludes', 'pageAnchor', 'accessProfile', 'businessCaseIds', 'mutable', 'cleanup', 'controller',
        ];
        $capabilityIds = [];
        $sources = [];
        $actions = [];
        $titlesByParent = [];
        $accessProfiles = $manifest['accessProfiles'];

        foreach ($capabilities as $index => $capability) {
            self::assertIsArray($capability, 'Capability row must be an object at index ' . $index);
            foreach ($required as $key) {
                self::assertArrayHasKey($key, $capability, "Capability {$index} misses {$key}");
            }

            $id = \trim((string)$capability['capabilityId']);
            $source = \trim((string)$capability['sourceId']);
            $action = \trim((string)$capability['action']);
            $effectiveAction = \strtolower(\rtrim((string)(\parse_url('/' . \ltrim($action, '/'), PHP_URL_PATH) ?: ''), '/'));
            $parent = \trim((string)$capability['parentSource']);
            $title = \trim((string)$capability['title']);
            $module = \trim((string)$capability['module']);
            $anchor = \trim((string)$capability['pageAnchor']);

            self::assertMatchesRegularExpression('/^CK-[A-Z0-9-]+$/', $id);
            self::assertArrayNotHasKey($id, $capabilityIds, 'Duplicate capabilityId: ' . $id);
            self::assertArrayNotHasKey($source, $sources, 'Duplicate sourceId: ' . $source);
            self::assertArrayNotHasKey($effectiveAction, $actions, 'Duplicate effective menu action: ' . $action);
            self::assertArrayNotHasKey($parent . "\0" . $title, $titlesByParent, 'Duplicate sibling title: ' . $title);
            $capabilityIds[$id] = true;
            $sources[$source] = true;
            $actions[$effectiveAction] = true;
            $titlesByParent[$parent . "\0" . $title] = true;

            $parsed = SourceIdParser::parse($source);
            self::assertNotNull($parsed, 'Invalid sourceId: ' . $source);
            self::assertSame($module, $parsed['module'], 'sourceId module mismatch: ' . $id);
            self::assertNotSame('', $parent, 'Missing parentSource: ' . $id);
            self::assertNotSame('', $title, 'Missing title: ' . $id);
            self::assertNotSame('', $action, 'Missing action: ' . $id);
            self::assertSame(strtolower($action), $action, 'Menu action must be canonical lowercase: ' . $id);
            self::assertNotSame('', \trim((string)$capability['urlIncludes']), 'Missing URL assertion: ' . $id);
            self::assertStringNotContainsString('*', $action, 'Wildcard menu action: ' . $id);
            self::assertDoesNotMatchRegularExpression(
                '#/(?:create|edit|view|generate)(?:[/?]|$)#i',
                $action,
                'Object action used as management menu: ' . $id,
            );
            self::assertMatchesRegularExpression(
                '/^\[data-testid=["\']?[a-z0-9][a-z0-9_-]*["\']?\]$/i',
                $anchor,
                'pageAnchor must be a dedicated data-testid selector: ' . $id,
            );
            self::assertIsArray($capability['businessCaseIds']);
            self::assertNotSame([], $capability['businessCaseIds'], 'No E2E case mapped: ' . $id);
            foreach ($capability['businessCaseIds'] as $caseId) {
                self::assertMatchesRegularExpression('/^CK-R43-[A-Z0-9-]+$/', (string)$caseId);
            }
            self::assertIsBool($capability['mutable']);
            self::assertArrayHasKey(
                (string)$capability['accessProfile'],
                $accessProfiles,
                'Unknown accessProfile: ' . $capability['accessProfile'],
            );
            self::assertNotSame('', \trim((string)$capability['cleanup']));
            if ($capability['mutable']) {
                self::assertIsArray(
                    $capability['mutationCaseIds'] ?? null,
                    'Mutable capability must declare mutationCaseIds: ' . $id,
                );
                self::assertNotSame(
                    [],
                    $capability['mutationCaseIds'],
                    'Mutable capability must map a real WebUI mutation: ' . $id,
                );
                self::assertNotSame('none', $capability['cleanup'], 'Mutable capability needs cleanup: ' . $id);
                foreach ($capability['mutationCaseIds'] as $mutationCaseId) {
                    self::assertContains(
                        $mutationCaseId,
                        $capability['businessCaseIds'],
                        'Mutation case must also be a business case: ' . $id,
                    );
                }
            } else {
                self::assertSame(
                    [],
                    $capability['mutationCaseIds'] ?? [],
                    'Read-only capability cannot claim a mutation case: ' . $id,
                );
            }
            self::assertNotSame('', \trim((string)$capability['controller']));
        }
    }

    public function testEveryManifestActionResolvesToAGeneratedBackendRoute(): void
    {
        $routerFile = BP . 'generated/routers/backend_pc.php';
        self::assertFileExists(
            $routerFile,
            'Backend routes must be generated before validating the R4.3 capability manifest',
        );
        $registered = require $routerFile;
        self::assertIsArray($registered);
        $routes = [];
        foreach (
            array_keys($registered) as $routeKey
        ) {
            $route = explode('::', strtolower((string)$routeKey), 2)[0];
            $routes[$route] = true;
        }

        foreach (self::manifest()['capabilities'] as $capability) {
            $action = strtolower((string)$capability['action']);
            $path = trim((string)(parse_url('/' . ltrim($action, '/'), PHP_URL_PATH) ?: ''), '/');
            $indexRoute = str_ends_with($path, '/index') ? substr($path, 0, -6) : $path;
            self::assertTrue(
                isset($routes[$path]) || isset($routes[$indexRoute]),
                'Manifest action has no generated backend route: '
                    . $capability['sourceId'] . ' => ' . $action,
            );
        }
    }

    public function testUserSpecificSidebarChromeCannotUseRoleOnlyCache(): void
    {
        $sidebar = BP . 'app/code/Weline/Theme/view/theme/backend/partials/sidebar/left.phtml';
        $content = BP . 'app/code/Weline/Admin/view/templates/common/left-sidebar.phtml';
        self::assertFileExists($sidebar);
        self::assertFileExists($content);
        self::assertStringContainsString('@meta.cache.auth {default="user"', (string)\file_get_contents($sidebar));
        self::assertStringContainsString('getFrequentMenus(', (string)\file_get_contents($content));
        self::assertStringContainsString('data-backend-user-id=', (string)\file_get_contents($content));
    }

    public function testBackendChromeUsesExistingSameOriginAssetsUnderStrictCsp(): void
    {
        $topbar = BP . 'app/code/Weline/Admin/Block/Backend/Page/Topbar.php';
        $message = BP . 'app/code/Weline/Component/view/templates/message.phtml';
        $logo = BP . 'app/code/Weline/Admin/view/statics/img/logo.png';
        self::assertFileExists($topbar);
        self::assertFileExists($message);
        self::assertFileExists($logo);

        $topbarSource = (string)\file_get_contents($topbar);
        self::assertStringContainsString('Weline_Admin::img/logo.png', $topbarSource);
        self::assertStringContainsString('$avatar === \'Weline_Admin::img/logo.jpg\'', $topbarSource);
        self::assertStringNotContainsString("setConfig('admin_default_avatar'", $topbarSource);
        self::assertStringNotContainsString('data:image/', (string)\file_get_contents($message));
    }

    public function testOrderBackendTemplatesUseCurrentSchemaFieldConstants(): void
    {
        $templates = \glob(BP . 'app/code/Weline/Order/view/templates/Backend/Order/*.phtml') ?: [];
        self::assertNotSame([], $templates);
        foreach ($templates as $template) {
            self::assertStringNotContainsString(
                '::fields_',
                (string)\file_get_contents($template),
                'Order backend template still references a removed ORM field alias: ' . $template,
            );
        }
    }

    public function testNonUiCapabilitiesHaveExplicitCliOrPhpunitEvidence(): void
    {
        $seen = [];
        foreach (self::manifest()['nonUiCapabilities'] as $index => $capability) {
            self::assertIsArray($capability, 'Non-UI row must be an object at index ' . $index);
            foreach (['capabilityId', 'module', 'validationLayer', 'businessCaseIds', 'evidence', 'evidencePaths'] as $key) {
                self::assertArrayHasKey($key, $capability, "Non-UI capability {$index} misses {$key}");
            }
            $id = \trim((string)$capability['capabilityId']);
            self::assertMatchesRegularExpression('/^CK-[A-Z0-9-]+$/', $id);
            self::assertArrayNotHasKey($id, $seen, 'Duplicate non-UI capabilityId: ' . $id);
            $seen[$id] = true;
            self::assertNotSame('', \trim((string)$capability['module']), 'Missing non-UI module: ' . $id);
            self::assertMatchesRegularExpression(
                '/(?:phpunit|cli)/i',
                (string)$capability['validationLayer'],
                'Non-UI capability must use PHPUnit or CLI evidence: ' . $id,
            );
            self::assertIsArray($capability['businessCaseIds']);
            self::assertNotSame([], $capability['businessCaseIds'], 'Missing non-UI business cases: ' . $id);
            foreach ($capability['businessCaseIds'] as $caseId) {
                self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9-]+$/', (string)$caseId);
            }
            self::assertNotSame('', \trim((string)$capability['evidence']), 'Missing non-UI evidence: ' . $id);
            self::assertIsArray($capability['evidencePaths']);
            self::assertNotSame([], $capability['evidencePaths'], 'Missing non-UI evidence paths: ' . $id);
            $evidenceSources = [];
            foreach ($capability['evidencePaths'] as $evidencePath) {
                $absolute = BP . \ltrim((string)$evidencePath, '/');
                self::assertFileExists($absolute, 'Missing non-UI evidence file: ' . $evidencePath);
                $evidenceSources[] = (string)\file_get_contents($absolute);
            }
            $joinedEvidence = \implode("\n", $evidenceSources);
            foreach ($capability['businessCaseIds'] as $caseId) {
                self::assertStringContainsString(
                    (string)$caseId,
                    $joinedEvidence,
                    'Non-UI case has no auditable test mapping: ' . $caseId,
                );
            }
        }
    }

    public function testOriginalPlanHasCompleteCurrentR43Traceability(): void
    {
        $manifest = self::manifest();
        $traceability = $manifest['originalPlanTraceability'] ?? null;
        self::assertIsArray($traceability, 'Missing original-plan traceability');
        self::assertSame('self-contained-manifest', $traceability['historicalEvidencePolicy'] ?? null);
        self::assertIsInt($traceability['expectedOriginalCaseCount'] ?? null);
        self::assertIsArray($traceability['groups'] ?? null);
        self::assertNotSame([], $traceability['groups']);

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string)($traceability['sourcePlanSha256'] ?? ''),
        );
        self::assertNotSame('', (string)($traceability['historyMigration'] ?? ''));

        $capabilityIds = [];
        $currentCaseIds = \array_fill_keys($manifest['globalCaseIds'], true);
        foreach ($manifest['capabilities'] as $capability) {
            $capabilityIds[(string)$capability['capabilityId']] = true;
            foreach ($capability['businessCaseIds'] as $caseId) {
                $currentCaseIds[(string)$caseId] = true;
            }
        }
        foreach ($manifest['nonUiCapabilities'] as $capability) {
            $capabilityIds[(string)$capability['capabilityId']] = true;
        }

        $ownedOriginalCases = [];
        $groupIds = [];
        foreach ($traceability['groups'] as $index => $group) {
            self::assertIsArray($group, 'Traceability group must be an object at index ' . $index);
            foreach (['groupId', 'validationLayer', 'originalCaseIds', 'r43CapabilityIds', 'r43CaseIds'] as $key) {
                self::assertArrayHasKey($key, $group, "Traceability group {$index} misses {$key}");
            }
            $groupId = (string)$group['groupId'];
            self::assertMatchesRegularExpression('/^R43-TRACE-[A-Z0-9-]+$/', $groupId);
            self::assertArrayNotHasKey($groupId, $groupIds, 'Duplicate traceability group: ' . $groupId);
            $groupIds[$groupId] = true;
            self::assertMatchesRegularExpression('/(?:webui|phpunit|cli)/', (string)$group['validationLayer']);
            self::assertIsArray($group['originalCaseIds']);
            self::assertNotSame([], $group['originalCaseIds'], 'Traceability group owns no original cases: ' . $groupId);
            self::assertIsArray($group['r43CapabilityIds']);
            self::assertIsArray($group['r43CaseIds']);
            self::assertNotSame([], $group['r43CaseIds'], 'Traceability group has no current R4.3 cases: ' . $groupId);
            foreach ($group['originalCaseIds'] as $caseId) {
                self::assertMatchesRegularExpression('/^TEST-[A-Z0-9-]+$/', (string)$caseId);
                self::assertArrayNotHasKey(
                    (string)$caseId,
                    $ownedOriginalCases,
                    'Original plan case has multiple owners: ' . $caseId,
                );
                $ownedOriginalCases[(string)$caseId] = $groupId;
            }
            foreach ($group['r43CapabilityIds'] as $capabilityId) {
                self::assertArrayHasKey(
                    (string)$capabilityId,
                    $capabilityIds,
                    'Traceability group references unknown capability: ' . $capabilityId,
                );
            }
            foreach ($group['r43CaseIds'] as $caseId) {
                self::assertArrayHasKey(
                    (string)$caseId,
                    $currentCaseIds,
                    'Traceability group references unknown current case: ' . $caseId,
                );
            }
        }

        $ownedCaseIds = \array_keys($ownedOriginalCases);
        \sort($ownedCaseIds, SORT_STRING);
        self::assertCount($traceability['expectedOriginalCaseCount'], $ownedCaseIds);
    }

    public function testEveryOriginalPlanCaseHasCurrentExecutableEvidenceDeclaration(): void
    {
        $traceability = self::manifest()['originalPlanTraceability'] ?? null;
        self::assertIsArray($traceability);
        $evidence = self::currentOriginalEvidenceMap($traceability);
        $originalCaseIds = [];
        foreach ($traceability['groups'] as $group) {
            foreach ($group['originalCaseIds'] as $caseId) {
                $originalCaseIds[] = (string)$caseId;
            }
        }
        $originalCaseIds = \array_values(\array_unique($originalCaseIds));
        \sort($originalCaseIds, SORT_STRING);
        self::assertCount($traceability['expectedOriginalCaseCount'], $originalCaseIds);

        $missing = [];
        foreach ($originalCaseIds as $caseId) {
            if (($evidence[$caseId] ?? []) === []) {
                $missing[] = $caseId;
            }
        }
        self::assertSame([], $missing, 'Original cases without current executable evidence: ' . \implode(', ', $missing));
    }

    public function testEveryMappedBusinessCaseExistsInAPlaywrightSpec(): void
    {
        $occurrences = [];
        foreach (self::r43SpecFiles() as $source) {
            \preg_match_all('/["\'](CK-R43-[A-Z0-9-]+)["\']/', $source, $literalMatches);
            \preg_match_all(
                '/\bid\s*:\s*["\'](CK-R43-[A-Z0-9-]+)["\']/',
                $source,
                $explicitMatches,
            );
            $literalCounts = \array_count_values($literalMatches[1] ?? []);
            $explicitCounts = \array_count_values($explicitMatches[1] ?? []);
            foreach ($literalCounts as $caseId => $literalCount) {
                // Explicit descriptors win so an ID repeated in an error message
                // or fixture payload is not mistaken for a second Playwright test.
                // Table-driven suites keep the ID as one literal array cell.
                $declarationCount = $explicitCounts[$caseId] ?? $literalCount;
                $occurrences[$caseId] = ($occurrences[$caseId] ?? 0) + $declarationCount;
            }
        }

        $manifest = self::manifest();
        $declared = \array_values($manifest['globalCaseIds']);
        foreach ($manifest['capabilities'] as $capability) {
            foreach ($capability['businessCaseIds'] as $caseId) {
                $declared[] = (string)$caseId;
                self::assertArrayHasKey(
                    (string)$caseId,
                    $occurrences,
                    'Mapped E2E case is not declared by a Playwright case: ' . $caseId,
                );
            }
        }
        foreach ($manifest['globalCaseIds'] as $caseId) {
            self::assertArrayHasKey(
                (string)$caseId,
                $occurrences,
                'Global E2E case is not declared by a Playwright case: ' . $caseId,
            );
        }

        $duplicates = \array_filter(
            $occurrences,
            static fn(int $count): bool => $count !== 1,
        );
        self::assertSame(
            [],
            $duplicates,
            'Every CK-R43 case ID must identify exactly one collected Playwright case',
        );
        $implemented = \array_keys($occurrences);
        $declared = \array_values(\array_unique($declared));
        \sort($implemented);
        \sort($declared);
        self::assertIsInt(
            $manifest['expectedR43CaseCount'] ?? null,
            'Manifest must freeze the planned R4.3 case count',
        );
        self::assertSame(
            $manifest['expectedR43CaseCount'],
            \count($implemented),
            'Implemented R4.3 count differs from the frozen plan count',
        );
        self::assertSame($declared, $implemented, 'Every implemented CK-R43 case must be declared in the manifest');
    }

    public function testR43SpecsCannotSkipOrUseBackendDeepLinksAsMenuProof(): void
    {
        $mutationCaseIds = [];
        foreach (self::manifest()['capabilities'] as $capability) {
            foreach ($capability['mutationCaseIds'] ?? [] as $caseId) {
                $mutationCaseIds[(string)$caseId] = true;
            }
        }

        foreach (self::r43SpecFiles() as $path => $source) {
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:test|moduleCase)\s*\.\s*(?:skip|fixme|fail)\b|\btest\s*\.\s*describe\s*\.\s*skip\b/',
                $source,
                'R4.3 case cannot be skipped or converted to an expected failure: ' . $path,
            );

            $normalizedPath = \str_replace('\\', '/', $path);
            $isBackend = \str_contains($normalizedPath, '/backend/');
            $isAclMatrix = \str_ends_with(
                $normalizedPath,
                '/Weline_Backend-commerce-r43-menu.spec.js',
            );
            if ($isBackend) {
                self::assertMatchesRegularExpression(
                    '/\bopenBackendMenuBySource\s*\(/',
                    $source,
                    'Backend R4.3 spec must navigate through a real menu: ' . $path,
                );
                self::assertMatchesRegularExpression(
                    '/\binstallBackendBrowserGuards\s*\(/',
                    $source,
                    'Backend R4.3 spec must capture page, console and HTTP failures: ' . $path,
                );
            }
            if (!$isAclMatrix) {
                self::assertDoesNotMatchRegularExpression(
                    '/\bgotoBackend\s*\(/',
                    $source,
                    'Direct backend route is reserved for the ACL rejection matrix: ' . $path,
                );
            }

            \preg_match_all('/["\'](CK-R43-[A-Z0-9-]+)["\']/', $source, $matches);
            $fileCaseIds = \array_values(\array_unique($matches[1] ?? []));
            $fileMutationCaseIds = \array_values(\array_filter(
                $fileCaseIds,
                static fn(string $caseId): bool => isset($mutationCaseIds[$caseId]),
            ));
            if ($fileMutationCaseIds === []) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/\bopenBackendMenuBySource\s*\(/',
                $source,
                'Mutation cases must begin from a real backend menu ('
                    . \implode(', ', $fileMutationCaseIds) . '): ' . $path,
            );
            self::assertMatchesRegularExpression(
                '/\binstallBackendBrowserGuards\s*\(/',
                $source,
                'Mutation cases must capture page, console and HTTP failures ('
                    . \implode(', ', $fileMutationCaseIds) . '): ' . $path,
            );
        }
    }

    public function testEveryR43FixtureIsLockedToAnIsolatedPostgresqlClone(): void
    {
        $fixtures = [];
        foreach (self::r43SpecFiles() as $specPath => $source) {
            \preg_match_all('/["\']([^"\']*fixture\.php)["\']/', $source, $matches);
            foreach ($matches[1] ?? [] as $fixtureName) {
                $fixturePath = \realpath(\dirname($specPath) . DIRECTORY_SEPARATOR . $fixtureName);
                self::assertNotFalse(
                    $fixturePath,
                    'R4.3 fixture referenced by a spec does not exist: ' . $fixtureName,
                );
                $fixtures[$fixturePath] = true;
            }
        }
        self::assertNotSame([], $fixtures, 'No R4.3 fixture was discovered');

        foreach (\array_keys($fixtures) as $fixturePath) {
            $source = (string)\file_get_contents($fixturePath);
            self::assertStringContainsString(
                'WELINE_E2E_ISOLATED_DB',
                $source,
                'Fixture has no explicit isolated-run gate: ' . $fixturePath,
            );
            self::assertStringContainsString(
                'mig_clone_',
                $source,
                'Fixture does not reject a non-clone database: ' . $fixturePath,
            );
            self::assertMatchesRegularExpression(
                '/pgsql|postgres/i',
                $source,
                'Fixture does not require PostgreSQL: ' . $fixturePath,
            );
        }
    }

    public function testEveryManifestCapabilityMatchesOneMenuAndControllerAcl(): void
    {
        $menus = self::menus();
        foreach (self::manifest()['capabilities'] as $capability) {
            $id = (string)$capability['capabilityId'];
            $source = (string)$capability['sourceId'];
            self::assertArrayHasKey($source, $menus, 'Missing menu: ' . $id);
            self::assertCount(1, $menus[$source], 'Menu source is not unique: ' . $source);
            $menu = $menus[$source][0];
            self::assertSame($capability['parentSource'], $menu['parent'], 'Parent mismatch: ' . $id);
            self::assertSame($capability['title'], $menu['title'], 'Title mismatch: ' . $id);
            self::assertSame($capability['action'], $menu['action'], 'Action mismatch: ' . $id);

            $controller = BP . \ltrim((string)$capability['controller'], '/');
            self::assertFileExists($controller, 'Controller missing: ' . $id);
            $sourceCode = (string)\file_get_contents($controller);
            $quotedSource = \preg_quote($source, '/');
            self::assertMatchesRegularExpression(
                '/Acl(?:Attribute)?\s*\(\s*["\']' . $quotedSource . '["\']/',
                $sourceCode,
                'Controller has no matching ACL declaration: ' . $id,
            );
        }
    }

    public function testEveryManifestParentExistsAndRetiredDuplicateGroupsStayAbsent(): void
    {
        $menus = self::menus();
        foreach (self::manifest()['capabilities'] as $capability) {
            self::assertArrayHasKey(
                (string)$capability['parentSource'],
                $menus,
                'Unknown parent source: ' . $capability['capabilityId'],
            );
        }

        foreach ([
            'Weline_Backend::commerce:trade:group',
            'Weline_Backend::commerce:fulfillment:group',
            'Weline_Backend::commerce:finance:group',
            'Weline_Checkout::order_manage',
            'Weline_Checkout::order_list',
            'Weline_Checkout::order_view',
        ] as $retired) {
            self::assertArrayNotHasKey($retired, $menus, 'Retired duplicate menu is still registered: ' . $retired);
        }

        foreach ([
            'Weline_Backend::order_group',
            'Weline_Backend::customer_group',
            'Weline_Backend::marketing_group',
            'Weline_Backend::shipping_group',
            'Weline_Backend::payment_group',
            'Weline_Backend::currency_group',
            'Weline_Backend::commerce:catalog:group',
            'Weline_Backend::commerce:inventory:group',
            'Weline_Backend::commerce:tax-search:group',
            'Weline_Backend::commerce:partner:group',
            'Weline_Backend::commerce:operations:group',
        ] as $requiredParent) {
            self::assertArrayHasKey($requiredParent, $menus, 'Required menu group missing: ' . $requiredParent);
        }
    }

    public function testEveryLeafInAnOwnedCommerceModuleIsDeclaredInManifest(): void
    {
        $manifestSources = [];
        $modules = [];
        foreach (self::manifest()['capabilities'] as $capability) {
            $manifestSources[(string)$capability['sourceId']] = true;
            $module = (string)$capability['module'];
            if ($module !== 'Weline_Backend') {
                $modules[$module] = true;
            }
        }

        foreach (self::menus() as $source => $rows) {
            foreach ($rows as $row) {
                if ($row['action'] === '') {
                    continue;
                }
                if (!\preg_match('#/app/code/Weline/([^/]+)/etc/backend/menu\.xml$#', $row['file'], $match)) {
                    continue;
                }
                $module = 'Weline_' . $match[1];
                if (!isset($modules[$module])) {
                    continue;
                }
                self::assertArrayHasKey(
                    $source,
                    $manifestSources,
                    'Commerce module menu leaf is absent from the shared manifest: ' . $source,
                );
            }
        }
    }
}
