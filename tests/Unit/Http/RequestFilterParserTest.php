<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Http;

use Batteryincluded\BatteryincludedBundle\Http\RequestFilterParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class RequestFilterParserTest extends TestCase
{
    public function testParsesValuesAndRanges(): void
    {
        $request = Request::create('/', 'GET', ['f' => [
            'type' => ['PRODUCT', '', 'PRODUCT', 'BLOG'],
            '_PRODUCT.price' => ['from' => '10', 'till' => ''],
            '_PRODUCT.rating' => ['from' => 'x', 'till' => 'y'],
            'empty' => [''],
            'scalar' => 'ignored',
            'nested' => [['a']],
        ]]);

        self::assertSame([
            'type' => ['PRODUCT', 'BLOG'],
            '_PRODUCT.price' => ['from' => '10', 'till' => (string) PHP_INT_MAX],
        ], (new RequestFilterParser())->parse($request));
    }

    public function testWithoutFilters(): void
    {
        self::assertSame([], (new RequestFilterParser())->parse(Request::create('/?q=a')));
    }
}
