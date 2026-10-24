<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures;

use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use Symfony\Component\HttpFoundation\JsonResponse;

class TestController
{
    public function __invoke(BrowseSearchStruct $searchStruct, BrowseService $chBrowseService): JsonResponse
    {
        $result = $chBrowseService->browse($searchStruct);

        return new JsonResponse([
            'query' => $searchStruct->getQuery(),
            'perPage' => $searchStruct->getPerPage(),
            'availability' => $searchStruct->getAvailability(),
            'found' => $result->getFound(),
        ]);
    }
}
