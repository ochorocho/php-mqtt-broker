<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Broker;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

/**
 * An event loop on a virtual clock.
 *
 * run() returns at once, so Broker::start() can be called in a test. Timers fire
 * only when the test advances the clock, which makes timeouts deterministic.
 */
final class FakeLoop implements LoopInterface
{
    private float $now = 0.0;

    /** @var array<int, array{timer: TimerInterface, due: float}> */
    private array $timers = [];

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        return $this->schedule(new Timer($interval, $callback, false));
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        return $this->schedule(new Timer($interval, $callback, true));
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        unset($this->timers[spl_object_id($timer)]);
    }

    public function futureTick($listener): void
    {
        $listener();
    }

    public function addSignal($signal, $listener): void
    {
    }

    public function removeSignal($signal, $listener): void
    {
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
    }

    /**
     * Move the clock forward, firing every timer that falls due on the way, in order.
     */
    public function advance(float $seconds): void
    {
        $target = $this->now + $seconds;

        while (($next = $this->nextDue($target)) !== null) {
            $this->now = $next['due'];
            $timer = $next['timer'];

            if ($timer->isPeriodic()) {
                $this->timers[spl_object_id($timer)]['due'] = $this->now + $timer->getInterval();
            } else {
                unset($this->timers[spl_object_id($timer)]);
            }

            ($timer->getCallback())($timer);
        }

        $this->now = $target;
    }

    public function pendingTimerCount(): int
    {
        return count($this->timers);
    }

    private function schedule(TimerInterface $timer): TimerInterface
    {
        $this->timers[spl_object_id($timer)] = ['timer' => $timer, 'due' => $this->now + $timer->getInterval()];

        return $timer;
    }

    /**
     * @return array{timer: TimerInterface, due: float}|null
     */
    private function nextDue(float $limit): ?array
    {
        $next = null;
        foreach ($this->timers as $entry) {
            if ($entry['due'] <= $limit && ($next === null || $entry['due'] < $next['due'])) {
                $next = $entry;
            }
        }

        return $next;
    }
}
