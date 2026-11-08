# Extension API 1 (unreleased)

This fork tracks upstream 1.3.2; it contains no integration-specific code.
API version `1` is an integer, independent of the app/package version.
The API is experimental until the full validation and publication gates pass.

## Registration

A separate Nextcloud app implements `IBootstrap`. In `register()`, call:

```php
$context->registerEventListener(
    \OCA\Mcp\Extension\RegistrationEvent::class,
    \OCA\Example\Mcp\RegistrationListener::class,
);
```

The listener implements `IEventListener`, injects its `ToolModule` using
Nextcloud DI, and registers this descriptor in `handle()`:

```php
$event->register(new \OCA\Mcp\Extension\Extension(
    1, 'example', 'example', 'Example',
    ['read' => 'Read owned objects.', 'create' => 'Create owned objects.'],
    $this->tools,
));
```

Use a stable module ID equal to the owning app ID or prefixed with `app_`.
Every tool name must start with `module_`. Operation identifiers are lowercase
and may contain dots; `grant_MODULE_OPERATION` must fit the 64-byte config key
limit. Definitions need `name`, `description`, `module`, `operation`, and an
object `inputSchema`. The owning `app` is injected, never optional in the
published registration. Alternative `grantAnyOf` is deliberately unsupported
in API 1; use distinct tools/operations instead. Supported argument validation
is upstream's subset plus finite numbers with inclusive/exclusive bounds,
not all of JSON Schema. Metadata is plain text, rendered as text, never HTML.

The event is dispatched lazily, once per request, after bootstrap registration.
Definitions are validated atomically and frozen. Registering a duplicate module,
tool, upstream module, foreign app/tool, invalid descriptor or incompatible API
throws an explicit exception. Production logs registration failure and fails
the request instead of silently advertising a partial catalog. Recursive or
late registration is an error. No provider may replace an upstream tool.

## Security contract

**Every extension operation is denied by default, including read. API 1 has no
opt-out.** Explicit per-user/per-group grants use existing policy storage,
revision control and audit behavior; app disablement does not erase the grants.
The owning app must be enabled for the actual authenticated user. Core
eligibility, connection/service gates, Guests restrictions and operation grants
remain authoritative. A provider's optional `RestrictedModule` gate and audit
are also preserved; it may only narrow access.

Only `read` is read-only. Every other operation uses the core confirmation
schema and gate; annotations cannot bypass it. API 1 deliberately uses the core
generic preview without invoking the provider's call/preview handlers.
Optional restriction gates still run and must not mutate data.
The provider receives the authenticated UID, never a UID chosen in arguments,
and must check native ACLs/ownership and explicitly configured delegation.
Confirming is not an ACL bypass or a promise of transactional rollback.
Guide notes and external `openWorldHint` metadata are supported.

Installed PHP apps are trusted server-side code, **not sandboxed plugins**.
A malicious app can perform side effects or lie about its `read` operation;
this API cannot contain such an app. Review/approve code before installation.
MCP gating protects compliant modules, not hostile PHP running in Nextcloud.

## Administration and compatibility

Extensions join all policy read/write, group, preview, inspector and effective
grant catalogs via the Access metadata port. The matrix and group editor show
their labels, operations and default denial. Advanced operation controls retain
independent grants; selecting a bundled level is an explicit administrator act.
Unknown extensions retain stored grants without acquiring access while absent.
Resource scopes specific to Files/Calendar/etc. are not offered to extensions;
resource ACL enforcement belongs to the provider.

Keep API 1 usable when introducing a later API, or declare a tested incompatibility
with a migration and rollback plan; never interpret an unknown API as version 1.
Provider dependency on this API is optional for its non-MCP features: register
the listener without loading MCP classes during app boot. Thus background jobs
can continue when MCP is disabled. Installation of MCP features requires this
fork (or a future upstream offering the exact API), not unmodified MCP 1.3.2.

## Validation

Run `composer install` in `mcp`, then:

```sh
vendor/bin/phpunit tests/Unit/Extension tests/Unit/Mcp/Tools/Validation tests/Unit/Access/Grants
php scripts/build_assets.php
```

Run the existing upstream full CI too. Registration tests cover collisions,
API errors, atomicity, default denial, legacy grants, identity, app and guest
gates, confirmation and previews. Provider suites must additionally prove
ownership, allowed/denied calls, revocation and zero preview side effects.
