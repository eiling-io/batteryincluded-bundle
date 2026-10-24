<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Http;

use Batteryincluded\BatteryincludedBundle\Http\Attribute\MapSearchStruct;
use Batteryincluded\BatteryincludedBundle\Http\RequestAvailabilityResolver;
use Batteryincluded\BatteryincludedBundle\Http\RequestFilterParser;
use Batteryincluded\BatteryincludedBundle\Http\SearchStructValueResolver;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Suggest\SuggestSearchStruct;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

class SearchStructValueResolverTest extends TestCase
{
    private SearchStructValueResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SearchStructValueResolver(new RequestFilterParser(), new RequestAvailabilityResolver(locales: ['de_CH' => 'ch']), 24, 100);
    }

    public function testFillsBrowseSearchStruct(): void
    {
        $request = Request::create('/search', 'GET', ['q' => ' laptop ', 'f' => ['type' => ['PRODUCT']], 'sort' => '_PRODUCT.price:asc', 'page' => '3', 'per_page' => '12']);
        $request->setLocale('de_CH');

        $searchStruct = $this->resolveBrowse($request);

        self::assertSame('laptop', $searchStruct->getQuery());
        self::assertSame(['type' => ['PRODUCT']], $searchStruct->getFilters());
        self::assertSame('_PRODUCT.price:asc', $searchStruct->getSort());
        self::assertSame(3, $searchStruct->getPage());
        self::assertSame(12, $searchStruct->getPerPage());
        self::assertSame('de_CH', $searchStruct->getLocale());
        self::assertSame('ch', $searchStruct->getAvailability());
    }

    public function testDefaultsAndLimits(): void
    {
        self::assertSame(24, $this->resolveBrowse(Request::create('/search'))->getPerPage());
        self::assertSame(100, $this->resolveBrowse(Request::create('/search?per_page=5000'))->getPerPage());
        self::assertSame(1, $this->resolveBrowse(Request::create('/search?page=-4'))->getPage());
    }

    public function testIgnoresInvalidValues(): void
    {
        $searchStruct = $this->resolveBrowse(Request::create('/search?per_page=abc&page[]=2&sort=evil'), new MapSearchStruct(sortings: ['', 'price']));

        self::assertSame(24, $searchStruct->getPerPage());
        self::assertSame(1, $searchStruct->getPage());
        self::assertSame('', $searchStruct->getSort());
    }

    public function testAttributeOptions(): void
    {
        $request = Request::create('/search?per_page=50');
        $request->setLocale('de_CH');

        $searchStruct = $this->resolveBrowse($request, new MapSearchStruct(perPage: 12, maxPerPage: 30, locale: 'en', availability: 'at', presetId: 'sale'));

        self::assertSame(30, $searchStruct->getPerPage());
        self::assertSame('en', $searchStruct->getLocale());
        self::assertSame('at', $searchStruct->getAvailability());
        self::assertSame('sale', $searchStruct->getPresetId());
        self::assertSame(12, $this->resolveBrowse(Request::create('/search'), new MapSearchStruct(perPage: 12))->getPerPage());
    }

    public function testFillsSuggestSearchStruct(): void
    {
        $request = Request::create('/suggest', 'GET', ['q' => 'lap', 'f' => ['type' => ['PRODUCT']]]);
        $request->setLocale('en');

        $values = [...$this->resolver->resolve($request, new ArgumentMetadata('searchStruct', SuggestSearchStruct::class, false, false, null))];

        self::assertCount(1, $values);
        self::assertInstanceOf(SuggestSearchStruct::class, $values[0]);
        self::assertSame('lap', $values[0]->getQuery());
        self::assertSame(['type' => ['PRODUCT']], $values[0]->getFilters());
        self::assertSame('en', $values[0]->getLocale());
        self::assertNull($values[0]->getAvailability());
    }

    public function testIgnoresOtherArguments(): void
    {
        self::assertSame([], [...$this->resolver->resolve(Request::create('/'), new ArgumentMetadata('id', 'string', false, false, null))]);
    }

    private function resolveBrowse(Request $request, ?MapSearchStruct $attribute = null): BrowseSearchStruct
    {
        $argument = new ArgumentMetadata('searchStruct', BrowseSearchStruct::class, false, false, null, attributes: $attribute !== null ? [$attribute] : []);
        $values = [...$this->resolver->resolve($request, $argument)];
        self::assertCount(1, $values);
        self::assertInstanceOf(BrowseSearchStruct::class, $values[0]);

        return $values[0];
    }
}
