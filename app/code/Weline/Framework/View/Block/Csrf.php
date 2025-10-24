<?php

namespace Weline\Framework\View\Block;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Security\Token;
use Weline\Framework\Ui\FormKey;
use Weline\Framework\View\Block;

class Csrf extends Block
{
    public function __construct(protected Request $request, private FormKey $formKey, array $data = [])
    {
        parent::__construct($data);
    }
    public function getHtml(string $name): string
    {
        return $this->render();
    }
    public function render(string $name = 'csrf'): string
    {
        $token = Token::create($name,9,3600);
        return "<input type='hidden' name='$name' value='$token'/>";
    }
    public function __toString(): string
    {
        return $this->render();
    }
}
