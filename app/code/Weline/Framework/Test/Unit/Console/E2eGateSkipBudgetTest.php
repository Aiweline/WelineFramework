<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Test\Console\E2e\Run;
use Weline\Framework\Test\Service\E2eGateSkipBudget;

/**
 * 门禁跳过预算契约：JSON 报告里的 skip 必须按 case id 对账。
 */
final class E2eGateSkipBudgetTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline-gate-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        \mkdir($this->tmpDir . \DIRECTORY_SEPARATOR . 'test-results', 0775, true);
        \putenv('WELINE_E2E_GATE_JSON_FILE=');
    }

    protected function tearDown(): void
    {
        @\unlink($this->tmpDir . \DIRECTORY_SEPARATOR . 'test-results' . \DIRECTORY_SEPARATOR . 'gate-summary.json');
        @\rmdir($this->tmpDir . \DIRECTORY_SEPARATOR . 'test-results');
        @\rmdir($this->tmpDir);
        \putenv('WELINE_E2E_GATE_JSON_FILE');
    }

    public function testCollectSkipsWalksNestedSuites(): void
    {
        $report = [
            'suites' => [
                [
                    'title' => 'outer',
                    'specs' => [
                        [
                            'title' => '[module:Weline_Checkout] [case:CASE-SKIP-001] skipped one',
                            'tests' => [
                                [
                                    'status' => 'skipped',
                                    'expectedStatus' => 'skipped',
                                    'annotations' => [
                                        ['type' => 'skip', 'description' => 'theme missing CTA'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'title' => '[module:Weline_Checkout] [case:CASE-PASS-002] passed one',
                            'tests' => [
                                ['status' => 'expected', 'expectedStatus' => 'passed', 'annotations' => []],
                            ],
                        ],
                    ],
                    'suites' => [
                        [
                            'title' => 'inner',
                            'specs' => [
                                [
                                    'title' => '[case:CASE-SKIP-003] nested skip',
                                    'tests' => [
                                        ['status' => 'skipped', 'expectedStatus' => 'skipped', 'annotations' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $skips = E2eGateSkipBudget::collectSkips($report);
        self::assertCount(2, $skips);
        self::assertSame('CASE-SKIP-001', $skips[0]['case']);
        self::assertSame('theme missing CTA', $skips[0]['reason']);
        self::assertSame('CASE-SKIP-003', $skips[1]['case']);
        self::assertSame('(未写原因)', $skips[1]['reason']);
    }

    public function testPartitionSeparatesDeclaredAndUndeclared(): void
    {
        $skips = [
            ['case' => 'DECLARED-1', 'title' => 't1', 'reason' => 'r1'],
            ['case' => 'NEW-SKIP-2', 'title' => 't2', 'reason' => 'r2'],
            ['case' => '', 'title' => 'untagged', 'reason' => 'r3'],
        ];
        $partition = E2eGateSkipBudget::partition($skips, ['DECLARED-1' => 'known']);

        self::assertCount(1, $partition['declared']);
        self::assertSame('DECLARED-1', $partition['declared'][0]['case']);
        self::assertCount(2, $partition['undeclared']);
    }

    public function testAllowedFromManifestAcceptsStringsAndObjects(): void
    {
        $allowed = E2eGateSkipBudget::allowedFromManifest([
            'allowed_skips' => [
                'STRING-CASE',
                ['case' => 'OBJECT-CASE', 'reason' => 'because'],
                ['id' => 'ID-CASE'],
                '',
                ['reason' => 'missing case'],
            ],
        ]);

        self::assertSame(
            ['STRING-CASE' => '', 'OBJECT-CASE' => 'because', 'ID-CASE' => ''],
            $allowed,
        );
    }

    public function testExtractCaseIdReturnsEmptyForUntaggedTitle(): void
    {
        self::assertSame('', E2eGateSkipBudget::extractCaseId('no tag here'));
        self::assertSame('ABC-1', E2eGateSkipBudget::extractCaseId('[case:ABC-1] title'));
    }

    public function testRunnerFailsClosedWhenJsonReportIsMissing(): void
    {
        self::assertSame(1, $this->runEnforceBudget([], 0));
    }

    public function testRunnerFailsClosedWhenJsonReportIsUnparsable(): void
    {
        $this->writeReport('{not json');
        self::assertSame(1, $this->runEnforceBudget([], 0));
    }

    public function testRunnerPassesWhenNoCaseSkipped(): void
    {
        $this->writeReport(\json_encode([
            'suites' => [[
                'specs' => [[
                    'title' => '[case:PASS-001] passed',
                    'tests' => [['status' => 'expected', 'expectedStatus' => 'passed', 'annotations' => []]],
                ]],
            ]],
        ], \JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->runEnforceBudget([], 0));
    }

    public function testRunnerPassesDeclaredSkipAndFailsUndeclaredSkip(): void
    {
        $report = \json_encode([
            'suites' => [[
                'specs' => [
                    [
                        'title' => '[case:DECLARED-001] skipped',
                        'tests' => [[
                            'status' => 'skipped',
                            'expectedStatus' => 'skipped',
                            'annotations' => [['type' => 'skip', 'description' => 'known']],
                        ]],
                    ],
                    [
                        'title' => '[case:UNDECLARED-002] skipped',
                        'tests' => [[
                            'status' => 'skipped',
                            'expectedStatus' => 'skipped',
                            'annotations' => [['type' => 'skip', 'description' => 'new silent skip']],
                        ]],
                    ],
                ],
            ]],
        ], \JSON_UNESCAPED_UNICODE);
        $this->writeReport($report);

        self::assertSame(1, $this->runEnforceBudget([], 0));
        self::assertSame(0, $this->runEnforceBudget(['DECLARED-001' => 'known', 'UNDECLARED-002' => 'known'], 0));
    }

    private function writeReport(string $json): void
    {
        $path = $this->tmpDir . \DIRECTORY_SEPARATOR . 'test-results' . \DIRECTORY_SEPARATOR . 'gate-summary.json';
        self::assertNotFalse(\file_put_contents($path, $json));
    }

    /**
     * @param array<string, string> $allowed
     */
    private function runEnforceBudget(array $allowed, int $exitCode): int
    {
        $run = (new \ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $printerProperty = new \ReflectionProperty(Run::class, 'printer');
        $printerProperty->setAccessible(true);
        $printerProperty->setValue($run, $this->createMock(Printing::class));

        $method = new \ReflectionMethod(Run::class, 'enforceSkipBudget');
        $method->setAccessible(true);

        return (int)$method->invoke($run, $this->tmpDir, $allowed, $exitCode);
    }
}
