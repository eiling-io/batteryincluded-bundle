<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Document;

use Batteryincluded\BatteryincludedBundle\Document\Document;
use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlGenerator;
use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function types(): iterable
    {
        yield 'type field' => [['type' => 'blog', 'id' => 'PRODUCT-1'], 'BLOG'];
        yield 'id prefix' => [['id' => 'CONTENT-12'], 'CONTENT'];
        yield 'highlighted id prefix' => [['id' => '<mark>FAQ</mark>-7'], 'FAQ'];
        yield 'translation node' => [['_CONTENT_i18n' => ['title' => 'Shipping']], 'CONTENT'];
        yield 'availability node' => [['_PRODUCT_availability' => []], 'PRODUCT'];
        yield 'payload node' => [['_BLOG' => []], 'BLOG'];
        yield 'nothing' => [[], 'PRODUCT'];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('types')]
    public function testType(array $document, string $expected): void
    {
        self::assertSame($expected, Document::type($document));
    }

    public function testIdPrefersTheRootId(): void
    {
        self::assertSame('PRODUCT-1', Document::id(['id' => 'PRODUCT-1', '_PRODUCT' => ['id' => '99']]));
        // suggestions highlight the id as well
        self::assertSame('PRODUCT-12', Document::id(['id' => 'PRODUCT-<mark>12</mark>']));
    }

    public function testIdFallsBackToTheShopId(): void
    {
        self::assertSame('BLOG-3', Document::id(['_BLOG' => ['id' => '3']]));
        self::assertSame('', Document::id(['_BLOG' => ['title' => 'Without id']]));
    }

    public function testDataMergesFlatTranslationsAndAvailability(): void
    {
        $document = [
            'id' => 'PRODUCT-1',
            '_PRODUCT' => ['name' => 'Laptop', 'price' => 999],
            '_PRODUCT_i18n' => ['name' => 'Notebook'],
            '_PRODUCT_availability' => ['ch' => ['price' => 1099]],
        ];

        self::assertSame(['name' => 'Notebook', 'price' => 1099], Document::data($document, 'ch'));
    }

    public function testDataKeepsTranslationsPerLocale(): void
    {
        $document = ['_PRODUCT' => ['name' => 'Laptop'], '_PRODUCT_i18n' => ['de' => ['name' => 'Notebook'], 'en' => ['name' => 'Laptop']]];

        self::assertSame(['name' => 'Laptop'], Document::data($document));
    }

    public function testHighlightEscapesAllButMarks(): void
    {
        self::assertSame('<mark>Lap</mark>top &lt;script&gt;', Document::highlight('<mark>Lap</mark>top <script>'));
    }

    public function testUrlGeneratorAsksTheResolversInOrder(): void
    {
        $resolver = static fn (?string $url) => new class($url) implements DocumentUrlResolverInterface {
            public function __construct(private readonly ?string $url)
            {
            }

            public function resolve(string $id, string $type, array $data): ?string
            {
                return $this->url === null ? null : $this->url . $id;
            }
        };

        $generator = new DocumentUrlGenerator([$resolver(null), $resolver('/p/'), $resolver('/other/')]);

        self::assertSame('/p/PRODUCT-1', $generator->generate('PRODUCT-1', 'PRODUCT', []));
        self::assertSame('/p/CONTENT-2', $generator->generateForDocument(['id' => 'CONTENT-2', '_CONTENT' => ['url' => '/shipping']]));
    }

    public function testUrlGeneratorFallsBackToTheUrlField(): void
    {
        $generator = new DocumentUrlGenerator();

        self::assertSame('/shipping', $generator->generateForDocument(['id' => 'CONTENT-2', '_CONTENT_i18n' => ['url' => '/<mark>ship</mark>ping']]));
        self::assertNull($generator->generate('PRODUCT-1', 'PRODUCT', []));
    }
}
