<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Sync;

use Batteryincluded\BatteryincludedBundle\Sync\SyncResult;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;

class SyncResultTest extends TestCase
{
    public function testCountsSyncedAndFailedDocuments(): void
    {
        $result = new SyncResult();
        $result->addResponse(new Response('[{"id":"PRODUCT-1","success":true},{"id":"PRODUCT-2","success":false,"error":"invalid price"}]', 200), 2);
        $result->addResponse(new Response('[{"id":"PRODUCT-3","success":false,"error":{"field":"price"}},{"success":false}]', 200), 3);

        self::assertSame(5, $result->getSent());
        self::assertSame(2, $result->getSynced());
        self::assertFalse($result->isSuccessful());
        self::assertSame('PRODUCT-2', $result->getFailed()[0]['id']);
        self::assertSame('invalid price', $result->getFailed()[0]['error']);
        // structured errors are kept as json, results without id are reported as well
        self::assertStringContainsString('"field":"price"', $result->getFailed()[1]['error']);
        self::assertSame('?', $result->getFailed()[2]['id']);
    }

    public function testIsSuccessfulWithoutFailures(): void
    {
        $result = new SyncResult();
        $result->addResponse(new Response('[{"id":"PRODUCT-1","success":true}]', 200), 1);
        $result->setMissing(['PRODUCT-9']);
        $result->setDeleted(1);

        self::assertTrue($result->isSuccessful());
        self::assertSame(1, $result->getSynced());
        self::assertSame(['PRODUCT-9'], $result->getMissing());
        self::assertSame(1, $result->getDeleted());
    }
}
