<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Rules;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\RuleInterface;
use Weline\Framework\Rules\RulesManager;

final class RulesManagerCategoryProfileContractTest extends TestCase
{
    public function testValidateExcludingSkipsFrontendCategoryFailures(): void
    {
        $manager = $this->managerWithRules([
            $this->rule('frontend-fail', 'frontend', true),
            $this->rule('database-ok', 'database', false),
        ]);

        $manager->validateExcluding(['frontend']);
        $this->addToAssertionCount(1);
    }

    public function testValidateAllStillFailsOnFrontendRule(): void
    {
        $manager = $this->managerWithRules([
            $this->rule('frontend-fail', 'frontend', true),
            $this->rule('database-ok', 'database', false),
        ]);

        $this->expectException(Exception::class);
        $manager->validateAll();
    }

    public function testValidateCategoriesOnlyRunsIncluded(): void
    {
        $manager = $this->managerWithRules([
            $this->rule('frontend-fail', 'frontend', true),
            $this->rule('database-ok', 'database', false),
        ]);

        $manager->validateCategories(['database']);
        $this->addToAssertionCount(1);
    }

    /** @param list<RuleInterface> $rules */
    private function managerWithRules(array $rules): RulesManager
    {
        $printing = $this->createMock(Printing::class);
        $manager = new class ($printing, $rules) extends RulesManager {
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

    private function rule(string $name, string $category, bool $fail): RuleInterface
    {
        return new class ($name, $category, $fail) implements RuleInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $category,
                private readonly bool $fail,
            ) {
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
