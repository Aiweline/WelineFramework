<?php

declare(strict_types=1);

namespace Weline\Benchmark\Controller;

use Weline\Benchmark\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Model\Migration;

class Db extends FrontendController
{
    public function index(): Response
    {
        try {
            /** @var Migration $migration */
            $migration = ObjectManager::getInstance(Migration::class, [], false);
            $migration->clearQuery()->load(1);
            $id = (int)$migration->getId(0);
        } catch (\Throwable) {
            return Response::json([
                'ok' => false,
                'status' => 'db_unavailable',
            ], 503);
        }

        return Response::json([
            'ok' => $id > 0,
            'id' => $id,
            'status' => $id > 0 ? 'found' : 'missing',
        ]);
    }
}
