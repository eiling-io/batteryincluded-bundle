<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Suggest;

use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlGenerator;
use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlResolverInterface;
use Batteryincluded\BatteryincludedBundle\Event\SuggestEvent;
use Batteryincluded\BatteryincludedBundle\Suggest\Suggester;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use BatteryIncludedSdk\Suggest\SuggestSearchStruct;
use BatteryIncludedSdk\Suggest\SuggestService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class SuggesterTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testGroupsTheDocumentsByType(): void
    {
        $this->http->queue(self::response());

        $result = $this->suggester()->suggest('lap', 'de', 'ch');

        self::assertSame(['PRODUCT', 'CONTENT', 'BLOG', 'FAQ'], array_column($result['groups'], 'type'));
        self::assertSame(7, $result['found']);
        self::assertSame('lap', $result['query']);
        self::assertSame([['value' => 'laptop', 'html' => '<mark>lap</mark>top']], $result['completions']);

        $product = $result['groups'][0]['items'][0];
        self::assertSame('PRODUCT-1', $product['id']);
        self::assertSame('<mark>Lap</mark>top Pro', $product['html']);
        self::assertSame('Laptop Pro', $product['title']);
        // the price of the requested availability
        self::assertSame(1099.0, $product['price']);
        self::assertSame(3, $product['instock']);
        self::assertTrue($product['active']);
        self::assertSame('https://img.test/1.png', $product['image']);
        self::assertSame('/p/PRODUCT-1', $product['url']);

        $content = $result['groups'][1]['items'][0];
        self::assertSame('Warranty &lt;2 years&gt;', $content['text']);
        // content pages without resolver link to their url field
        self::assertSame('/warranty', $content['url']);

        // without id there is no url
        self::assertSame('', $result['groups'][2]['items'][0]['id']);
        self::assertNull($result['groups'][2]['items'][0]['url']);
    }

    public function testSendsQueryLocaleAvailabilityAndFilters(): void
    {
        $this->http->queue(self::response());

        $this->suggester()->suggest(' lap ', 'de-CH', 'ch', 'ch', ['type' => ['PRODUCT']]);

        $request = $this->http->requests[0];
        self::assertStringStartsWith('https://api.test/collections/customer.test-ch/documents/suggest?', $request['url']);
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        self::assertSame('lap', $query['q']);
        self::assertSame(['PRODUCT'], $query['f']['type']);
        self::assertSame('ch', $query['v']['availability']);
    }

    public function testLimitsAndOrder(): void
    {
        $this->http->queue(self::response());

        $result = $this->suggester(limits: ['PRODUCT' => 1, 'default' => 1, 'BLOG' => 0], order: ['FAQ', 'PRODUCT'], completions: 0)->suggest('lap', 'de');

        self::assertSame(['FAQ', 'PRODUCT', 'CONTENT'], array_column($result['groups'], 'type'));
        self::assertCount(1, $result['groups'][1]['items']);
        self::assertSame([], $result['completions']);
    }

    public function testShortQueriesAreNotSent(): void
    {
        $result = $this->suggester()->suggest(' l ', 'de');

        self::assertSame([], $this->http->requests);
        self::assertSame(['query' => 'l', 'completions' => [], 'groups' => [], 'found' => 0], $result);
    }

    public function testListenersChangeTheResult(): void
    {
        $this->http->queue(self::response());
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SuggestEvent::class, static function (SuggestEvent $event): void {
            $result = $event->getResult();
            $result['groups'] = array_map(static fn (array $group) => ['label' => mb_strtolower($group['type']) . ' (' . $event->locale . ')'] + $group, $result['groups']);
            $event->setResult($result);
        });

        $result = $this->suggester(dispatcher: $dispatcher)->suggest('lap', 'de');

        self::assertSame('product (de)', $result['groups'][0]['label']);
    }

    public function testBuildUsesTheGivenUrlCallback(): void
    {
        $this->http->queue(self::response());
        $response = Collections::registry($this->http)->get('default', SuggestService::class)->suggestWithFilter(new SuggestSearchStruct());

        $result = $this->suggester()->build($response, 'lap', 'de', url: static fn (string $id, string $type) => $type . ':' . $id);

        self::assertSame('PRODUCT:PRODUCT-1', $result['groups'][0]['items'][0]['url']);
    }

    /**
     * @param array<string, int>|null $limits
     * @param list<string>|null       $order
     */
    private function suggester(?array $limits = null, ?array $order = null, int $completions = 5, ?EventDispatcher $dispatcher = null): Suggester
    {
        $resolver = new class implements DocumentUrlResolverInterface {
            public function resolve(string $id, string $type, array $data): ?string
            {
                return $type === 'PRODUCT' ? '/p/' . $id : null;
            }
        };

        return new Suggester(
            Collections::registry($this->http),
            new DocumentUrlGenerator([$resolver]),
            $limits ?? ['PRODUCT' => 4, 'default' => 3],
            $order ?? ['PRODUCT', 'CONTENT', 'BLOG'],
            $completions,
            2,
            $dispatcher,
        );
    }

    private static function response(): Response
    {
        return new Response((string) json_encode([
            ['kind' => 'query-completion', 'hits' => [['value' => 'laptop', 'highlighted' => '<mark>lap</mark>top', 'score' => 1.0]]],
            ['kind' => 'document', 'found' => 7, 'hits' => [
                ['highlighted' => ['id' => 'FAQ-7', '_FAQ' => ['title' => '<mark>Lap</mark>top battery']]],
                ['highlighted' => ['id' => 'CONTENT-4', '_CONTENT' => ['url' => '/warranty'], '_CONTENT_i18n' => ['title' => 'Warranty', 'teaser' => 'Warranty <2 years>']]],
                ['highlighted' => [
                    'id' => 'PRODUCT-1',
                    '_PRODUCT' => ['name' => 'Laptop', 'price' => 999, 'instock' => 3, 'active' => true, 'imageUrl' => 'https://img.test/1.png'],
                    '_PRODUCT_i18n' => ['name' => '<mark>Lap</mark>top Pro'],
                    '_PRODUCT_availability' => ['ch' => ['price' => 1099]],
                ]],
                ['highlighted' => ['id' => 'PRODUCT-2', '_PRODUCT' => ['name' => 'Laptop 2']]],
                ['highlighted' => ['_BLOG_i18n' => ['title' => 'The best <mark>lap</mark>tops']]],
                ['highlighted' => ['id' => 'BLOG-2', '_BLOG' => ['title' => 'Second']]],
                ['highlighted' => ['id' => 'CONTENT-5', '_CONTENT' => ['title' => 'Returns']]],
            ]],
        ]), 200);
    }
}
