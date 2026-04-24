# Upgrading

## 0.4.x → 0.5.0 (upcoming)

v0.5.0 will introduce a **native Laravel AI gateway** for this package, ported from the unmerged [laravel/ai#405](https://github.com/laravel/ai/pull/405) design. This replaces the `PrismGateway` subclass approach used in 0.4.x.

**Why:** `laravel/ai` v0.6 removed `PrismGateway`. In 0.4.x on `laravel/ai ^0.6`, the service provider logs a one-time warning and disables the `agent()` bridge; Prism standalone (`Prism::text()->using('workers-ai', ...)`) keeps working. v0.5.0 restores `agent()->prompt(provider: 'workers-ai')` on `laravel/ai ^0.6+` via the new native gateway.

**Who is affected:**
- Ordinary users of `agent(provider: 'workers-ai')` or config-driven registration — **no action required**. Upgrade to v0.5.0, keep your `config/ai.php` block as-is.
- Users who **subclass** `PrismWorkersAi\LaravelAi\WorkersAiProvider` directly — **minor BC break**. The constructor signature changes to match the new native-gateway shape. Full before/after will land here when v0.5.0 ships.

**Migration path:**
- On `laravel/ai ^0.5`: stay on 0.4.x for now; 0.5.0 also supports ^0.5 so upgrade at your leisure.
- On `laravel/ai ^0.6`: 0.4.x works for Prism but disables the `agent()` bridge. Upgrade to 0.5.0 to restore full `agent()` support.

Details, code diffs, and test updates will be filled in here when v0.5.0 is tagged.

## 0.3.x → 0.4.x

No manual migration steps. All changes are additive (reasoning extraction, session affinity, prefix-cache metrics, `workersai` alias, default retry).
