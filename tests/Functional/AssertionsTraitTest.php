<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Functional;

use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Batteryincluded\BatteryincludedBundle\Test\BatteryIncludedAssertionsTrait;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\TestKernel;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The trait as an application uses it, with `in_memory_api: true` in the test environment.
 */
class AssertionsTraitTest extends KernelTestCase
{
    use BatteryIncludedAssertionsTrait;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(['in_memory_api' => true]);
    }

    /**
     * @var callable|null
     */
    private $exceptionHandler;

    protected function setUp(): void
    {
        $this->exceptionHandler = self::currentExceptionHandler();
    }

    /**
     * Symfony 7.0 keeps the exception handler of the ErrorHandler component registered, phpunit expects the old one.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        for ($i = 0; $i < 10 && self::currentExceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    private static function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    public function testDocumentsOfASyncAreAsserted(): void
    {
        self::bootKernel();

        /** @var DocumentSynchronizer $synchronizer */
        $synchronizer = self::getContainer()->get(DocumentSynchronizer::class);
        $synchronizer->sync([ArrayProvider::product('1'), ArrayProvider::product('2')]);
        $synchronizer->sync([ArrayProvider::product('3')], 'ch');
        $synchronizer->delete(['PRODUCT-2']);

        self::assertBatteryIncludedDocumentExists('PRODUCT-1');
        self::assertBatteryIncludedDocumentNotExists('PRODUCT-2');
        self::assertBatteryIncludedDocumentExists('PRODUCT-3', 'ch');
        self::assertBatteryIncludedDocumentCount(1, 'PRODUCT');
        self::assertBatteryIncludedDocumentCount(0, 'BLOG');
        self::assertBatteryIncludedRequest('POST', '/documents/import', 2);
        self::assertBatteryIncludedRequest('DELETE', '/documents/delete');
    }

    public function testFixturesAreFoundBySearches(): void
    {
        self::bootKernel();
        self::addBatteryIncludedDocuments([ArrayProvider::product('7')]);

        /** @var BrowseService $browse */
        $browse = self::getContainer()->get(BrowseService::class);
        $search = new BrowseSearchStruct();
        $search->setQuery('product 7');

        self::assertSame(1, $browse->browse($search)->getFound());
        self::assertSame(['PRODUCT-7'], array_keys(self::getBatteryIncludedDocuments()));
    }

    public function testFailingAssertion(): void
    {
        self::bootKernel();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The collection "default" does not contain the document "PRODUCT-1".');

        self::assertBatteryIncludedDocumentExists('PRODUCT-1');
    }
}
