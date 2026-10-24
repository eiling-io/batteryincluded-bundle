<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Doctrine;

use Batteryincluded\BatteryincludedBundle\Doctrine\Attribute\BatteryIncludedDocument;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentChangeQueue;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;

/**
 * Schedules a sync or delete for entities with #[BatteryIncludedDocument]. Changes are collected per flush and only
 * handed to the DocumentChangeQueue after a successful flush, a rolled back flush changes nothing.
 */
class DocumentChangeListener
{
    /**
     * @var array<string, list<BatteryIncludedDocument>>
     */
    private array $attributes = [];

    /**
     * @var array<string, array{id: string, collection: string, delete: bool, deleteMissing: bool}>
     */
    private array $pending = [];

    /**
     * Changes of removed entities, read in preRemove (called by remove(), before the flush) and applied in postRemove.
     *
     * @var array<int, array<string, array{id: string, collection: string, delete: bool, deleteMissing: bool}>>
     */
    private array $removals = [];

    public function __construct(private readonly DocumentChangeQueue $queue)
    {
    }

    public function preFlush(): void
    {
        $this->pending = [];
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        unset($this->removals[spl_object_id($args->getObject())]);
        $this->pending = array_merge($this->pending, $this->collect($args->getObject(), $args->getObjectManager(), false));
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->pending = array_merge($this->pending, $this->collect($args->getObject(), $args->getObjectManager(), false));
    }

    /**
     * The identifier is read before the removal, Doctrine resets generated ids afterwards.
     *
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $this->removals[spl_object_id($args->getObject())] = $this->collect($args->getObject(), $args->getObjectManager(), true);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postRemove(LifecycleEventArgs $args): void
    {
        $key = spl_object_id($args->getObject());
        $this->pending = array_merge($this->pending, $this->removals[$key] ?? []);
        unset($this->removals[$key]);
    }

    public function onClear(): void
    {
        $this->pending = [];
        $this->removals = [];
    }

    public function postFlush(): void
    {
        foreach ($this->pending as $change) {
            if ($change['delete']) {
                $this->queue->scheduleDelete($change['id'], $change['collection']);
            } else {
                $this->queue->scheduleSync($change['id'], $change['collection'], $change['deleteMissing']);
            }
        }
        $this->pending = [];
    }

    /**
     * @return array<string, array{id: string, collection: string, delete: bool, deleteMissing: bool}>
     */
    private function collect(object $entity, ObjectManager $objectManager, bool $removed): array
    {
        $changes = [];
        $metadata = $objectManager->getClassMetadata($entity::class);
        foreach ($this->getAttributes($metadata->getName()) as $attribute) {
            $identifier = $attribute->identifier === null
                ? $this->doctrineIdentifier($metadata->getIdentifierValues($entity))
                : $this->readIdentifier($entity, $attribute->identifier);
            if ($identifier === null || $identifier === '') {
                continue;
            }

            $id = mb_strtoupper($attribute->type) . '-' . $identifier;
            $changes[$attribute->collection . '|' . $id] = [
                'id' => $id,
                'collection' => $attribute->collection,
                'delete' => $removed && $attribute->deleteOnRemove,
                'deleteMissing' => $attribute->deleteMissing,
            ];
        }

        return $changes;
    }

    /**
     * @param class-string $class
     *
     * @return list<BatteryIncludedDocument>
     */
    private function getAttributes(string $class): array
    {
        if (!isset($this->attributes[$class])) {
            $attributes = [];
            // attributes of parent classes count as well, e.g. with single table inheritance
            for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                foreach ($reflection->getAttributes(BatteryIncludedDocument::class) as $attribute) {
                    $attributes[] = $attribute->newInstance();
                }
            }
            $this->attributes[$class] = $attributes;
        }

        return $this->attributes[$class];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function doctrineIdentifier(array $values): ?string
    {
        if (count($values) !== 1) {
            return $values === [] ? null : throw new \LogicException('Entities with a composite id need the "identifier" option of #[BatteryIncludedDocument].');
        }

        $value = reset($values);

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }

    private function readIdentifier(object $entity, string $identifier): ?string
    {
        if (method_exists($entity, $identifier)) {
            $value = $entity->{$identifier}();
        } elseif (method_exists($entity, 'get' . ucfirst($identifier))) {
            $value = $entity->{'get' . ucfirst($identifier)}();
        } elseif (property_exists($entity, $identifier)) {
            $property = new \ReflectionProperty($entity, $identifier);
            $value = $property->isInitialized($entity) ? $property->getValue($entity) : null;
        } else {
            throw new \LogicException(sprintf('%s has no method or property "%s" for #[BatteryIncludedDocument].', $entity::class, $identifier));
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
