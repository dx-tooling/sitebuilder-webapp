# Architecture Book

This document defines the reference architecture for ETFS-based Symfony applications.  The architecture is centered around feature verticals and their facades, with strict boundary enforcement and Cursor rules as additional constraints.

## Core Concept: Verticals (Feature Modules)

The codebase is organized by verticals (feature modules). Each top-level folder under `src/` is a vertical with its own bounded context and internal layers.

Typical vertical layout:

```
src/
└── FeatureName/
    ├── Domain/           # Pure business logic
    ├── Facade/           # Public API for other verticals (Port)
    ├── Infrastructure/   # External systems (Adapter)
    ├── Api/              # HTTP API (Adapter)
    └── Presentation/     # Web UI (Adapter)
```

Notes:
- Some verticals may omit layers they do not need (e.g. no `Api/`).
- `Common/` is a shared vertical used for cross-cutting concerns and is explicitly excluded from vertical boundary tests.

## Facades: The Port Between Verticals

Facades are the only sanctioned integration point between verticals.

- Each vertical exposes **interfaces** and **DTOs** in `Facade/` that represent its public API to other verticals.
- Facade implementations orchestrate domain services but present stable, simplified contracts to callers.
- Controllers and services inside the same vertical **should use Domain services directly**; facades are intended for *cross-vertical* use only.

Example (domain service in Foo using Bar facade):

```
App\Foo\Domain\FooDomainService
  -> uses App\Bar\Facade\BarFacadeInterface
```

This keeps inter-vertical dependencies explicit, stable, and testable.

## Dependency Rules and Boundary Enforcement

Verticals are isolated from each other’s internal layers. The architecture test enforces that one vertical must not reference another vertical’s internal `Api`, `Domain`, `Infrastructure`, or `Presentation` namespaces.

Implication for ETFS apps:

- Cross-vertical dependencies must go through **Facade** interfaces.
- Direct coupling between internal layers of different verticals is forbidden.

## Layer Responsibilities (Recap)

- **Facade**: Interfaces + DTOs + thin orchestration for other verticals.
- **Domain**: Entities, enums/value objects, domain services, repositories. Pure business logic.
- **Infrastructure**: External integrations, data providers, adapters.
- **Api**: JSON/HTTP endpoints, serialization, request/response DTOs.
- **Presentation**: Symfony controllers, UI services, Twig templates, UX components.

## Cursor Rules Alignment (ETFS App Starter Kit)

The architecture also follows the project rules in `.cursorrules`:

- **Strict typing and PHP 8.4**: All code uses `declare(strict_types=1);` and strict types.
- **SOLID, DI-first**: Services are injected via the container; avoid static/global access.
- **Layered feature namespaces**: `App\FeatureName\{Domain,Presentation,Api,Infrastructure,TestHarness}`.
- **DTO-first data flow**: Avoid associative arrays for data transfer; prefer typed DTOs.
- **Exceptions and logging**: Use Symfony exceptions, logging, and validation facilities.
- **Database access in Domain**: Allowed for SQL access; use raw SQL for complex queries and
  `EntityManager` for simple cases. Never hardcode table names.
- **No manual migrations**: Model entities; migrations are generated externally.
- **Multibyte-safe strings**: Use `mb_*` functions where applicable.
- **Date/time creation**: Use `DateAndTimeService` instead of `DateTimeImmutable`.

## Client-Side Organization

Client-side Stimulus code is colocated with verticals under:

```
src/FeatureName/Presentation/Resources/assets/controllers/
```

This keeps frontend code aligned with the same vertical boundaries.

## Practical Guidance for ETFS App Starter Kit

- Keep all new features as their own verticals in `src/<FeatureName>/`.
- Add a `Facade` only when another vertical needs access.
- Enforce boundary rules with the same architecture test pattern.
- Keep `Common/` small and for truly cross-cutting concerns only.
- Prefer DTOs for all cross-layer and cross-vertical data transfer.
