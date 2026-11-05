# Hook wiring: patterns, cost model, measured costs

## Contents

- Pattern catalog
- Cost model
- Measured costs
- How much hooks fire
- Decision procedure
- Review checklist for registration code

## Pattern catalog

| ID | Pattern | Example | Class loads | Graph built | Memoized | Removable |
|----|---------|---------|-------------|-------------|----------|-----------|
| W0 | Function name | `'acme_filter'` | file included at boot (functions do not autoload) | n/a | n/a | yes |
| W1 | Eager instance | `[$c->make(Foo::class), 'm']` at boot | boot | boot | yes | same object only |
| W2 | Static, stateless | `['Foo', 'm']`, no dependencies | first fire | never | n/a | yes |
| W3 | Lazy, rebuilt per fire | static `m()` calls `$c->make(self::class)` with a non-shared binding | first fire | every fire | no | yes |
| W4 | Lazy, static memo | `(self::$i ??= $c->make(self::class))->...` | first fire | first fire | yes | yes |
| W5 | Lazy, container singleton | static `m()` calls `$c->make(Foo::class)` with a shared binding | first fire | first fire | yes (lookup per fire) | yes |
| W6 | Closure proxy | `fn (...$a) => $c->make(Foo::class)->m(...$a)` | first fire | first fire if shared | if shared | no |
| W7 | Lazy object bound at boot (PHP 8.4+) | `[$rc->newLazyProxy(fn () => $c->make(Foo::class)), 'm']` | boot | first state access | yes | same object only |
| W8 | Gated registration | `add_action('admin_init', ['Feature', 'register'])` registers the rest | only where the gate fires | per inner pattern | per inner pattern | yes |
| W9 | First-class callable | `Foo::m(...)`, `Closure::fromCallable('Foo::m')` | boot (hidden) | per body | per body | no |
| W10 | Static facade over an instance | `Foo::m()` delegates to a container-held instance | first fire | first fire | yes | yes |

A PHP 8.4 lazy object (W7) still loads its class at creation because `ReflectionClass` needs it. It defers only the constructor and dependencies, and it initializes on first property access: a method that touches no state never initializes it.

## Cost model

Per callback, per request type:

| Symbol | Meaning |
|--------|---------|
| p | probability the hook fires at least once |
| n | mean fires when it fires |
| L | class-loading cost of the classes the callback needs |
| B | object-graph construction cost, including constructor work |
| D | dispatch cost per fire through `apply_filters` |
| W | the callback's own work per fire |
| I | per-fire indirection of a memoized lazy form |

| Pattern | Expected cost per request |
|---------|---------------------------|
| W1 eager | L + B + p·n·(D + W) |
| W2 static stateless | p·L + p·n·(D + W) |
| W3 rebuilt per fire | p·L + p·n·(B + D + W + I) |
| W4, W5, W10 memoized | p·(L + B) + p·n·(D + W + I) |
| W7 lazy object | L + p·B + p·n·(D + W) |

Weight request types by how many PHP-served requests and boots each gets per day, cron and WP-CLI boots included.

What follows from it:

- W3 wastes (n − 1)·B per request. With B around 18 µs and n = 1,000 (a `gettext`-family hook) that is about 18 ms from one callback.
- W1 on a rarely fired hook wastes (1 − p)·(L + B) on every request, and it adds up across hundreds of services and every cron boot.
- The break-even between memoized lazy and eager is n* = (1 − p)(L + B) / (p·I); in practice the difference is tens of microseconds either way. Do not churn always-fired hooks.
- Constructor I/O lands in B and usually dominates it.

## Measured costs

The reference lab ran PHP 8.5, WordPress 7.1.4's real hook API, warm OPcache with JIT off, on an Apple M2 Max. Use the ratios; re-measure absolutes on the target.

