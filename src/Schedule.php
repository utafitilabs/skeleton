<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The installation's default schedule. The core's tasks (the facts recompute)
 * attach to it by tag; the tasks below are this installation's own.
 *
 * Its state and its lock live in the database, on the registry's pool and lock
 * store (see config/services.yaml), so a run missed while the worker was down
 * runs once on restart, a redeploy keeps that memory, and a second worker
 * container shares the lock.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $state,
        private LockFactory $locks,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->state)
            ->processOnlyLastMissedRun(true)
            ->lock($this->locks->createLock('uhifadhi.schedule.default'))
            // add your own tasks here
            // see https://symfony.com/doc/current/scheduler.html#attaching-recurring-messages-to-a-schedule
        ;
    }
}
