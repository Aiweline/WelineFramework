<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Model\Rule;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\LocalModel;
use Weline\I18n\LocalModelInterface;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

class LocalDescriptionTest extends TestCase
{
    private LocalDescription $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(LocalDescription::class);
    }

    protected function tearDown(): void
    {
        if ($this->model->getId()) {
            $this->model->delete();
        }
        parent::tearDown();
    }

    public function testModelInstantiation(): void
    {
        $this->assertInstanceOf(LocalDescription::class, $this->model);
        $this->assertInstanceOf(LocalModel::class, $this->model);
        $this->assertInstanceOf(LocalModelInterface::class, $this->model);
    }

    public function testFieldConstants(): void
    {
        $this->assertSame('id', LocalDescription::schema_fields_ID);
        $this->assertSame(Rule::schema_fields_ID, LocalDescription::schema_fields_ID);
        $this->assertSame('name', LocalDescription::schema_fields_NAME);
        $this->assertSame('description', LocalDescription::schema_fields_DESCRIPTION);
        $this->assertSame('local_code', LocalModelInterface::schema_fields_local_code);
    }

    public function testTableAndIndexerConstants(): void
    {
        $this->assertSame('weline_marketing_rule_local_description', LocalDescription::schema_table);
        $this->assertSame('marketing_rule_local_description', LocalDescription::indexer);
    }

    public function testSetAndGetData(): void
    {
        $this->model->setData([
            LocalDescription::schema_fields_ID => 1,
            LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
            LocalDescription::schema_fields_NAME => 'rule-name',
            LocalDescription::schema_fields_DESCRIPTION => 'rule-description',
        ]);

        $this->assertSame(1, $this->model->getData(LocalDescription::schema_fields_ID));
        $this->assertSame('zh_Hans_CN', $this->model->getData(LocalModelInterface::schema_fields_local_code));
        $this->assertSame('rule-name', $this->model->getData(LocalDescription::schema_fields_NAME));
        $this->assertSame('rule-description', $this->model->getData(LocalDescription::schema_fields_DESCRIPTION));
    }

    public function testSaveAndLoadTranslation(): void
    {
        $rule = $this->createRule('Test Rule', 'Test Description');
        $ruleId = (int)$rule->getId();

        try {
            $zhTranslation = ObjectManager::getInstance(LocalDescription::class);
            $zhTranslation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => 'zh-rule',
                LocalDescription::schema_fields_DESCRIPTION => 'zh-description',
            ]);
            $zhTranslation->save();

            $enTranslation = ObjectManager::getInstance(LocalDescription::class);
            $enTranslation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'en_US',
                LocalDescription::schema_fields_NAME => 'en-rule',
                LocalDescription::schema_fields_DESCRIPTION => 'en-description',
            ]);
            $enTranslation->save();

            $zhLoaded = ObjectManager::getInstance(LocalDescription::class);
            $zhLoaded->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertSame('zh-rule', $zhLoaded->getData(LocalDescription::schema_fields_NAME));
            $this->assertSame('zh-description', $zhLoaded->getData(LocalDescription::schema_fields_DESCRIPTION));

            $enLoaded = ObjectManager::getInstance(LocalDescription::class);
            $enLoaded->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'en_US')
                ->find()
                ->fetch();

            $this->assertSame('en-rule', $enLoaded->getData(LocalDescription::schema_fields_NAME));
            $this->assertSame('en-description', $enLoaded->getData(LocalDescription::schema_fields_DESCRIPTION));
        } finally {
            $this->deleteTranslations($ruleId);
            $rule->reset()->load($ruleId)->delete();
        }
    }

    public function testMainTableIdField(): void
    {
        $this->assertSame(Rule::schema_fields_ID, LocalDescription::schema_fields_ID);
        $this->assertSame('id', LocalDescription::schema_fields_ID);
    }

    public function testCompositePrimaryKey(): void
    {
        $rule = $this->createRule('Composite Key Test');
        $ruleId = (int)$rule->getId();

        try {
            $translation1 = ObjectManager::getInstance(LocalDescription::class);
            $translation1->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => 'zh-composite',
            ]);
            $translation1->save();

            $translation2 = ObjectManager::getInstance(LocalDescription::class);
            $translation2->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'en_US',
                LocalDescription::schema_fields_NAME => 'en-composite',
            ]);
            $translation2->save();

            $loaded = ObjectManager::getInstance(LocalDescription::class);
            $loaded->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertNotEmpty($translation1->getId());
            $this->assertNotEmpty($translation2->getId());
            $this->assertSame('zh-composite', $loaded->getData(LocalDescription::schema_fields_NAME));
        } finally {
            $this->deleteTranslations($ruleId);
            $rule->reset()->load($ruleId)->delete();
        }
    }

    public function testUpdateTranslation(): void
    {
        $rule = $this->createRule('Update Test');
        $ruleId = (int)$rule->getId();

        try {
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => 'before-name',
                LocalDescription::schema_fields_DESCRIPTION => 'before-description',
            ]);
            $translation->save();

            $translation->setData(LocalDescription::schema_fields_NAME, 'after-name');
            $translation->setData(LocalDescription::schema_fields_DESCRIPTION, 'after-description');
            $translation->save();

            $updated = ObjectManager::getInstance(LocalDescription::class);
            $updated->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertSame('after-name', $updated->getData(LocalDescription::schema_fields_NAME));
            $this->assertSame('after-description', $updated->getData(LocalDescription::schema_fields_DESCRIPTION));
        } finally {
            $this->deleteTranslations($ruleId);
            $rule->reset()->load($ruleId)->delete();
        }
    }

    public function testDeleteTranslation(): void
    {
        $rule = $this->createRule('Delete Test');
        $ruleId = (int)$rule->getId();

        try {
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => 'to-delete',
            ]);
            $translation->save();

            $translation->delete();

            $deleted = ObjectManager::getInstance(LocalDescription::class);
            $deleted->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertEmpty($deleted->getId());
            $this->assertEmpty($deleted->getData());
        } finally {
            $this->deleteTranslations($ruleId);
            $rule->reset()->load($ruleId)->delete();
        }
    }

    private function createRule(string $name, string $description = ''): Rule
    {
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->setData([
            Rule::schema_fields_NAME => $name,
            Rule::schema_fields_DESCRIPTION => $description,
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE,
        ]);
        $rule->save();
        return $rule;
    }

    private function deleteTranslations(int $ruleId): void
    {
        if ($ruleId <= 0) {
            return;
        }

        ObjectManager::getInstance(LocalDescription::class)
            ->reset()
            ->where(LocalDescription::schema_fields_ID, $ruleId)
            ->delete();
    }
}
