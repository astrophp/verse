<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Unit\Serve;

use Astro\Verse\Serve\RebuildScheduler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RebuildSchedulerTest extends TestCase
{
    #[Test]
    public function nothing_starts_without_a_change(): void
    {
        $scheduler = new RebuildScheduler(0.15);

        $this->assertFalse($scheduler->tick(0.0));
        $this->assertFalse($scheduler->tick(100.0));
    }

    #[Test]
    public function a_change_starts_a_build_after_the_debounce(): void
    {
        $scheduler = new RebuildScheduler(0.15);
        $scheduler->changed(10.0);

        $this->assertFalse($scheduler->tick(10.0));
        $this->assertFalse($scheduler->tick(10.149));
        $this->assertTrue($scheduler->tick(10.15));
        $this->assertFalse($scheduler->tick(10.2), 'one change starts one build');
    }

    #[Test]
    public function further_changes_extend_the_debounce(): void
    {
        $scheduler = new RebuildScheduler(0.15);
        $scheduler->changed(10.0);
        $scheduler->changed(10.1);
        $scheduler->changed(10.2);

        $this->assertFalse($scheduler->tick(10.3));
        $this->assertTrue($scheduler->tick(10.35));
    }

    #[Test]
    public function the_first_build_does_not_wait(): void
    {
        $scheduler = new RebuildScheduler(0.15);
        $scheduler->requestNow(5.0);

        $this->assertTrue($scheduler->tick(5.0));
    }

    #[Test]
    public function builds_never_overlap(): void
    {
        $scheduler = new RebuildScheduler(0.15);
        $scheduler->changed(0.0);
        $this->assertTrue($scheduler->tick(1.0));
        $this->assertTrue($scheduler->isBuilding());

        $scheduler->changed(1.5);

        $this->assertFalse($scheduler->tick(2.0));
        $this->assertFalse($scheduler->tick(60.0));
        $this->assertTrue($scheduler->isDirty());
    }

    #[Test]
    public function changes_during_a_build_schedule_exactly_one_follow_up(): void
    {
        $scheduler = new RebuildScheduler(0.15);
        $scheduler->changed(0.0);
        $this->assertTrue($scheduler->tick(1.0));

        foreach ([1.1, 1.2, 1.3, 1.4, 1.5] as $time) {
            $scheduler->changed($time);
            $this->assertFalse($scheduler->tick($time));
        }
        $scheduler->finished();

        $this->assertFalse($scheduler->tick(1.55), 'the follow-up still waits for the debounce');
        $this->assertTrue($scheduler->tick(1.7));
        $scheduler->finished();
        $this->assertFalse($scheduler->tick(5.0), 'and there is only one');
        $this->assertFalse($scheduler->isDirty());
    }

    #[Test]
    public function an_old_change_followed_by_a_finished_build_starts_the_follow_up_at_once(): void
    {
        $scheduler = new RebuildScheduler(0.15);
        $scheduler->changed(0.0);
        $this->assertTrue($scheduler->tick(1.0));
        $scheduler->changed(1.01);
        $scheduler->finished();

        $this->assertTrue($scheduler->tick(3.0));
    }

    #[Test]
    public function a_build_without_changes_leaves_the_scheduler_idle(): void
    {
        $scheduler = new RebuildScheduler(0.15);
        $scheduler->changed(0.0);
        $this->assertTrue($scheduler->tick(1.0));
        $scheduler->finished();

        $this->assertFalse($scheduler->tick(2.0));
        $this->assertFalse($scheduler->isBuilding());
        $this->assertFalse($scheduler->isDirty());
    }
}
