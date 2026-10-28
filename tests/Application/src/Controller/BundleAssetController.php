<?php

namespace App\Controller;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the Stimulus controller of the bundle, the test application has no AssetMapper. A shop gets it with the
 * AssetMapper or Webpack Encore as `@batteryincluded/batteryincluded-bundle`.
 */
class BundleAssetController
{
    #[Route('/bundle-assets/{file}', requirements: ['file' => '[a-z_]+\.(js|css)'], methods: ['GET'])]
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = dirname((string) (new \ReflectionClass(BatteryincludedBundle::class))->getFileName(), 2) . '/assets/dist/' . $file;
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', str_ends_with($file, '.js') ? 'text/javascript' : 'text/css');

        return $response;
    }
}
