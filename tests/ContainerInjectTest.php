<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Lazy;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Container;
use PHPUnit\Framework\TestCase;

// ── Fixtures ──────────────────────────────────────────────────────────────────

class InjMailer
{
    public string $tag = 'mailer';
}

#[Singleton]
class InjShared
{
    public int $touched = 0;
}

class InjTarget
{
    #[Autowired]
    public ?InjMailer $mailer = null;

    #[Autowired]
    public ?InjShared $shared = null;

    public string $constructedWith;

    public function __construct(string $alias = 'none')
    {
        $this->constructedWith = $alias;
    }
}

class InjPlain
{
    public string $untouched = 'as built';
}

class InjLazyTarget
{
    #[Autowired, Lazy]
    public ?InjMailer $mailer = null;
}

class InjNamedTarget
{
    #[Inject(InjMailer::class)]
    public ?object $service = null;
}

/**
 * `inject()` fills an object the caller already built.
 *
 * The container's own `make()` builds and injects in one step, which is right whenever
 * the container owns construction. It does not when the caller must control the object's
 * identity — `Repository::instance($alias)` is the case that prompted this: the alias
 * lives in per-object state, so two aliases of one table need two distinct objects, and
 * routing that through `make()` on a shared binding would collapse them into one.
 * Splitting construction from injection serves both without a compromise.
 */
final class ContainerInjectTest extends TestCase
{
    protected function setUp(): void
    {
        Container::init();
    }

    public function test_it_fills_autowired_properties_of_a_hand_built_object(): void
    {
        $target = new InjTarget('c');

        Container::getInstance()->inject($target);

        self::assertInstanceOf(InjMailer::class, $target->mailer);
        self::assertInstanceOf(InjShared::class, $target->shared);
    }

    public function test_it_leaves_the_object_the_caller_built(): void
    {
        $target = new InjTarget('c');

        $returned = Container::getInstance()->inject($target);

        self::assertSame($target, $returned, 'inject() must not swap the instance.');
        self::assertSame('c', $target->constructedWith, 'Constructor state must survive.');
    }

    /**
     * The reason the method exists: two hand-built objects stay distinct, so per-object
     * state (a query alias, for instance) cannot collide the way a shared binding would.
     */
    public function test_two_injected_objects_stay_distinct(): void
    {
        $a = new InjTarget('a');
        $b = new InjTarget('b');

        Container::getInstance()->inject($a);
        Container::getInstance()->inject($b);

        self::assertNotSame($a, $b);
        self::assertSame('a', $a->constructedWith);
        self::assertSame('b', $b->constructedWith);
    }

    public function test_a_shared_dependency_is_the_same_across_injected_objects(): void
    {
        $a = new InjTarget();
        $b = new InjTarget();

        $c = Container::getInstance();
        $c->inject($a);
        $c->inject($b);

        self::assertSame($a->shared, $b->shared, 'A #[Singleton] dependency stays shared.');
    }

    public function test_an_object_without_autowired_properties_is_untouched(): void
    {
        $plain = new InjPlain();

        Container::getInstance()->inject($plain);

        self::assertSame('as built', $plain->untouched);
    }

    public function test_it_honours_the_lazy_attribute(): void
    {
        $target = new InjLazyTarget();

        Container::getInstance()->inject($target);

        self::assertNotNull($target->mailer);
        self::assertSame('mailer', $target->mailer->tag, 'The proxy must resolve on use.');
    }

    public function test_it_honours_an_explicit_inject_target(): void
    {
        $target = new InjNamedTarget();

        Container::getInstance()->inject($target);

        self::assertInstanceOf(InjMailer::class, $target->service);
    }

    /**
     * Code reachable both from a booted application and from a bare script needs to ask
     * whether a container exists, rather than catch an exception to find out.
     */
    public function test_it_reports_whether_a_container_exists(): void
    {
        self::assertTrue(Container::isInitialized(), 'setUp() called init().');

        new \ReflectionProperty(Container::class, 'instance')->setValue(null, null);
        self::assertFalse(Container::isInitialized(), 'Nothing was initialised.');

        Container::init();
        self::assertTrue(Container::isInitialized());
    }

    /**
     * Injecting the same object twice must be harmless — the second pass simply
     * overwrites with the same resolutions.
     */
    public function test_injecting_twice_is_idempotent(): void
    {
        $target = new InjTarget();
        $c = Container::getInstance();

        $c->inject($target);
        $first = $target->shared;
        $c->inject($target);

        self::assertSame($first, $target->shared);
    }
}
