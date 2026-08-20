<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Exception\ContainerException;
use Flytachi\Winter\DI\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;

// ── Fixtures ──────────────────────────────────────────────────────────────────

interface PaymentGateway
{
    public function charge(int $amount): void;
}

abstract class AbstractJob
{
    abstract public function run(): void;
}

trait CountsCalls
{
    private int $calls = 0;
}

enum Colour
{
    case Red;
    case Blue;
}

final class PrivatelyBuilt
{
    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }
}

/**
 * What the container says when it cannot build something.
 *
 * Two things are pinned here, and the first is a contract rather than a nicety: every
 * failure has to arrive as a PSR-11 exception. `class_exists()` answers true for an
 * abstract class and for an enum, so both used to walk past the container's own check
 * and die inside `new` — as a raw `\Error`, which is not a `ContainerExceptionInterface`.
 * Code that wrapped its resolution in `catch (ContainerExceptionInterface)` never saw
 * them.
 *
 * The second is the message. All of these reach the container through one door, and they
 * are fixed in entirely different places — an interface needs a binding, a missing class
 * needs composer. One sentence for all of them sends people looking in the wrong place;
 * this suite is what keeps them apart.
 */
final class ContainerDiagnosticsTest extends TestCase
{
    private Container $c;

    protected function setUp(): void
    {
        $this->c = Container::init();
    }

    // ── Everything lands inside PSR-11 ────────────────────────────────────────

    /**
     * @param string $id An identifier the container cannot turn into an object.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvable')]
    public function test_every_failure_is_a_psr11_exception(string $id): void
    {
        try {
            $this->c->make($id);
            self::fail("Resolving [{$id}] should have failed.");
        } catch (\Throwable $e) {
            self::assertInstanceOf(
                ContainerExceptionInterface::class,
                $e,
                'a caller catching ContainerExceptionInterface must not be walked past',
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvable(): iterable
    {
        yield 'missing class'  => ['Acme\\Nothing\\Here'];
        yield 'interface'      => [PaymentGateway::class];
        yield 'trait'          => [CountsCalls::class];
        yield 'abstract class' => [AbstractJob::class];
        yield 'enum'           => [Colour::class];
        yield 'private ctor'   => [PrivatelyBuilt::class];
    }

    // ── Not found: nothing to build ───────────────────────────────────────────

    /** The most expensive misreading: this is composer's problem, not the container's. */
    public function test_a_missing_class_says_so_and_points_at_the_autoloader(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Class [Acme\\Nothing\\Here] not found');
        $this->expectExceptionMessage('autoloader');

        $this->c->make('Acme\\Nothing\\Here');
    }

    public function test_an_interface_is_told_to_be_bound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('is an interface and has no binding');

        $this->c->make(PaymentGateway::class);
    }

    /** `class_exists()` is false for a trait too, so it arrives through the same door. */
    public function test_a_trait_is_named_as_a_trait(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('is a trait and cannot be resolved');

        $this->c->make(CountsCalls::class);
    }

    // ── Container error: found, but not buildable ─────────────────────────────

    public function test_an_abstract_class_is_a_container_error(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('is abstract and cannot be instantiated');

        $this->c->make(AbstractJob::class);
    }

    public function test_an_enum_is_a_container_error(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('is an enum and cannot be instantiated');

        $this->c->make(Colour::class);
    }

    public function test_a_non_public_constructor_is_reported_as_such(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('its constructor is not public');

        $this->c->make(PrivatelyBuilt::class);
    }

    // ── What must keep working ────────────────────────────────────────────────

    public function test_a_bound_interface_still_resolves(): void
    {
        $this->c->bind(PaymentGateway::class, StubGateway::class);

        self::assertInstanceOf(StubGateway::class, $this->c->make(PaymentGateway::class));
    }

    public function test_an_abstract_class_bound_to_a_concrete_one_resolves(): void
    {
        $this->c->bind(AbstractJob::class, RealJob::class);

        self::assertInstanceOf(RealJob::class, $this->c->make(AbstractJob::class));
    }

    /** A factory is the way past a constructor the container cannot call. */
    public function test_a_factory_still_reaches_a_private_constructor(): void
    {
        $this->c->bind(PrivatelyBuilt::class, static fn(): PrivatelyBuilt => PrivatelyBuilt::create());

        self::assertInstanceOf(PrivatelyBuilt::class, $this->c->make(PrivatelyBuilt::class));
    }

    /**
     * The guard runs before construction on purpose: a `TypeError` raised by the
     * constructor's own body is the application's bug, and relabelling it as a container
     * failure would bury the only message that says where it is.
     */
    public function test_a_constructor_that_throws_is_left_alone(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->c->make(ExplodingService::class);
    }
}

final class StubGateway implements PaymentGateway
{
    public function charge(int $amount): void
    {
    }
}

final class RealJob extends AbstractJob
{
    public function run(): void
    {
    }
}

final class ExplodingService
{
    public function __construct()
    {
        throw new \RuntimeException('boom');
    }
}
