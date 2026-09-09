<?php

declare(strict_types=1);

namespace Weline\Blog\Model;

use Weline\Blog\Service\BlogContentMutation;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;

/** Covers admin forms, imports and LocalModel translation workers through the model boundary. */
trait PublishesContentChanges
{
    public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        $identity = array_replace((array)$this->getData(), $data instanceof AbstractModel ? $data->getModelData() : (is_array($data) ? $data : []));
        return ObjectManager::getInstance(BlogContentMutation::class)->run(
            $this, static::CONTENT_RESOURCE_TYPE, $identity, fn(): bool|int => parent::save($data, $sequence),
        );
    }

    public function delete(): static
    {
        return ObjectManager::getInstance(BlogContentMutation::class)->run(
            $this, static::CONTENT_RESOURCE_TYPE, (array)$this->getData(), fn(): static => parent::delete(), true,
        );
    }
}
