<?php

declare(strict_types=1);

namespace Weline\Framework\Manager;

enum ServiceScope: string
{
    case PROCESS = 'process';
    case REQUEST = 'request';
    case FIBER = 'fiber';
    case PROTOTYPE = 'prototype';
}
