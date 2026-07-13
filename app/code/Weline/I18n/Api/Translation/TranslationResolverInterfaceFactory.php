<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\TranslationResolver;

final class TranslationResolverInterfaceFactory implements FactoryObjectInterface
{
    public function create(): TranslationResolverInterface
    {
        return ObjectManager::getInstance(TranslationResolver::class);
    }
}
