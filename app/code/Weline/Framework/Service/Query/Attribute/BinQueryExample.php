<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class BinQueryExample
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly array $params = [],
        public readonly string $description = ''
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDescriptor(): array
    {
        $descriptor = [
            'params' => $this->params,
        ];
        if ($this->description !== '') {
            $descriptor['description'] = $this->description;
        }

        return $descriptor;
    }
}
