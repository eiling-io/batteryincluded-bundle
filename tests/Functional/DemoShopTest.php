<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Functional;

use Batteryincluded\BatteryincludedBundleTests\Fixtures\MockApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoShopTest extends FunctionalTestCase
{
    public function testRendersTheSearchPage(): void
    {
        $response = $this->request('/demo/?q=laptop&lang=en&country=ch');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('Laptop', (string) $response->getContent());
        self::assertStringContainsString('href="/demo/detail/PRODUCT-1', (string) $response->getContent());

        parse_str((string) parse_url(MockApi::$requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('laptop', $query['q']);
        self::assertSame(['locale' => 'en', 'availability' => 'ch'], array_intersect_key($query['v'], ['locale' => 1, 'availability' => 1]));
    }

    public function testShowsTheFacetsOfTheLanguage(): void
    {
        $content = (string) $this->request('/demo/?q=laptop&lang=en')->getContent();

        // translated category tree, the untranslated categories and the facets of other languages are hidden
        self::assertStringContainsString('Notebooks', $content);
        self::assertStringNotContainsString('Rechner', $content);
        self::assertStringContainsString('Colour', $content);
        self::assertStringNotContainsString('Farbe', $content);
    }

    public function testRendersAContentPage(): void
    {
        $content = (string) $this->request('/demo/detail/CONTENT-4')->getContent();

        self::assertStringContainsString('<h1 class="h3 mt-1">Warranty</h1>', $content);
        self::assertStringContainsString('<p class="lead">2 years</p>', $content);
        self::assertStringContainsString('<p>First paragraph.</p>', $content);
        self::assertStringContainsString('<p>Second paragraph.</p>', $content);
    }

    public function testRendersABlogPost(): void
    {
        $content = (string) $this->request('/demo/detail/BLOG-3')->getContent();

        self::assertStringContainsString('The best laptops', $content);
        self::assertStringContainsString('Jane', $content);
    }

    public function testGroupsTheSuggestionsByType(): void
    {
        $response = $this->request('/demo/api/suggest?q=lap&lang=en');
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $data['found']);
        self::assertSame('laptop', $data['completions'][0]['value']);
        // products first, then content pages, blog posts and other types
        self::assertSame(['PRODUCT', 'CONTENT', 'BLOG', 'FAQ'], array_column($data['groups'], 'type'));
        self::assertSame(['Products', 'Content pages', 'Blog posts', 'FAQ'], array_column($data['groups'], 'label'));

        [$products, $contents, $blogs, $faqs] = array_column($data['groups'], 'items');
        self::assertSame('<mark>Lap</mark>top', $products[0]['html']);
        self::assertSame('LT-1', $products[0]['text']);
        self::assertStringStartsWith('/demo/detail/PRODUCT-1?', $products[0]['url']);
        self::assertStringStartsWith('/demo/detail/CONTENT-4?', $contents[0]['url']);
        self::assertSame('bi-file-earmark-text', $contents[0]['icon']);
        self::assertStringStartsWith('/demo/detail/FAQ-7?', $faqs[0]['url']);

        // without the id the title is searched within the type
        parse_str((string) parse_url($blogs[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('The best laptops', $query['q']);
        self::assertSame(['type' => ['BLOG']], $query['f']);
        self::assertSame('https://img.test/blog.png', $blogs[0]['image']);
    }

    public function testEscapesTheSuggestions(): void
    {
        $data = json_decode((string) $this->request('/demo/api/suggest?q=lap')->getContent(), true);

        foreach (array_merge(...array_column($data['groups'], 'items')) as $item) {
            self::assertSame($item['html'], strip_tags($item['html'], '<mark>'), 'only <mark> is kept');
        }
    }

    public function testSuggestsNothingForShortQueries(): void
    {
        $data = json_decode((string) $this->request('/demo/api/suggest?q=l')->getContent(), true);

        self::assertSame(['completions' => [], 'groups' => [], 'found' => 0], $data);
        self::assertSame([], MockApi::$requests);
    }

    private function request(string $uri): Response
    {
        return $this->bootKernel(demo: true)->handle(Request::create('https://shop.test' . $uri));
    }
}
