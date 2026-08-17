<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Exception\ContainerException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

// ── Fixtures ──────────────────────────────────────────────────────────────────

class CoStore
{
    /** @var int How many times the factory actually ran. */
    public static int $builds = 0;
}

/** Transient, like a controller or a service: rebuilt on every request. */
class CoService
{
    #[Inject('slowStore')]
    public CoStore $store;
}

#[Singleton]
class CoShared
{
    public static int $builds = 0;
}

/** A singleton slow enough to still be building when the next coroutine arrives. */
#[Singleton]
class CoSlowSingleton
{
    public const float BUILD_SECONDS = 0.2;

    public function __construct(public int $n = 0)
    {
        Coroutine::sleep(self::BUILD_SECONDS);
    }
}

class CoCycleA
{
    public function __construct(public CoCycleB $b)
    {
    }
}

class CoCycleB
{
    public function __construct(public CoCycleA $a)
    {
    }
}

/**
 * Resolution under concurrency — one worker, many requests, one container.
 *
 * Under Swoole a worker serves requests as coroutines inside a single process, so
 * anything the container keeps in a property is shared by every request in flight. The
 * resolution stack must not be: a request pausing on I/O halfway through a resolution
 * would otherwise look, to every other request resolving the same class, exactly like a
 * circular dependency. The failure is silent in testing and shows up in production only
 * when two requests overlap on the same class.
 *
 * Each test runs in its own process on purpose. Xdebug's function observers do not survive
 * coroutine stacks: once a child coroutine has suspended and resumed, the interpreter
 * segfaults in `xdebug_execute_user_code_end` at request shutdown — after the tests
 * themselves have passed, so the report says OK and the exit code says 139. Every
 * `xdebug.mode` does it, `coverage` included; the alternative is running the suite under
 * `XDEBUG_MODE=off`, which nobody remembers to do. Here the crash lands in a child whose
 * result is already out, and the run stays green wherever Xdebug happens to be loaded.
 *
 * @requires extension swoole
 */
