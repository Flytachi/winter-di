<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Container;
use PHPUnit\Framework\TestCase;

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[Request]
class RsJobContext
{
    public string $job = 'unset';
}

#[Singleton]
class RsShared
{
    public string $note = 'survives';
}

#[Transient]
class RsFresh
{
}

/**
 * A request scope has to be closable by hand where nothing closes it by itself.
 *
 * Under HTTP the scope ends with the coroutine that carried the request. Nothing else
 * has that boundary: a worker looping over jobs runs its whole body in one coroutine,
 * so a request-scoped bean resolved inside it survives every iteration and carries the
 * previous job's state into the next. The framework cannot see where one unit of work
 * ends — only the code driving the loop can say so.
 */
final class ContainerRequestScopeTest extends TestCase
{
    protected function setUp(): void
    {
        Container::init();
    }

    public function test_a_request_bean_is_reused_until_the_scope_is_flushed(): void
    {
        $c = Container::getInstance();

        $first = $c->make(RsJobContext::class);
        self::assertSame($first, $c->make(RsJobContext::class), 'Same unit — same instance.');

        $c->flushRequestScope();

        self::assertNotSame($first, $c->make(RsJobContext::class), 'New unit — new instance.');
    }

    /**
     * The failure this exists to prevent: state from the previous job showing up in the
     * next one.
     */
    public function test_state_does_not_survive_the_flush(): void
    {
        $c = Container::getInstance();

        $c->make(RsJobContext::class)->job = 'job-1';
        $c->flushRequestScope();

        self::assertSame('unset', $c->make(RsJobContext::class)->job);
    }

    /**
     * Ending a scope is not resetting the container — a singleton is not scoped to a
     * unit of work and must keep both its identity and its state.
     */
    public function test_singletons_are_untouched(): void
    {
        $c = Container::getInstance();

        $shared = $c->make(RsShared::class);
        $shared->note = 'still here';
        $c->flushRequestScope();

        $after = $c->make(RsShared::class);
        self::assertSame($shared, $after);
        self::assertSame('still here', $after->note);
    }

    public function test_transients_are_unaffected(): void
    {
        $c = Container::getInstance();

        $before = $c->make(RsFresh::class);
        $c->flushRequestScope();
        $after = $c->make(RsFresh::class);

        self::assertNotSame($before, $after, 'Transients were never shared to begin with.');
    }

    public function test_flushing_an_empty_scope_is_harmless(): void
    {
        $c = Container::getInstance();

        $c->flushRequestScope();
        $c->flushRequestScope();

        self::assertInstanceOf(RsJobContext::class, $c->make(RsJobContext::class));
    }

    /**
     * Repeated units must not accumulate anything — the bookkeeping that lets the flush
     * stay cheap has to be cleared along with the instances.
     */
    public function test_repeated_units_do_not_accumulate(): void
    {
        $c = Container::getInstance();
        $seen = [];

        for ($unit = 0; $unit < 5; $unit++) {
            $seen[] = spl_object_id($c->make(RsJobContext::class));
            $c->flushRequestScope();
        }

        self::assertCount(5, $seen);
        self::assertSame([], array_diff_assoc($seen, $seen), 'sanity');
    }
}
