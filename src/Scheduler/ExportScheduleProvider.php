<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Scheduler;

use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Schedule `batteryincluded` with one recurring export per entry of `export.schedule`, the ExportMessageHandler runs
 * `bi:export`. Consume it with `bin/console messenger:consume scheduler_batteryincluded`.
 */
class ExportScheduleProvider implements ScheduleProviderInterface
{
    public const NAME = 'batteryincluded';

    private ?Schedule $schedule = null;

    /**
     * @param list<array{collection: string, cron: string, providers: list<string>}> $exports
     */
    public function __construct(private readonly array $exports)
    {
    }

    public function getSchedule(): Schedule
    {
        if ($this->schedule !== null) {
            return $this->schedule;
        }

        $schedule = new Schedule();
        foreach ($this->exports as $export) {
            $schedule->add(RecurringMessage::cron($export['cron'], new ExportMessage($export['collection'], $export['providers'])));
        }

        return $this->schedule = $schedule;
    }
}
