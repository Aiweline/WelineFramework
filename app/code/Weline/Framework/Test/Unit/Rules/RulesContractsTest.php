<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Rules;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\RuleInterface;
use Weline\Framework\Rules\RulesManager;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllRulesContractsPass().
 */
final class RulesContractsTest extends TestCase
{
    public function testAllRulesContractsPass(): void
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
            'RulesManagerCategoryProfile::testValidateExcludingSkipsFrontendCategoryFailures' => function () {
            $this->assert_RulesManagerCategoryProfile_testValidateExcludingSkipsFrontendCategoryFailures();
        },
            'RulesManagerCategoryProfile::testValidateAllStillFailsOnFrontendRule' => function () {
            $this->assert_RulesManagerCategoryProfile_testValidateAllStillFailsOnFrontendRule();
        },
            'RulesManagerCategoryProfile::testValidateCategoriesOnlyRunsIncluded' => function () {
            $this->assert_RulesManagerCategoryProfile_testValidateCategoriesOnlyRunsIncluded();
        },
        ];
    }

private function assert_RulesManagerCategoryProfile_testValidateExcludingSkipsFrontendCategoryFailures(): void
{
    $manager = $this->managerWithRules_RulesManagerCategoryProfile([$this->rule_RulesManagerCategoryProfile('frontend-fail', 'frontend', true), $this->rule_RulesManagerCategoryProfile('database-ok', 'database', false)]);
    $manager->validateExcluding(['frontend']);
    $this->addToAssertionCount(1);
}

private function assert_RulesManagerCategoryProfile_testValidateAllStillFailsOnFrontendRule(): void
{
    $manager = $this->managerWithRules_RulesManagerCategoryProfile([$this->rule_RulesManagerCategoryProfile('frontend-fail', 'frontend', true), $this->rule_RulesManagerCategoryProfile('database-ok', 'database', false)]);
    try {
    $manager->validateAll();
    self::fail('Expected exception was not thrown');
    } catch (Exception $__e) {
    self::assertInstanceOf(Exception::class, $__e);
    }
}

private function assert_RulesManagerCategoryProfile_testValidateCategoriesOnlyRunsIncluded(): void
{
    $manager = $this->managerWithRules_RulesManagerCategoryProfile([$this->rule_RulesManagerCategoryProfile('frontend-fail', 'frontend', true), $this->rule_RulesManagerCategoryProfile('database-ok', 'database', false)]);
    $manager->validateCategories(['database']);
    $this->addToAssertionCount(1);
}

/** @param list<RuleInterface> $rules */
private function managerWithRules_RulesManagerCategoryProfile(array $rules): RulesManager
{
    $printing = $this->createMock(Printing::class);
    $manager = new class($printing, $rules) extends RulesManager
    {
        /** @param list<RuleInterface> $seed */
        public function __construct(Printing $printing, private readonly array $seed)
        {
            // Skip discoverRules() — parent ctor scans disk.
            $ref = new \ReflectionClass(RulesManager::class);
            $prop = $ref->getProperty('printing');
            $prop->setAccessible(true);
            $prop->setValue($this, $printing);
            $rulesProp = $ref->getProperty('rules');
            $rulesProp->setAccessible(true);
            $indexed = [];
            foreach ($seed as $rule) {
                $indexed[$rule->getName()] = $rule;
            }
            $rulesProp->setValue($this, $indexed);
            $classes = $ref->getProperty('ruleClasses');
            $classes->setAccessible(true);
            $classes->setValue($this, []);
        }
    };
    return $manager;
}

private function rule_RulesManagerCategoryProfile(string $name, string $category, bool $fail): RuleInterface
{
    return new class($name, $category, $fail) implements RuleInterface
    {
        public function __construct(private readonly string $name, private readonly string $category, private readonly bool $fail)
        {
        }
        public function getName(): string
        {
            return $this->name;
        }
        public function getBrief(): string
        {
            return $this->name;
        }
        public function getDescription(): string
        {
            return $this->name;
        }
        public function getCategory(): string
        {
            return $this->category;
        }
        public function getPriority(): int
        {
            return 100;
        }
        public function validate(): void
        {
            if ($this->fail) {
                throw new Exception('fail:' . $this->name);
            }
        }
    };
}
}
