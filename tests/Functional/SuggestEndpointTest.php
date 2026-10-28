<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Functional;

use Batteryincluded\BatteryincludedBundleTests\Fixtures\MockApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SuggestEndpointTest extends FunctionalTestCase
{
    public function testGroupsTheSuggestions(): void
    {
        $response = $this->request('https://shop.ch/batteryincluded/suggest?q=lap&locale=en');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('lap', $data['query']);
        self::assertSame(4, $data['found']);
        self::assertSame(['PRODUCT', 'CONTENT', 'BLOG', 'FAQ'], array_column($data['groups'], 'type'));
        self::assertSame('PRODUCT-1', $data['groups'][0]['items'][0]['id']);
        self::assertSame(999.0, $data['groups'][0]['items'][0]['price']);
        self::assertSame('<mark>lap</mark>top', $data['completions'][0]['html']);

        // locale of the query, availability of the host (availability.hosts), collection of the route
        parse_str((string) parse_url(MockApi::$requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertStringContainsString('/customer.test/documents/suggest', MockApi::$requests[0]['url']);
        self::assertSame('lap', $query['q']);
        self::assertSame(['locale' => 'en', 'availability' => 'ch'], $query['v']);
    }

    public function testUsesTheLocaleOfTheRequest(): void
    {
        $this->request('https://shop.test/batteryincluded/suggest?q=lap&locale=../../x');

        parse_str((string) parse_url(MockApi::$requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame(['locale' => 'en'], $query['v']);
    }

    public function testShortQueriesAreNotSent(): void
    {
        $data = json_decode((string) $this->request('https://shop.test/batteryincluded/suggest?q=l')->getContent(), true);

        self::assertSame([], $data['groups']);
        self::assertSame([], MockApi::$requests);
    }

    public function testHidesTheErrorFromTheVisitor(): void
    {
        $response = $this->request('https://shop.test/batteryincluded/suggest?q=error');

        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertSame('{"error":"Suggestions are not available."}', $response->getContent());
    }

    public function testConfiguresTheSuggester(): void
    {
        $data = json_decode((string) $this->request('https://shop.test/batteryincluded/suggest?q=lap', ['suggest' => ['limits' => ['faq' => 0, 'product' => 1], 'order' => ['blog'], 'completions' => 0]])->getContent(), true);

        self::assertSame(['BLOG', 'CONTENT', 'PRODUCT'], array_column($data['groups'], 'type'));
        self::assertSame([], $data['completions']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function request(string $uri, array $config = []): Response
    {
        $this->kernel?->shutdown();

        return $this->bootKernel($config)->handle(Request::create($uri));
    }
}
