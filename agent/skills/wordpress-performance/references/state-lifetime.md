# Static vs instance, and how long state lives

## The decision

| Shape of the code | Recommended form | Why |
|-------------------|------------------|-----|
| Pure logic, no state, no collaborators | Static method (W2) or namespaced function | Nothing to construct; call cost is the same as an instance call (±20 ns) |
| Request-cached, context-free data (a parsed static config file) | Static memo (W4) | Same cost as a singleton, less ceremony |
| Request-cached data derived from site, user or options | Memo keyed by `get_current_blog_id()` (and user if relevant), or reset on `switch_blog` | Plain statics leak across `switch_to_blog()` |
| Configured collaborators (HTTP clients from env and options, middleware toggles, API clients, repositories) | Lazily built, memoized instance (W5 or W7) | Same speed as a static memo, plus per-environment bindings, decoration, scoped resets, swaps in tests |
| Per-call state (the "current post" being processed) | Value object created per call | Shared services must not carry per-call state |

Call overhead is never the reason to choose static. Construction cost, laziness and memoization are.

## Static means process lifetime

On FPM web requests, process state and request state end together. They do not end together in these cases:

- **Core `wp-cron.php`** runs every due event in one process.
- **`wp cron event run --due-now --network`** loops over all sites with `switch_to_blog()` in one process.
- **Action Scheduler** runs 25 actions per batch in one request. It flushes WordPress's runtime cache between batches, not your statics.
- **Long WP-CLI commands** loop over posts, users or sites.
- **`switch_to_blog()`** switches the database tables and object-cache prefix and fires `switch_blog`, but leaves PHP statics and singletons alone. Even core has static caches reset on `switch_theme` but not `switch_blog`.
- **Persistent runtimes** (FrankenPHP worker mode, RoadRunner, Swoole, Octane) keep statics across requests. WordPress does not officially support worker mode; design for it anyway when it is cheap: keyed or resettable state.

## Patterns for safe memoization

```php
final class Settings
{
    /** @var array<int, array> keyed by blog ID so switch_to_blog() cannot leak values */
    private static array $byBlog = [];

    public static function get(): array
    {
        return self::$byBlog[get_current_blog_id()] ??= (array) get_option('acme_settings', []);
    }

    /** Call between Action Scheduler actions or CLI items when values may change. */
    public static function reset(): void
    {
        self::$byBlog = [];
    }
}
```

For container singletons that hold site-derived state, key the binding by blog ID or forget the instance on `switch_blog`. A Laravel-style `scoped` binding or a Symfony `ResetInterface` service resets only when the container is told a new request or job started, never on `switch_to_blog()`. Wire that reset to `switch_blog` yourself.

## Container defaults differ

Know whether `make()` / `get()` returns a new object, because a "lazy" callback over a non-shared binding is the rebuild-per-fire pattern (W3).

| Container | Default |
|-----------|---------|
| Laravel `illuminate/container` (`bind`), League, Spiral, lucatume/di52 | new instance per resolve unless bound as singleton or shared |
| Symfony DI, PHP-DI (`get`), Pimple, Laminas, Nette, Yii3, Aura, Syde modularity services | shared |
| WooCommerce internal container | single instance per class |

Laravel's own event dispatcher resolves class-based listeners on every dispatch unless they are singletons, a mainstream example of W3.
