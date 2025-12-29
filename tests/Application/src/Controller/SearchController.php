<?php

namespace App\Controller;

use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(private BrowseService $browseService)
    {
    }

    #[Route('/search')]
    public function index()
    {
        $searchStruct = new BrowseSearchStruct();
        $searchStruct->setQuery('iPhone');
        $result = $this->browseService->browse($searchStruct);
        return $this->render('search/index.html.twig', [
            'result' => $result,
        ]);
    }
}