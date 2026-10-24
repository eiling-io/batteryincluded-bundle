<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Doctrine;

use Batteryincluded\BatteryincludedBundle\Doctrine\DocumentChangeListener;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentChangeQueue;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Entity\Note;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Entity\Product;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Entity\Variant;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class DocumentChangeListenerTest extends TestCase
{
    private EntityManager $entityManager;

    private DocumentChangeQueue $queue;

    protected function setUp(): void
    {
        $paths = [__DIR__ . '/../../Fixtures/Entity'];
        $config = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true);
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/batteryincluded-bundle-tests-proxies');
            $config->setProxyNamespace('BatteryincludedTestProxies');
        }
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->queue = new DocumentChangeQueue(new DocumentSynchronizer(Collections::registry(new FakeHttpClient())));
        $this->entityManager->getEventManager()->addEventListener(
            [Events::preFlush, Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postFlush, Events::onClear],
            new DocumentChangeListener($this->queue),
        );
    }

    public function testSchedulesSyncAfterFlush(): void
    {
        $product = new Product('Laptop');
        $this->entityManager->persist($product);
        self::assertSame([], $this->queue->getChanges());

        $this->entityManager->flush();

        self::assertSame(['default' => ['PRODUCT-' . $product->id => ['action' => 'sync', 'deleteMissing' => true]]], $this->queue->getChanges());
    }

    public function testSchedulesSyncOnUpdateAndDeleteOnRemove(): void
    {
        $product = new Product('Laptop');
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $id = $product->id;
        $this->queue->flush();

        $product->name = 'Laptop Pro';
        $this->entityManager->flush();
        self::assertSame('sync', $this->queue->getChanges()['default']['PRODUCT-' . $id]['action']);

        $this->entityManager->remove($product);
        $this->entityManager->flush();
        self::assertSame('delete', $this->queue->getChanges()['default']['PRODUCT-' . $id]['action']);
    }

    public function testRepeatedAttributesAndCustomIdentifiers(): void
    {
        $product = new Product('Laptop');
        $variant = new Variant('LT-1-BLUE', $product);
        $this->entityManager->persist($product);
        $this->entityManager->persist($variant);
        $this->entityManager->flush();
        $this->queue->flush();

        $this->entityManager->remove($variant);
        $this->entityManager->flush();

        self::assertSame([
            'ch' => ['VARIANT-LT-1-BLUE' => ['action' => 'delete', 'deleteMissing' => false]],
            'default' => ['PRODUCT-' . $product->id => ['action' => 'sync', 'deleteMissing' => false]],
        ], $this->queue->getChanges());
    }

    public function testSkipsEntitiesWithoutAttributeOrIdentifier(): void
    {
        $this->entityManager->persist(new Note());
        $this->entityManager->persist(new Variant('LT-2'));
        $this->entityManager->flush();

        self::assertSame(['ch' => ['VARIANT-LT-2' => ['action' => 'sync', 'deleteMissing' => false]]], $this->queue->getChanges());
    }

    public function testRemoveWithoutFlushSchedulesNothing(): void
    {
        $product = new Product('Laptop');
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $this->queue->flush();

        $this->entityManager->remove($product);
        $this->entityManager->clear();
        $this->entityManager->persist(new Note());
        $this->entityManager->flush();

        self::assertSame([], $this->queue->getChanges());
    }

    public function testFailedFlushSchedulesNothing(): void
    {
        $product = new Product('Laptop');
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $this->queue->flush();

        $this->entityManager->getConnection()->executeStatement('DROP TABLE Product');
        $product->name = 'Laptop Pro';
        try {
            $this->entityManager->flush();
            self::fail('The flush has to fail.');
        } catch (\Throwable) {
        }

        self::assertSame([], $this->queue->getChanges());
    }
}
