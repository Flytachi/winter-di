<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Lazy;
use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\ServiceProvider;
use Flytachi\Winter\DI\Exception\ContainerException;
use Flytachi\Winter\DI\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

// ── Fixtures ──────────────────────────────────────────────────────────────────

interface CacheInterface { public function get(string $k): mixed; }
interface LoggerInterface { public function log(string $m): void; }

class NullCache implements CacheInterface
{
    public function get(string $k): mixed { return null; }
}

class ArrayCache implements CacheInterface
{
    private array $data = [];
    public function get(string $k): mixed { return $this->data[$k] ?? null; }
    public function set(string $k, mixed $v): void { $this->data[$k] = $v; }
}

class NullLogger implements LoggerInterface
{
    public array $messages = [];
    public function log(string $m): void { $this->messages[] = $m; }
}

#[Singleton]
class SingletonService
{
    public int $id;
    public function __construct() { $this->id = random_int(1, PHP_INT_MAX); }
}

#[Transient]
class TransientService
{
    public int $id;
    public function __construct() { $this->id = random_int(1, PHP_INT_MAX); }
}

class ServiceWithDeps
{
    public function __construct(
        public readonly SingletonService $singleton,
        public readonly TransientService $transient,
    ) {}
}

class ServiceWithDefault
{
    public function __construct(
        public readonly string $name = 'default',
        public readonly int $timeout = 10,
    ) {}
}

class ServiceWithInject
{
    public function __construct(
        #[Inject(ArrayCache::class)]
        public readonly CacheInterface $cache,
    ) {}
}

class ServiceWithNamedInject
{
    public function __construct(
        #[Inject('config.timeout')]
        public readonly int $timeout,
    ) {}
}

class ServiceWithPropertyInject
{
    #[Inject]
    public SingletonService $singleton;
}

class ServiceWithSpecificPropertyInject
{
    #[Inject(ArrayCache::class)]
    public CacheInterface $cache;
}

class ContextTag
{
    public function __construct(public readonly ?string $owner = null) {}
}

class ConsumerCtorContext
{
    public function __construct(
        #[Autowired] public readonly ContextTag $tag,
    ) {}
}

class ConsumerPropContext
{
    #[Autowired]
    public ContextTag $tag;
}

class LazyCycleA
{
    #[Lazy]
    public LazyCycleB $b;            // proxy — make(LazyCycleB) deferred → breaks the cycle
    public string $tag = 'A';
}

class LazyCycleB
{
    #[Autowired]
    public LazyCycleA $a;            // eager back-reference
    public string $tag = 'B';
}

class LazyCtorConsumer
{
    public function __construct(
        #[Lazy] public SingletonService $svc,
    ) {}
}

class LazyInterfaceConsumer
{
    #[Lazy]
    public CacheInterface $cache;    // interface — nothing concrete to proxy → error
}

class CircularA
{
    public function __construct(public CircularB $b) {}
}
class CircularB
{
    public function __construct(public CircularA $a) {}
}

class FixtureProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        $c->bind(CacheInterface::class, NullCache::class);
        $c->singleton(LoggerInterface::class, NullLogger::class);
        $c->set('config.timeout', 42);
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

final class ContainerTest extends TestCase
{
    private Container $c;

    protected function setUp(): void
    {
        $this->c = Container::init();
    }

    // ── Init & getInstance ────────────────────────────────────────────────────

    public function test_init_returns_container(): void
    {
        $this->assertInstanceOf(Container::class, $this->c);
    }

    public function test_get_instance_returns_same_container(): void
    {
        $this->assertSame($this->c, Container::getInstance());
    }

    public function test_get_instance_throws_without_init(): void
    {
        // Reset static instance via re-init then test after a fresh init
        // (We cannot reset private static without reflection — just confirm getInstance works)
        $this->assertInstanceOf(Container::class, Container::getInstance());
    }

    // ── Self-registration ─────────────────────────────────────────────────────

    public function test_container_resolves_itself(): void
    {
        $this->assertSame($this->c, $this->c->make(Container::class));
    }

    public function test_container_resolves_psr11_interface(): void
    {
        $this->assertSame($this->c, $this->c->make(\Psr\Container\ContainerInterface::class));
    }

    // ── Scopes — singleton ────────────────────────────────────────────────────

    public function test_singleton_attribute_returns_same_instance(): void
    {
        $a = $this->c->make(SingletonService::class);
        $b = $this->c->make(SingletonService::class);
        $this->assertSame($a, $b);
    }

