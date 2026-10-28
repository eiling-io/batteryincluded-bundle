<?php

namespace App\Document;

use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlResolverInterface;
use Batteryincluded\BatteryincludedBundle\Demo\DemoShop;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Links the documents of the suggest endpoint and of bi_document_url() to the detail page of the demo shop,
 * a shop links its product and blog routes here.
 */
class DemoDocumentUrlResolver implements DocumentUrlResolverInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function resolve(string $id, string $type, array $data): ?string
    {
        return $this->urlGenerator->generate(DemoShop::ROUTE_DETAIL, ['id' => $id]);
    }
}
