<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiAssistant;

class AiAssistantService
{
    public function __construct(private readonly AiAssistant $assistant) {}

    public function createAssistant(array $data): AiAssistant
    {
        $assistant = clone $this->assistant;
        $assistant->setData($data);
        $assistant->save();
        return $assistant;
    }

    public function getById(int $id): AiAssistant
    {
        $assistant = clone $this->assistant;
        $assistant->load($id);
        if (!$assistant->getId()) {
            throw new \RuntimeException("Assistant not found");
        }
        return $assistant;
    }
}