#[RunTestsInSeparateProcesses]
final class ContainerConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole is required for the coroutine paths');
        }

        CoStore::$builds  = 0;
        CoShared::$builds = 0;
    }

    /**
     * @param callable(Container): void $body
     */
    private function inCoroutines(callable $body): void
    {
        $container = Container::init();

        // A bean whose factory does I/O — the pause that used to look like a cycle.
        $container->request('slowStore', static function () {
            Coroutine::sleep(0.02);
            CoStore::$builds++;
            return new CoStore();
        });

        Coroutine\run(static fn() => $body($container));
    }

    public function test_parallel_resolution_is_not_a_circular_dependency(): void
    {
        $outcome = [];

        $this->inCoroutines(function (Container $c) use (&$outcome): void {
            $group = new WaitGroup();

            foreach ([1, 2, 3] as $n) {
                $group->add();
                Coroutine::create(function () use ($c, $n, $group, &$outcome): void {
                    try {
                        $outcome[$n] = $c->make(CoService::class) instanceof CoService ? 'ok' : 'wrong type';
                    } catch (\Throwable $e) {
                        $outcome[$n] = $e->getMessage();
                    } finally {
                        $group->done();
                    }
                });
            }

            $group->wait();
        });

        ksort($outcome);   // coroutines finish in whatever order they wake up
        self::assertSame([1 => 'ok', 2 => 'ok', 3 => 'ok'], $outcome);
    }

    public function test_a_singleton_is_built_once_under_concurrency(): void
    {
        $instances = [];

        $this->inCoroutines(function (Container $c) use (&$instances): void {
            $c->singleton(CoShared::class, static function () {
                Coroutine::sleep(0.02);   // the first resolution does I/O and yields
                CoShared::$builds++;
                return new CoShared();
            });

            $group = new WaitGroup();

            foreach ([1, 2, 3] as $n) {
                $group->add();
                Coroutine::create(function () use ($c, $n, $group, &$instances): void {
                    try {
                        $instances[$n] = spl_object_id($c->make(CoShared::class));
                    } finally {
                        $group->done();
                    }
                });
            }

            $group->wait();
        });

        self::assertSame(1, CoShared::$builds, 'the factory must run once, not once per coroutine');
        self::assertCount(1, array_unique($instances), 'every coroutine gets the same instance');
    }

    /**
     * A build with overrides is never cached, so nobody should ever queue behind it: the
     * waiter would sleep through the whole build only to find an empty cache and build its
     * own copy anyway — paying for two builds instead of one.
     */
    public function test_a_build_with_overrides_does_not_hold_up_a_plain_resolution(): void
    {
        $waited = 0.0;

        $this->inCoroutines(function (Container $c) use (&$waited): void {
            $group = new WaitGroup();
            $group->add();
            Coroutine::create(function () use ($c, $group): void {
                $c->make(CoSlowSingleton::class, ['n' => 1]);   // not cacheable
                $group->done();
            });

            Coroutine::sleep(0.02);                             // let it start and suspend

            $group->add();
            Coroutine::create(function () use ($c, $group, &$waited): void {
                $started = microtime(true);
                $c->make(CoSlowSingleton::class);
                $waited = microtime(true) - $started;
                $group->done();
            });

            $group->wait();
        });

        // Its own build and nothing more — waiting for the overrides build would roughly
        // double this.
        self::assertLessThan(CoSlowSingleton::BUILD_SECONDS * 1.5, $waited);
    }

    /**
     * Two builds of the same singleton can overlap — one with overrides, one after a failed
     * attempt — and the second replaces the first in the map of pending builds. Whoever was
     * already waiting on the first channel must still be woken by its owner, not left to
     * time out on a channel nobody closes.
     */
    public function test_a_waiter_is_never_left_behind_by_a_replaced_build(): void
    {
        $waited = 0.0;

        $this->inCoroutines(function (Container $c) use (&$waited): void {
            $group = new WaitGroup();

            $group->add();
            Coroutine::create(function () use ($c, $group): void {
                $c->make(CoSlowSingleton::class, ['n' => 1]);
                $group->done();
            });

            Coroutine::sleep(0.02);

            $group->add();
            Coroutine::create(function () use ($c, $group, &$waited): void {
                $started = microtime(true);
                $c->make(CoSlowSingleton::class);
                $waited = microtime(true) - $started;
                $group->done();
            });

            Coroutine::sleep(0.02);

            $group->add();
            Coroutine::create(function () use ($c, $group): void {
                $c->make(CoSlowSingleton::class, ['n' => 2]);   // would replace the entry
                $group->done();
            });

            $group->wait();
        });

        // Left behind, it would sleep out the five-second bound instead.
        self::assertLessThan(1.0, $waited);
    }

    public function test_a_real_cycle_is_still_detected_inside_a_coroutine(): void
    {
        $caught = null;

        $this->inCoroutines(function (Container $c) use (&$caught): void {
            try {
                $c->make(CoCycleA::class);
            } catch (ContainerException $e) {
                $caught = $e->getMessage();
            }
        });

        self::assertNotNull($caught, 'a genuine cycle must still fail');
        self::assertStringContainsString('Circular dependency', $caught);
    }

    public function test_the_cycle_message_shows_the_path(): void
    {
        try {
            Container::init()->make(CoCycleA::class);
            self::fail('expected a circular dependency');
        } catch (ContainerException $e) {
            // The chain is what makes the error actionable — which link to break.
            self::assertStringContainsString(CoCycleA::class . '] → [' . CoCycleB::class, $e->getMessage());
            self::assertStringEndsWith(CoCycleB::class . '] → [' . CoCycleA::class . '].', $e->getMessage());
        }
    }

    public function test_a_failed_singleton_build_does_not_hang_the_waiters(): void
    {
        $outcome = [];

        $this->inCoroutines(function (Container $c) use (&$outcome): void {
            $c->singleton(CoShared::class, static function () {
                Coroutine::sleep(0.02);
                CoShared::$builds++;
                throw new \RuntimeException('factory blew up');
            });

            $group = new WaitGroup();

            foreach ([1, 2] as $n) {
                $group->add();
                Coroutine::create(function () use ($c, $n, $group, &$outcome): void {
                    try {
                        $c->make(CoShared::class);
                        $outcome[$n] = 'ok';
                    } catch (\Throwable $e) {
                        $outcome[$n] = $e->getMessage();
                    } finally {
                        $group->done();
                    }
                });
            }

            $group->wait();
        });

        // The waiter is woken by the failed builder and retries on its own, so it sees
        // the real error rather than waiting out the bound or hanging forever.
        self::assertSame([1 => 'factory blew up', 2 => 'factory blew up'], $outcome);
        self::assertSame(2, CoShared::$builds, 'the waiter retried instead of inheriting the failure');
    }
}
