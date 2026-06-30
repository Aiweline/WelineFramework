<?php

declare(strict_types=1);

namespace Weline\Cart\Session;

use Weline\Framework\Session\Business\AbstractBusinessSession;

class CartSession extends AbstractBusinessSession
{
    protected const PREFIX = 'cart_';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        $items = $this->get('items');

        return \is_array($items) ? $items : [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function setItems(array $items): void
    {
        $this->set('items', $items);
    }

    public function clearCart(): void
    {
        $this->clear();
    }
}
