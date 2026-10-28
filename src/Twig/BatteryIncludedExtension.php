<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Twig;

use Batteryincluded\BatteryincludedBundle\Document\Document;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Search results, recommendations and highlights in templates, e.g. "similar products" on a detail page.
 * The functions are evaluated lazily by the BatteryIncludedRuntime.
 */
class BatteryIncludedExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('bi_search', [BatteryIncludedRuntime::class, 'search']),
            new TwigFunction('bi_recommendations', [BatteryIncludedRuntime::class, 'recommendations']),
            new TwigFunction('bi_highlights', [BatteryIncludedRuntime::class, 'highlights']),
            new TwigFunction('bi_document_url', [BatteryIncludedRuntime::class, 'documentUrl']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('bi_highlight', [Document::class, 'highlight'], ['is_safe' => ['html']]),
        ];
    }
}
