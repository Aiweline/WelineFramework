<?php
declare(strict_types=1);

namespace Weline\Api\Controller\Framework;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Response;

class WorkerSmoke extends FrontendController
{
    public function getIndex(): Response
    {
        if (Env::system('deploy', 'prod') !== 'dev') {
            return Response::text('Not found', 404);
        }

        return Response::html($this->html());
    }

    private function html(): string
    {
        $endpoint = Env::getFrontendQueryBinPath();
        return (string)$this->fetch('Weline_Api::templates/framework/worker-smoke.phtml', [
            'runtime' => [
                'env' => ['WELINE_ENV' => 'DEV', 'DEV' => true, 'PROD' => false],
                'area' => 'frontend',
                'modulesBaseUrl' => '/Weline/Frontend/view/statics/js/weline-api',
                'assetVersion' => 'dev-smoke',
                'deployVersion' => 'dev',
                'workerBuildId' => 'dev-smoke',
                'api' => [
                    'workerUrl' => '/Weline/Frontend/view/statics/js/weline-api-worker.js',
                    'endpoint' => $endpoint,
                    'queryBinUrl' => $endpoint,
                    'requestTimeoutMs' => 4000,
                ],
                'i18n' => ['dictionary' => []],
                'site' => ['module' => 'Weline_Api', 'i18n' => []],
            ],
        ]);
    }
}