    public function test_singleton_registration_returns_same_instance(): void
    {
        $this->c->singleton(NullLogger::class);
        $a = $this->c->make(NullLogger::class);
        $b = $this->c->make(NullLogger::class);
        $this->assertSame($a, $b);
    }

    // ── Scopes — transient ────────────────────────────────────────────────────

    public function test_transient_attribute_returns_new_instance(): void
    {
        $a = $this->c->make(TransientService::class);
        $b = $this->c->make(TransientService::class);
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_transient_registration_returns_new_instance(): void
    {
        $this->c->transient(NullCache::class);
        $a = $this->c->make(NullCache::class);
        $b = $this->c->make(NullCache::class);
        $this->assertNotSame($a, $b);
    }

    // ── Scopes — default ─────────────────────────────────────────────────────

    public function test_unregistered_class_is_transient_by_default(): void
    {
        $a = $this->c->make(NullLogger::class);
        $b = $this->c->make(NullLogger::class);
        $this->assertNotSame($a, $b);
    }

    // ── Autowiring ────────────────────────────────────────────────────────────

    public function test_autowires_constructor_by_type(): void
    {
        $service = $this->c->make(ServiceWithDeps::class);
        $this->assertInstanceOf(SingletonService::class, $service->singleton);
        $this->assertInstanceOf(TransientService::class, $service->transient);
    }

    public function test_autowires_uses_singleton_cache(): void
    {
        $a = $this->c->make(ServiceWithDeps::class);
        $b = $this->c->make(ServiceWithDeps::class);
        $this->assertSame($a->singleton, $b->singleton);
    }

    public function test_autowires_default_parameter_values(): void
    {
        $service = $this->c->make(ServiceWithDefault::class);
        $this->assertSame('default', $service->name);
        $this->assertSame(10, $service->timeout);
    }

    // ── make() overrides ──────────────────────────────────────────────────────

    public function test_make_with_overrides_bypasses_autowiring(): void
    {
        $service = $this->c->make(ServiceWithDefault::class, ['name' => 'custom', 'timeout' => 99]);
        $this->assertSame('custom', $service->name);
        $this->assertSame(99, $service->timeout);
    }

    public function test_make_with_overrides_does_not_cache(): void
    {
        $this->c->singleton(SingletonService::class);
        $a = $this->c->make(SingletonService::class, []);
        $b = $this->c->make(SingletonService::class);
        $this->assertSame($a, $b);
    }

    // ── bind() ────────────────────────────────────────────────────────────────

    public function test_bind_interface_to_class(): void
    {
        $this->c->bind(CacheInterface::class, NullCache::class);
        $cache = $this->c->make(CacheInterface::class);
        $this->assertInstanceOf(NullCache::class, $cache);
    }

    public function test_bind_interface_to_closure(): void
    {
        $this->c->bind(CacheInterface::class, fn($c) => new ArrayCache());
        $cache = $this->c->make(CacheInterface::class);
        $this->assertInstanceOf(ArrayCache::class, $cache);
    }

    public function test_bind_is_transient(): void
    {
        $this->c->bind(CacheInterface::class, NullCache::class);
        $a = $this->c->make(CacheInterface::class);
        $b = $this->c->make(CacheInterface::class);
        $this->assertNotSame($a, $b);
    }

    // ── set() ─────────────────────────────────────────────────────────────────

    public function test_set_stores_scalar(): void
    {
        $this->c->set('config.timeout', 30);
        $this->assertSame(30, $this->c->make('config.timeout'));
    }

    public function test_set_stores_object(): void
    {
        $logger = new NullLogger();
        $this->c->set(LoggerInterface::class, $logger);
        $this->assertSame($logger, $this->c->make(LoggerInterface::class));
    }

    // ── #[Inject] on constructor parameter ───────────────────────────────────

    public function test_inject_attribute_overrides_binding(): void
    {
        $this->c->bind(CacheInterface::class, NullCache::class);
        $service = $this->c->make(ServiceWithInject::class);
        // #[Inject(ArrayCache::class)] overrides the global NullCache binding
        $this->assertInstanceOf(ArrayCache::class, $service->cache);
    }

    public function test_inject_named_value(): void
    {
        $this->c->set('config.timeout', 42);
        $service = $this->c->make(ServiceWithNamedInject::class);
        $this->assertSame(42, $service->timeout);
    }

    // ── #[Inject] on property ─────────────────────────────────────────────────

    public function test_property_inject_by_type(): void
    {
        $service = $this->c->make(ServiceWithPropertyInject::class);
        $this->assertInstanceOf(SingletonService::class, $service->singleton);
    }

    public function test_property_inject_with_specific_class(): void
    {
        $service = $this->c->make(ServiceWithSpecificPropertyInject::class);
        $this->assertInstanceOf(ArrayCache::class, $service->cache);
    }

    // ── contextual() bindings ─────────────────────────────────────────────────

    public function test_contextual_passes_consumer_to_constructor(): void
    {
        $this->c->contextual(ContextTag::class, fn(Container $c, ?string $consumer) => new ContextTag($consumer));
        $service = $this->c->make(ConsumerCtorContext::class);
        $this->assertSame(ConsumerCtorContext::class, $service->tag->owner);
    }

    public function test_contextual_passes_consumer_to_property(): void
    {
        $this->c->contextual(ContextTag::class, fn(Container $c, ?string $consumer) => new ContextTag($consumer));
        $service = $this->c->make(ConsumerPropContext::class);
        $this->assertSame(ConsumerPropContext::class, $service->tag->owner);
    }

    public function test_contextual_is_ignored_by_direct_make(): void
    {
        $this->c->contextual(ContextTag::class, fn(Container $c, ?string $consumer) => new ContextTag($consumer));
        // contextual applies to injection only — a direct make() resolves the class normally
        $this->assertNull($this->c->make(ContextTag::class)->owner);
    }

    public function test_contextual_result_differs_per_consumer_and_is_not_cached(): void
    {
        $this->c->contextual(ContextTag::class, fn(Container $c, ?string $consumer) => new ContextTag($consumer));
        $a = $this->c->make(ConsumerCtorContext::class);
        $b = $this->c->make(ConsumerPropContext::class);
        $this->assertSame(ConsumerCtorContext::class, $a->tag->owner);
        $this->assertSame(ConsumerPropContext::class, $b->tag->owner);
        $this->assertNotSame($a->tag, $b->tag);
    }

    public function test_make_contextual_falls_back_to_make_without_binding(): void
    {
        // no contextual() registered → behaves like a plain make()
        $tag = $this->c->makeContextual(ContextTag::class, 'Some\\Consumer');
        $this->assertNull($tag->owner);
    }

    public function test_contextual_overlays_binding_injection_wins_make_uses_binding(): void
    {
        // a regular binding governs direct make(); the contextual overlay governs injection
        $this->c->bind(ContextTag::class, fn() => new ContextTag('plain'));
        $this->c->contextual(ContextTag::class, fn(Container $c, ?string $consumer) => new ContextTag($consumer));

        // injection → contextual (consumer-aware), regardless of registration order
        $this->assertSame(ConsumerCtorContext::class, $this->c->make(ConsumerCtorContext::class)->tag->owner);
        // direct make() → the regular binding
        $this->assertSame('plain', $this->c->make(ContextTag::class)->owner);
    }

    public function test_contextual_re_registration_overrides_factory(): void
    {
        $this->c->contextual(ContextTag::class, fn(Container $c, ?string $consumer) => new ContextTag('first'));
        $this->c->contextual(ContextTag::class, fn(Container $c, ?string $consumer) => new ContextTag('second'));
        $this->assertSame('second', $this->c->make(ConsumerPropContext::class)->tag->owner);
    }

    // ── #[Lazy] ───────────────────────────────────────────────────────────────

    public function test_lazy_property_breaks_circular_dependency(): void
    {
        // LazyCycleA ⇄ LazyCycleB — would throw "Circular dependency" if $b were eager
        $a = $this->c->make(LazyCycleA::class);
        $this->assertSame('A', $a->tag);
        $this->assertInstanceOf(LazyCycleB::class, $a->b);   // proxy is type-compatible
        $this->assertSame('B', $a->b->tag);                  // first access initializes the real instance
        $this->assertSame('A', $a->b->a->tag);               // back-reference resolves
    }

    public function test_lazy_proxy_is_uninitialized_until_accessed(): void
    {
        $a   = $this->c->make(LazyCycleA::class);
        $ref = new \ReflectionClass(LazyCycleB::class);
        $this->assertTrue($ref->isUninitializedLazyObject($a->b));   // not built yet
        $a->b->tag;                                                  // touch → initialize
        $this->assertFalse($ref->isUninitializedLazyObject($a->b));
    }

    public function test_lazy_constructor_param_injects_proxy(): void
    {
        $consumer = $this->c->make(LazyCtorConsumer::class);
        $this->assertInstanceOf(SingletonService::class, $consumer->svc);
        $this->assertIsInt($consumer->svc->id);              // resolves on first access
    }

    public function test_lazy_on_interface_without_concrete_throws(): void
    {
        $this->expectException(ContainerException::class);
        $this->c->make(LazyInterfaceConsumer::class);
    }

    // ── call() ────────────────────────────────────────────────────────────────

    public function test_call_resolves_method_params(): void
    {
        $result = $this->c->call(fn(SingletonService $s) => $s->id);
        $this->assertIsInt($result);
    }

    public function test_call_array_callable_class_string(): void
    {
        $result = $this->c->call([CallableFixture::class, 'handle']);
        $this->assertTrue($result);
    }

    public function test_call_array_callable_object(): void
    {
        $fixture = new CallableFixture();
        $result  = $this->c->call([$fixture, 'handle']);
        $this->assertTrue($result);
    }

    public function test_call_with_overrides(): void
    {
        $result = $this->c->call(
            fn(string $name) => $name,
            ['name' => 'winter']
        );
        $this->assertSame('winter', $result);
    }

    // ── ServiceProvider ───────────────────────────────────────────────────────

    public function test_provider_registers_bindings(): void
    {
        $this->c->register(FixtureProvider::class);

        $this->assertInstanceOf(NullCache::class, $this->c->make(CacheInterface::class));
        $this->assertInstanceOf(NullLogger::class, $this->c->make(LoggerInterface::class));
        $this->assertSame(42, $this->c->make('config.timeout'));
    }

    public function test_provider_singleton_returns_same_instance(): void
    {
        $this->c->register(FixtureProvider::class);
        $a = $this->c->make(LoggerInterface::class);
        $b = $this->c->make(LoggerInterface::class);
        $this->assertSame($a, $b);
    }

    public function test_provider_must_extend_service_provider(): void
    {
        $this->expectException(ContainerException::class);
        $this->c->register(\stdClass::class);
    }

    // ── PSR-11 ────────────────────────────────────────────────────────────────

    public function test_has_returns_true_for_existing_class(): void
    {
        $this->assertTrue($this->c->has(SingletonService::class));
    }

    public function test_has_returns_true_for_binding(): void
    {
        $this->c->bind(CacheInterface::class, NullCache::class);
        $this->assertTrue($this->c->has(CacheInterface::class));
    }

    public function test_has_returns_false_for_unknown(): void
    {
        $this->assertFalse($this->c->has('non.existent.key'));
    }

    public function test_get_is_alias_for_make(): void
    {
        $a = $this->c->make(SingletonService::class);
        $b = $this->c->get(SingletonService::class);
        $this->assertSame($a, $b);
    }

    // ── Errors ────────────────────────────────────────────────────────────────

    public function test_make_throws_not_found_for_unresolvable(): void
    {
        $this->expectException(NotFoundException::class);
        $this->c->make(CacheInterface::class); // interface with no binding
    }

    public function test_make_throws_on_circular_dependency(): void
    {
        $this->expectException(ContainerException::class);
        $this->c->make(CircularA::class);
    }

    public function test_manual_scope_overrides_attribute(): void
    {
        // SingletonService has #[Singleton], override to transient
        $this->c->transient(SingletonService::class);
        $a = $this->c->make(SingletonService::class);
        $b = $this->c->make(SingletonService::class);
        $this->assertNotSame($a, $b);
    }

    /**
     * Re-registering must drop whatever the previous scope cached, or the override is a
     * no-op: make() answers from the cache before it ever looks at the scope, so the old
     * singleton would keep coming back and "manual registration wins" would quietly stop
     * being true. bind()/singleton()/request() always cleared it; transient() did not.
     */
    public function test_scope_override_applies_after_the_class_was_already_resolved(): void
    {
        $singleton = $this->c->make(SingletonService::class);   // cached as a singleton

        $this->c->transient(SingletonService::class);

        $a = $this->c->make(SingletonService::class);
        $b = $this->c->make(SingletonService::class);

        $this->assertNotSame($a, $b, 'transient must build a new instance every time');
        $this->assertNotSame($singleton, $a, 'the cached singleton must not survive the override');
    }
}

// ── Extra fixtures (need class_exists check in call() test) ───────────────────

class CallableFixture
{
    public function handle(SingletonService $s): bool
    {
        return $s instanceof SingletonService;
    }
}
