<?php

namespace App\Controller;

use BatteryIncludedSdk\Client\ApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(ApiClient $apiClient)
    {
    }

    #[Route('/search')]
    public function index()
    {
        die('test');
    }
}