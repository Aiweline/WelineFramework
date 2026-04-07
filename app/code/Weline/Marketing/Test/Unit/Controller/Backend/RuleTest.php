<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\LocalModelInterface;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

class RuleTest extends TestCase
{
    private Rule $ruleModel;
    private LocalDescription $localModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleModel = ObjectManager::getInstance(Rule::class);
        $this->localModel = ObjectManager::getInstance(LocalDescription::class);
        $this->ensureLocalDescriptionColumnsAvailable();
    }

    protected function tearDown(): void
    {
        if ($this->ruleModel->getId()) {
            $this->deleteTranslations([(int)$this->ruleModel->getId()]);
            $this->ruleModel->delete();
        }
        parent::tearDown();
    }

    public function testIndexCallsLoadLocalDescription(): void
    {
        $rule = $this->createRule('controller-rule');
        $ruleId = (int)$rule->getId();

        try {
            $this->saveTranslation($ruleId, 'zh_Hans_CN', 'controller-local-name');

            $query = ObjectManager::getInstance(Rule::class);
            $query->reset()
                ->loadLocalDescription('', LocalDescription::class)
                ->pagination()
                ->select()
                ->fetch();

            $found = false;
            foreach ($query->getItems() as $item) {
                if ((int)$item->getId() === $ruleId) {
                    $found = true;
                    $this->assertArrayHasKey('local_name', $item->getData());
                    $this->assertSame('controller-local-name', $item->getData('local_name'));
                    break;
                }
            }

            $this->assertTrue($found);
        } finally {
            $this->deleteTranslations([$ruleId]);
            $rule->reset()->load($ruleId)->delete();
        }
    }

    public function testTranslationDataPassedToView(): void
    {
        $rule = $this->createRule('view-rule');
        $ruleId = (int)$rule->getId();

        try {
            $this->saveTranslation($ruleId, 'zh_Hans_CN', 'view-local-name');

            $query = ObjectManager::getInstance(Rule::class);
            $query->reset()
                ->loadLocalDescription('', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->pagination()
                ->select()
                ->fetch();

            $rules = $query->getItems();
            $this->assertIsArray($rules);
            $this->assertNotEmpty($rules);

            foreach ($rules as $ruleItem) {
                $ruleData = $ruleItem->getData();
                $this->assertArrayHasKey('id', $ruleData);
                $this->assertArrayHasKey('name', $ruleData);
                $this->assertSame($ruleId, (int)$ruleData['id']);
                $this->assertSame('view-local-name', $ruleData['local_name'] ?? null);
            }
        } finally {
            $this->deleteTranslations([$ruleId]);
            $rule->reset()->load($ruleId)->delete();
        }
    }

    public function testSearchCompatibilityWithTranslation(): void
    {
        $rule = $this->createRule('Search Test Rule');
        $ruleId = (int)$rule->getId();

        try {
            $this->saveTranslation($ruleId, 'zh_Hans_CN', 'search-local-name');

            $query = ObjectManager::getInstance(Rule::class);
            $query->reset()
                ->where('main_table.name', '%Search Test%', 'like')
                ->loadLocalDescription('', LocalDescription::class)
                ->select()
                ->fetch();

            $found = false;
            foreach ($query->getItems() as $item) {
                if ((int)$item->getId() === $ruleId) {
                    $found = true;
                    $this->assertSame('search-local-name', $item->getData('local_name'));
                    break;
                }
            }

            $this->assertTrue($found);
        } finally {
            $this->deleteTranslations([$ruleId]);
            $rule->reset()->load($ruleId)->delete();
        }
    }

    public function testPaginationCompatibilityWithTranslation(): void
    {
        $ruleIds = [];

        try {
            for ($i = 1; $i <= 5; $i++) {
                $rule = ObjectManager::make(Rule::class);
                $rule->setData([
                    Rule::schema_fields_NAME => "pagination-rule-{$i}",
                    Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
                    Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE,
                ]);
                $rule->save();
                $ruleId = (int)$rule->getId();
                $ruleIds[] = $ruleId;

                $this->saveTranslation($ruleId, 'zh_Hans_CN', "pagination-local-{$i}");
            }

            $query = ObjectManager::getInstance(Rule::class);
            $query->reset()
                ->loadLocalDescription('', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleIds, 'IN')
                ->pagination(1, 2)
                ->select()
                ->fetch();

            $items = $query->getItems();
            $this->assertCount(2, $items);
            $this->assertNotNull($query->getPagination());

            foreach ($items as $item) {
                $this->assertArrayHasKey('local_name', $item->getData());
                $this->assertNotEmpty($item->getData('local_name'));
            }
        } finally {
            $this->deleteTranslations($ruleIds);
            foreach ($ruleIds as $ruleId) {
                ObjectManager::make(Rule::class)->reset()->load($ruleId)->delete();
            }
        }
    }

    public function testExceptionHandling(): void
    {
        $query = ObjectManager::getInstance(Rule::class);
        $query->reset()
            ->loadLocalDescription('', LocalDescription::class)
            ->where('main_table.' . Rule::schema_fields_ID, 999999)
            ->select()
            ->fetch();

        $items = $query->getItems();
        $this->assertIsArray($items);
        $this->assertEmpty($items);
    }

    private function createRule(string $name): Rule
    {
        $rule = ObjectManager::make(Rule::class);
        $rule->setData([
            Rule::schema_fields_NAME => $name,
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE,
        ]);
        $rule->save();
        return $rule;
    }

    private function saveTranslation(int $ruleId, string $locale, string $name): void
    {
        $translation = ObjectManager::make(LocalDescription::class);
        $translation->setData([
            LocalDescription::schema_fields_ID => $ruleId,
            LocalModelInterface::schema_fields_local_code => $locale,
            LocalDescription::schema_fields_NAME => $name,
        ]);
        $translation->save();
    }

    private function deleteTranslations(array $ruleIds): void
    {
        $ruleIds = array_values(array_filter(array_map('intval', $ruleIds)));
        if ($ruleIds === []) {
            return;
        }

        $query = ObjectManager::make(LocalDescription::class);
        $query->reset()
            ->where(LocalDescription::schema_fields_ID, $ruleIds, 'IN')
            ->select()
            ->fetch()
            ->walk(static function ($item): void {
                $item->delete();
            });
    }

    private function ensureLocalDescriptionColumnsAvailable(): void
    {
        try {
            $connector = $this->localModel->getConnection()->getConnector();
            $table = $this->localModel->getTable();
            if (!$connector->hasField($table, LocalDescription::schema_fields_NAME)
                || !$connector->hasField($table, LocalDescription::schema_fields_DESCRIPTION)) {
                $this->markTestSkipped('Marketing local description schema is missing name/description columns in the current database.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Unable to verify marketing local description schema in the current database.');
        }
    }
}