| Measurement | Result |
|-------------|--------|
| `apply_filters` on a hook with no callbacks | ~68 ns |
| One fire to `[$obj, 'm']` or `['Foo', 'm']` | ~405 ns |
| One fire to `'Foo::m'` | ~440 ns (the string is parsed on every call) |
| Extra per fire, static-property memo | ~+60–90 ns |
| Extra per fire, minimal-container singleton lookup | ~+140 ns |
| Extra per fire, illuminate/container singleton lookup | ~+340 ns |
| Extra per fire, PHP 8.4 lazy proxy | ~0 |
| Build an 8-object graph by hand | ~2 µs |
| Build it with a minimal reflection container | ~5 µs |
| Build it with illuminate/container | ~18 µs |
| Create a lazy proxy | ~0.07 µs |
| Load one class or interface, OPcache warm | ~0.4 µs |
| Load one class or interface, OPcache off | ~29 µs |
| 50 eager services whose hooks never fire | 0.4 ms (minimal container) / 1.0 ms (illuminate) plus ~0.4 MB peak, vs 0.02 ms lazy |
| 50 callbacks × 20 fires, rebuilt per fire (illuminate) | 14.6 ms, vs 1.5 ms memoized |
| `call_user_func_array`: string vs `[$obj]` vs `['Foo','m']` vs closure | 92 vs 70 vs 53 vs 32 ns |

## How much hooks fire

Vanilla WordPress 7.1.4 with a block theme, medians per request:

| Request type | Total hook fires | Distinct hooks | Attached callbacks never fired |
|--------------|------------------|----------------|--------------------------------|
| Single post | ~20,600 | ~735 | 62% |
| Archive | ~13,700 | ~626 | 63% |
| REST list | ~14,400 | ~527 | 71% |
| Block editor | ~44,300 | ~939 | 57% |
| admin-ajax | ~4,500 | ~297 | 72% |
| cron | ~4,400 | ~279 | 80% |

Hot hooks to treat as "fires hundreds or thousands of times":

- `gettext`, `gettext_with_context`, `ngettext` and their domain variants;
- `pre_option`, `pre_option_{name}`, `option_{name}`, `alloptions` (once per `get_option()`);
- `get_{type}_metadata`;
- `render_block`, `render_block_data`, `pre_render_block`, `render_block_{name}`;
- `the_title`, `the_content` in loops;
- `sanitize_key`, `sanitize_title`, `attribute_escape`, `esc_html`, `clean_url`, `set_url_scheme`;
- `map_meta_cap`, `user_has_cap`.

A plugin's own count can differ wildly; measure it.

## Decision procedure

1. Correctness first. Report these regardless of performance:
   - a string or array callback pointing at a non-static method (TypeError at first fire);
   - a bare class-name string (`'Foo'` is not callable);
   - required parameters above `accepted_args` (`ArgumentCountError`);
   - a filter callback that returns nothing.
2. n > 1 in any request type and the pattern is W3: memoize (W4, W5, W7 or W10).
3. p low in the dominant request types, and B large or the constructor does I/O, and the pattern is W1: go lazy (W4, W5, W7) or gate (W8).
4. p ≈ 1 and n large: any memoized form is fine. Only flag W3, or a per-fire cost W that blows a hot-hook budget (for example 0.5 ms per request).
5. No state and no dependencies: W2. Nothing to do.
6. Keep forms removable unless the team opts out. `['Foo','m']` and `'Foo::m'` share an ID, so switching between them is safe. Moving to a closure, a first-class callable or an instance changes how third parties remove the callback; say so.
7. W9 on a rarely fired hook: switch to the array form.
8. Weight cron and CLI by boots per day; something cheap per request can dominate across 100k cron boots.

## Review checklist for registration code

- [ ] Every class-method callback written as a string (`'Foo::m'`) or class-string array (`['Foo', 'm']`) points at a static method. Function names (W0) and object arrays (W1, W7) are fine as they are.
- [ ] No `Foo::m(...)` or `Closure::fromCallable()` on hooks that rarely fire.
- [ ] Lazy static entry points memoize what they build.
- [ ] Constructors of services built at boot do no I/O.
- [ ] Callbacks on hot hooks do constant, small work per fire; memoize lookups inside them.
- [ ] Hook names exist: a core hook or a `do_action` / `apply_filters` in the codebase (for example, `wp_init` is not a core hook; `init` is).
- [ ] Registration that is useless in cron, CLI or REST is gated behind a context hook, failing open.
