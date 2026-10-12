<?php
declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_DeploySchemaDemo',
    __DIR__,
    '0.1.3',
    'SchemaDiff column backup/restore UC fixture (not a storefront module)'
);
