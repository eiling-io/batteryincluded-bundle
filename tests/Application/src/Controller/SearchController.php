<?php

namespace App\Controller;

use Batteryincluded\BatteryincludedBundle\Http\Attribute\MapSearchStruct;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(private BrowseService $browseService)
    {
    }

    // the bundle fills the search struct from the query string, e.g. /search?q=laptop&f[type][]=PRODUCT&sort=_PRODUCT.price:asc&page=2
    #[Route('/search')]
    public function index(#[MapSearchStruct(perPage: 12, sortings: ['', '_PRODUCT.price:asc', '_PRODUCT.price:desc'])] BrowseSearchStruct $searchStruct): Response
    {
        return $this->render('search/index.html.twig', [
            'searchStruct' => $searchStruct,
            'result' => $this->browseService->browse($searchStruct),
        ]);
    }
}
