<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary;

final class DictionaryRepositoryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): DictionaryRepositoryInterface
    {
        return ObjectManager::getInstance(Dictionary::class);
    }
}
