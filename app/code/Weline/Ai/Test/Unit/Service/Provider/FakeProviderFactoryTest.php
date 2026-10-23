<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\FakeProvider;
use Weline\Ai\Service\Provider\ProviderFactory;

if (!\defined('BP')) {
    \define('BP', \dirname(__DIR__, 8) . DIRECTORY_SEPARATOR);
    require_once BP . 'app/code/Weline/Framework/App/DirectoryList.php';
    \Weline\Framework\App\DirectoryList::defineBootstrapConstants(BP);
}


/**
 * T3 验收：ProviderFactory 按 vendor=fake 选中 FakeProvider。
 */
final class FakeProviderFactoryTest extends TestCase
{
    public function testVendorFakeMapsToFakeProvider(): void
    {
        $model = new AiModel();
        $model->setData(AiModel::schema_fields_MODEL_CODE, 'fake-translator');
        $model->setData(AiModel::schema_fields_SUPPLIER, 'fake');

        $provider = (new ProviderFactory())->getProvider($model);
        $this->assertInstanceOf(FakeProvider::class, $provider);
        $this->assertSame('fake', $provider->getProviderCode());
    }
}
