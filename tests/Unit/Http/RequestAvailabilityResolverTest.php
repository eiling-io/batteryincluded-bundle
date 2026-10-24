<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Http;

use Batteryincluded\BatteryincludedBundle\Http\RequestAvailabilityResolver;
use Batteryincluded\BatteryincludedBundle\Http\SearchStructValueResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class RequestAvailabilityResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function requests(): iterable
    {
        yield 'host' => ['https://shop.ch/', 'de', 'ch'];
        yield 'host is case insensitive' => ['https://SHOP.CH/', 'de', 'ch'];
        yield 'wildcard host' => ['https://fr.shop.at/', 'fr', 'at'];
        yield 'host wins over locale' => ['https://shop.ch/', 'de_AT', 'ch'];
        yield 'full locale' => ['https://shop.example/', 'de_AT', 'at'];
        yield 'locale with dash' => ['https://shop.example/', 'de-CH', 'ch'];
        yield 'language' => ['https://shop.example/', 'de_DE', 'de'];
        yield 'default' => ['https://shop.example/', 'fr', 'eu'];
    }

    #[DataProvider('requests')]
    public function testResolve(string $url, string $locale, ?string $expected): void
    {
        $request = Request::create($url);
        $request->setLocale($locale);

        $resolver = new RequestAvailabilityResolver(['shop.ch' => 'ch', '*.shop.at' => 'at'], ['de_CH' => 'ch', 'de-AT' => 'at', 'de' => 'de'], 'eu');

        self::assertSame($expected, $resolver->resolve($request));
    }

    public function testRequestAttributeWins(): void
    {
        $request = Request::create('https://shop.ch/');
        $request->attributes->set(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE, 'li');

        self::assertSame('li', (new RequestAvailabilityResolver(['shop.ch' => 'ch']))->resolve($request));
    }

    public function testWithoutConfiguration(): void
    {
        self::assertNull((new RequestAvailabilityResolver())->resolve(Request::create('https://shop.ch/')));
    }
}
