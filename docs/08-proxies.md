# Proxies

A **proxy** is a generated class that stands in for another one. It extends the
original, overrides some of its methods to add behaviour around them, and is
registered in the container under the original's name — so consumers keep
asking for `UserService` and keep receiving something that *is* a `UserService`.

Frameworks use this to add behaviour a class did not write itself: running a
method in the background, wrapping it in a transaction, caching its result.
Winter's `#[Async]` attribute works exactly this way.

**The problem.** Once the container hands out `UserService__Async` instead of
`UserService`, `$instance::class` no longer reports what the application wrote.
That name is not cosmetic — the resolver uses it to decide *for whom* a
dependency is being built. A [`contextual()`](02-container.md#contextualstring-abstract-callable-factory-static)
logger factory would name the channel after the generated class, and log lines
would suddenly read `App\Proxy\Generated\UserService__Async` instead of
`App\Service\UserService`.

**The solution.** A proxy declares the class it replaces. The resolver then
treats that class as the instance's identity: injection is planned from it, and
it is what `contextual()` factories receive as the consumer.

---

## `ProxyInterface`

```php
use Flytachi\Winter\DI\Contract\ProxyInterface;
```

```php
interface ProxyInterface
{
    /** @return class-string */
    public static function proxyTarget(): string;
}
```

The method is **static** on purpose — the resolver needs the identity before an
instance exists, while building constructor arguments.

---

## Writing a proxy

A proxy is expected to **extend** its target, so it stays type-compatible with
everything that depends on it:

```php
final class UserServiceProxy extends UserService implements ProxyInterface
{
    public static function proxyTarget(): string
    {
        return UserService::class;
    }

    public function notify(int $id): void
    {
        // …behaviour around the original…
        parent::notify($id);
    }
}
```

Register it under the target's name, keeping the intended scope:

```php
$container->singleton(UserService::class, UserServiceProxy::class);
```

From here on `make(UserService::class)` returns the proxy, `instanceof
UserService` holds, and the container reports `UserService` as the consumer.

> Because there is only one object — the proxy *is* the instance — a call from
> one method of the service to another goes through the override as well. That
> is a property of subclass proxying; delegation-based proxies (a wrapper
> holding a separate target) do not have it.

---

## What changes for a proxied class

| | Without `ProxyInterface` | With `ProxyInterface` |
|---|---|---|
| `contextual()` consumer | generated class name | target class name |
| Injection plan built from | generated class | target class |
| `instanceof Target` | true (proxy extends it) | true |
| `$instance::class` | generated name | generated name (unchanged) |

`$instance::class` deliberately keeps reporting the truth — the interface
changes how the *container* reasons about identity, not what PHP reports.

---

## Property injection and inheritance

`ReflectionClass::getProperties()` does **not** list private properties declared
in a parent class. The resolver therefore walks the class hierarchy explicitly
when planning injection, so an inherited private dependency is filled in:

```php
abstract class BaseService
{
    #[Autowired]
    private Mailer $mailer;          // injected, even from a subclass
}

class UserService extends BaseService {}
```

This matters doubly for proxies: the target's private properties are invisible
from the generated subclass, and without the hierarchy walk they would silently
stay `null`.

When a property is redeclared in a subclass, the most derived declaration wins —
the same rule PHP itself applies.

---

## Cost

The injection plan — which properties to fill, with what, eagerly or lazily — is
computed **once per class** and reused for the process lifetime, alongside the
constructor metadata cached by [`ReflectionCache`](07-reflection-cache.md).
Attributes are read at plan time, not on every resolution, so repeated
`make()` calls only pay for the actual `setValue()` and dependency lookups.
