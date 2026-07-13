<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\TranslationCollector;

final class TranslationCollectorInterfaceFactory implements FactoryObjectInterface
{
    public function create(): TranslationCollectorInterface
    {
        return ObjectManager::getInstance(TranslationCollector::class);
    }
}
