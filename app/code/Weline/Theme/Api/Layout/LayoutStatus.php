<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

enum LayoutStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
}
