<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;

interface ImageGenerationProviderInterface
{
    /**
     * @return array{
     *     images: array<int, array{url?:string,b64_json?:string,mime_type?:string,revised_prompt?:string}>,
     *     usage?: array<string,int|float>,
     *     model?: string,
     *     finish_reason?: string,
     *     raw?: mixed
     * }
     */
    public function generateImage(AiModel $model, string $prompt, array $params = []): array;
}
