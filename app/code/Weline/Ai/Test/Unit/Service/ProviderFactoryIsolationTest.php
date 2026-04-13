<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\OpenAiProvider;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Framework\Manager\ObjectManager;

class ProviderFactoryIsolationTest extends TestCase
{
    public function testGetProviderReturnsFreshInstanceForTheSameModel(): void
    {
        $factory = new ProviderFactory();

        /** @var AiModel $model */
        $model = ObjectManager::make(AiModel::class);
        $model->setData(AiModel::schema_fields_MODEL_CODE, 'gpt-4o-mini');
        $model->setData(AiModel::schema_fields_SUPPLIER, 'openai');

        $first = $factory->getProvider($model);
        $second = $factory->getProvider($model);

        $this->assertInstanceOf(OpenAiProvider::class, $first);
        $this->assertInstanceOf(OpenAiProvider::class, $second);
        $this->assertNotSame($first, $second);
    }
}
