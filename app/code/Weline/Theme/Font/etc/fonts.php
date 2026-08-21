<?php

declare(strict_types=1);

/**
 * Extra fonts to warm during setup:upgrade (optional).
 *
 * Preferred registration: put files under `{Module}/view/fonts/` — Theme
 * discovers them automatically. Use this file only for absolute paths outside
 * that convention, or prefer event Weline_Theme_Font::warmup_collect.
 *
 * @return list<string|array{path:string,languages?:list<string>}>
 */
return [
];
