<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Twig;

use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlGenerator;
use Batteryincluded\BatteryincludedBundle\Http\SearchStructValueResolver;
use Batteryincluded\BatteryincludedBundle\Twig\BatteryIncludedExtension;
use Batteryincluded\BatteryincludedBundle\Twig\BatteryIncludedRuntime;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\TestLogger;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

class BatteryIncludedRuntimeTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testSearchReturnsTheResolvedDocuments(): void
    {
        $this->http->queue(new Response((string) json_encode(['found' => 1, 'hits' => [['document' => [
            'id' => 'BLOG-1',
            'type' => 'BLOG',
            '_BLOG' => ['title' => 'Default'],
            '_BLOG_i18n' => ['en' => ['title' => 'English']],
        ]]]]), 200));
        $request = new Request();
        $request->setLocale('en');
        $request->attributes->set(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE, 'ch');

        $documents = $this->runtime($request)->search('news', ['type' => 'blog', 'per_page' => 3, 'sort' => '_BLOG.date:desc', 'filters' => ['_BLOG.author' => 'Jane']]);

        self::assertSame('English', $documents[0]['_BLOG']['title']);
        parse_str((string) parse_url($this->http->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('news', $query['q']);
        self::assertSame(['_BLOG.author' => ['Jane'], 'type' => ['BLOG']], $query['f']);
        self::assertSame('3', $query['per_page']);
        self::assertSame('_BLOG.date:desc', $query['sort']);
        self::assertSame(['locale' => 'en', 'availability' => 'ch'], $query['v']);
    }

    public function testSearchUsesTheGivenCollection(): void
    {
        $this->http->queue(new Response('{"found":0,"hits":[]}', 200));

        self::assertSame([], $this->runtime()->search('', ['collection' => 'ch', 'locale' => 'de']));
        self::assertStringContainsString('/customer.test-ch/documents/browse', $this->http->requests[0]['url']);
    }

    public function testRecommendations(): void
    {
        $body = (string) json_encode([['type' => 'related', 'id' => 'PRODUCT-2'], ['type' => 'related', 'id' => 'PRODUCT-3'], ['type' => 'together', 'id' => 'PRODUCT-4']]);
        $this->http->queue(new Response($body, 200), new Response($body, 200), new Response('[]', 200));
        $runtime = $this->runtime();

        self::assertSame(['PRODUCT-2'], array_column($runtime->recommendations('PRODUCT-1', 'related', ['limit' => 1]), 'id'));
        self::assertSame(['related', 'also', 'together'], array_keys($runtime->recommendations('7', null)));
        self::assertSame([], $runtime->recommendations('BLOG-1', 'also'));

        self::assertStringEndsWith('/documents/recommendations?id=PRODUCT-1', $this->http->requests[0]['url']);
        self::assertStringEndsWith('/documents/recommendations?id=PRODUCT-7', $this->http->requests[1]['url']);
        self::assertStringEndsWith('/documents/recommendations?id=BLOG-1', $this->http->requests[2]['url']);
    }

    public function testHighlights(): void
    {
        $this->http->queue(new Response('{"searches":["laptop"],"querySuggestions":["Which laptop?"]}', 200));

        self::assertSame(['searches' => ['laptop'], 'querySuggestions' => ['Which laptop?']], $this->runtime()->highlights());
    }

    public function testFailedCallsAreLoggedAndReturnAnEmptyResult(): void
    {
        $this->http->queue(new \RuntimeException('Connection refused'), new \RuntimeException('Connection refused'));
        $logger = new TestLogger();
        $runtime = $this->runtime(logger: $logger);

        self::assertSame([], $runtime->search('laptop'));
        self::assertSame(['searches' => [], 'querySuggestions' => []], $runtime->highlights());
        self::assertCount(2, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
    }

    public function testFailedCallsAreThrownInDebugMode(): void
    {
        $this->http->queue(new \RuntimeException('Connection refused'));

        $this->expectExceptionMessage('Connection refused');

        $this->runtime(debug: true)->search('laptop');
    }

    public function testUnknownOptionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown options "perPage"');

        $this->runtime()->search('', ['perPage' => 3]);
    }

    public function testTemplatesUseTheFunctionsAndTheFilter(): void
    {
        $this->http->queue(new Response((string) json_encode(['found' => 1, 'hits' => [['document' => ['id' => 'CONTENT-1', 'type' => 'CONTENT', '_CONTENT' => ['title' => 'Shipping', 'url' => '/shipping']]]]]), 200));
        $runtime = $this->runtime();
        $twig = new Environment(new ArrayLoader(['page' => <<<'TWIG'
            {%- for document in bi_search('', {type: 'content'}) -%}
            <a href="{{ bi_document_url(document) }}">{{ document._CONTENT.title }}</a>
            {%- endfor %} {{ '<mark>Lap</mark>top <b>'|bi_highlight }}
            TWIG]), ['strict_variables' => true]);
        $twig->addExtension(new BatteryIncludedExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([BatteryIncludedRuntime::class => static fn () => $runtime]));

        self::assertSame('<a href="/shipping">Shipping</a> <mark>Lap</mark>top &lt;b&gt;', $twig->render('page'));
    }

    private function runtime(?Request $request = null, ?TestLogger $logger = null, bool $debug = false): BatteryIncludedRuntime
    {
        $requestStack = new RequestStack();
        if ($request !== null) {
            $requestStack->push($request);
        }

        return new BatteryIncludedRuntime(Collections::registry($this->http), new DocumentUrlGenerator(), $requestStack, $logger, $debug);
    }
}
