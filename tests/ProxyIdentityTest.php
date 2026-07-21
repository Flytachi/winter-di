<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Lazy;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\ProxyInterface;
use PHPUnit\Framework\TestCase;

// ── Fixtures ──────────────────────────────────────────────────────────────────

interface PidWriterInterface { public function write(string $m): void; }

class PidWriter implements PidWriterInterface
{
    public function write(string $m): void {}
}

/** Records which class it was built for, so contextual() resolution can be asserted. */
class PidConsumerWriter implements PidWriterInterface
{
    public function __construct(public readonly ?string $consumer = null) {}
    public function write(string $m): void {}
}

class PidDependency
{
    public int $id;
    public function __construct() { $this->id = random_int(1, PHP_INT_MAX); }
}

// Inherited private injection — the property is invisible to the child's getProperties().
class PidPrivateBase
{
    #[Autowired]
    private ?PidDependency $hidden = null;

    #[Autowired]
    protected ?PidDependency $visible = null;

    public function hidden(): ?PidDependency { return $this->hidden; }
    public function visible(): ?PidDependency { return $this->visible; }
}

class PidPrivateChild extends PidPrivateBase
{
    #[Autowired]
    protected ?PidDependency $own = null;

    public function own(): ?PidDependency { return $this->own; }
}

// Redeclaration — the most derived declaration must win.
class PidRedeclaredBase
{
    #[Inject(PidWriter::class)]
    protected ?PidWriterInterface $writer = null;

    public function writer(): ?PidWriterInterface { return $this->writer; }
}

class PidRedeclaredChild extends PidRedeclaredBase
{
    #[Inject(PidConsumerWriter::class)]
    protected ?PidWriterInterface $writer = null;
}

// Lazy on an inherited private property.
class PidLazyBase
{
    #[Lazy]
    private ?PidDependency $deferred = null;

    public function deferred(): ?PidDependency { return $this->deferred; }
}

class PidLazyChild extends PidLazyBase
{
}

// Proxy identity — property injection.
class PidTarget
{
    #[Autowired]
    private ?PidWriterInterface $writer = null;

    public function writer(): ?PidWriterInterface { return $this->writer; }
}

final class PidTargetProxy extends PidTarget implements ProxyInterface
{
    public static function proxyTarget(): string { return PidTarget::class; }
}

// Proxy identity — constructor injection.
class PidCtorTarget
{
    public function __construct(public readonly PidWriterInterface $writer) {}
}

final class PidCtorTargetProxy extends PidCtorTarget implements ProxyInterface
{
    public static function proxyTarget(): string { return PidCtorTarget::class; }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

final class ProxyIdentityTest extends TestCase
{
    private Container $c;

    protected function setUp(): void
    {
        $this->c = Container::init();
    }

    public function testInheritedPrivatePropertyIsInjected(): void
    {
        $service = $this->c->make(PidPrivateChild::class);

        $this->assertInstanceOf(PidDependency::class, $service->hidden());
        $this->assertInstanceOf(PidDependency::class, $service->visible());
        $this->assertInstanceOf(PidDependency::class, $service->own());
    }

    public function testPrivatePropertyIsInjectedOnTheDeclaringClassItself(): void
    {
        $service = $this->c->make(PidPrivateBase::class);

        $this->assertInstanceOf(PidDependency::class, $service->hidden());
    }

    public function testRedeclaredPropertyUsesTheMostDerivedDeclaration(): void
    {
        $service = $this->c->make(PidRedeclaredChild::class);

        $this->assertInstanceOf(PidConsumerWriter::class, $service->writer());
    }

    public function testInheritedLazyPropertyIsProxied(): void
    {
        $service = $this->c->make(PidLazyChild::class);
        $deferred = $service->deferred();

        $this->assertInstanceOf(PidDependency::class, $deferred);
        $this->assertTrue((new \ReflectionClass(PidDependency::class))->isUninitializedLazyObject($deferred));
        $this->assertGreaterThan(0, $deferred->id);
    }

    public function testProxyReceivesTheTargetsPrivateProperties(): void
    {
        $this->c->bind(PidWriterInterface::class, PidWriter::class);
        $this->c->bind(PidTarget::class, PidTargetProxy::class);

        $service = $this->c->make(PidTarget::class);

        $this->assertInstanceOf(PidTargetProxy::class, $service);
        $this->assertInstanceOf(PidWriter::class, $service->writer());
    }

    public function testContextualFactorySeesTheTargetNotTheProxyOnProperties(): void
    {
        $this->c->contextual(
            PidWriterInterface::class,
            fn(Container $c, ?string $consumer) => new PidConsumerWriter($consumer)
        );
        $this->c->bind(PidTarget::class, PidTargetProxy::class);

        /** @var PidConsumerWriter $writer */
        $writer = $this->c->make(PidTarget::class)->writer();

        $this->assertSame(PidTarget::class, $writer->consumer);
    }

    public function testContextualFactorySeesTheTargetNotTheProxyOnConstructor(): void
    {
        $this->c->contextual(
            PidWriterInterface::class,
            fn(Container $c, ?string $consumer) => new PidConsumerWriter($consumer)
        );
        $this->c->bind(PidCtorTarget::class, PidCtorTargetProxy::class);

        /** @var PidConsumerWriter $writer */
        $writer = $this->c->make(PidCtorTarget::class)->writer;

        $this->assertSame(PidCtorTarget::class, $writer->consumer);
    }

    public function testPlainClassStillReportsItself(): void
    {
        $this->c->contextual(
            PidWriterInterface::class,
            fn(Container $c, ?string $consumer) => new PidConsumerWriter($consumer)
        );

        /** @var PidConsumerWriter $writer */
        $writer = $this->c->make(PidTarget::class)->writer();

        $this->assertSame(PidTarget::class, $writer->consumer);
    }
}
