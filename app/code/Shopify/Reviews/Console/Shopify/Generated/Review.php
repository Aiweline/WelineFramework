<?php

namespace Shopify\Reviews\Console\Shopify\Generated;

use Weline\Framework\Console\CommandInterface;

class Review implements CommandInterface
{

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []):void
    {
        \Shopify\Reviews\Model\Review::generate($args);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '为Shopify生成Ai评论';
    }
}