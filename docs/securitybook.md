# Security Book

This document explains the security architecture of the sitebuilder application: how authentication works, how CSRF protection works, and why the configuration looks the way it does. The goal is to eliminate guesswork for developers maintaining or extending the application.


## Authentication: SecurityUser Boundary Pattern

The application does **not** use Symfony's built-in entity user provider. Instead, a custom `SecurityUserProvider` wraps the `AccountCore` Doctrine entity into a `SecurityUser` readonly DTO before handing it to the security system.

### Why

`AccountCore` is a Domain entity in the Account vertical. If `$this->getUser()` returned `AccountCore` directly, every vertical that needs the current user (Organization, ProjectManagement, etc.) would depend on `Account/Domain/Entity/AccountCore` -- violating vertical boundaries.

### How it works

1. `SecurityUserProvider` (`src/Account/Infrastructure/Security/SecurityUserProvider.php`) implements `UserProviderInterface`. It loads `AccountCore` from the database and maps it to a `SecurityUser`.
2. `SecurityUser` (`src/Common/Domain/Security/SecurityUser.php`) is a readonly DTO in `Common/` implementing `UserInterface` and `PasswordAuthenticatedUserInterface`. It exposes only what the security system and controllers need: id, email, roles, password hash, mustSetPassword, createdAt.
3. `config/packages/security.yaml` uses a service-based provider:

```yaml
providers:
    app_user_provider:
        id: App\Account\Infrastructure\Security\SecurityUserProvider
```

4. Controllers call `$this->getUser()` and get a `SecurityUser`. When they need Account-specific data (e.g. `currentlyActiveOrganizationId`), they go through the Account facade.

### Password upgrades

`SecurityUserProvider` also implements `PasswordUpgraderInterface`, so Symfony can transparently rehash passwords when the hashing algorithm changes -- without any controller involvement.


## CSRF Protection: Stateless Tokens

The application uses **stateless CSRF tokens** (Symfony 7.2+), not the traditional session-bound tokens. This is a fundamentally different protection model.

### Configuration

`config/packages/csrf.yaml`:

```yaml
framework:
    form:
        csrf_protection:
            token_id: submit

    csrf_protection:
        stateless_token_ids:
            - submit          # all Symfony forms
            - authenticate    # login form
            - logout          # logout action
            - chat_based_content_editor_run
            - accept_invitation
```

`config/packages/security.yaml` (on the `form_login` block):

```yaml
form_login:
    enable_csrf: true
```

### How stateless CSRF tokens work

Traditional (stateful) CSRF stores a random token in the session and validates it on submission. Stateless CSRF does not use the session at all. Instead, it relies on the browser's Same-Origin Policy through a layered defense:

#### Layer 1: Origin / Referer header check (primary)

When a form is submitted, the browser always sends an `Origin` header (or `Referer` as fallback). Symfony's `SameOriginCsrfTokenManager` compares this header against the application's own origin (`https://sitebuilder.dx-tooling.org` in production).

- **Same-origin request** (legitimate): `Origin: https://sitebuilder.dx-tooling.org` -- matches, allowed.
- **Cross-origin request** (attacker): `Origin: https://evil.com` -- does not match, rejected.
- **No Origin header**: rejected (with caveats for some browser edge cases where `Referer` is checked as fallback).

Browsers **do not allow** JavaScript to forge the `Origin` header, even via `fetch()` or `XMLHttpRequest`. This is enforced at the browser engine level and is not bypassable by client-side code.

#### Layer 2: Double-submit cookie (enhanced, requires JavaScript)

The file `assets/controllers/csrf_protection_controller.js` adds a second layer:

1. On page load, every form has a hidden field `<input name="_csrf_token" value="csrf-token">`. The value `"csrf-token"` is a **cookie name identifier**, not a secret.
2. When the form is submitted, the JavaScript intercepts the `submit` event and:
   - Stores `"csrf-token"` as the cookie name prefix.
   - Replaces the field value with a cryptographically random 24+ character Base64 token (generated via `window.crypto.getRandomValues`).
   - Sets a cookie: `__Host-csrf-token_<random-token>=csrf-token` with `SameSite=Strict` and `Secure`.
3. The server validates that the cookie and the submitted field value are consistent.

This "double-submit" pattern provides defense-in-depth because:

- An attacker on a different origin **cannot read or set** `__Host-` prefixed cookies for your domain.
- `SameSite=Strict` ensures the cookie is **never sent** on cross-origin requests.
- A fresh random token is generated per submission, preventing replay.

#### Layer 3: Session pinning (opportunistic)

Once the double-submit check succeeds for a session, Symfony remembers this and **requires** it for future requests in that session. This prevents downgrade attacks where an attacker might try to bypass the JavaScript layer.

### What the literal `"csrf-token"` value means

When you view the page source and see:

```html
<input type="hidden" name="_csrf_token" value="csrf-token">
```

This is **expected behavior**, not a bug. The string `"csrf-token"` is:

- A marker that the JavaScript picks up (it matches the regex `/^[-_a-zA-Z0-9]{4,22}$/`).
- Used as the cookie name prefix for the double-submit pattern.
- Replaced with a real random token **before** the form is actually submitted.

If JavaScript is disabled, the field value `"csrf-token"` is submitted as-is. The server still validates the request using the Origin/Referer header check (Layer 1), so the request is still protected.

### Prerequisite: trusted_proxies

For the Origin check to work in production (behind a reverse proxy or load balancer), the application must know its own origin. This requires correct `trusted_proxies` and `trusted_headers` configuration in `config/packages/framework.yaml`:

```yaml
trusted_proxies: "%env(default::TRUSTED_PROXIES)%"
trusted_headers:
    [
        "x-forwarded-for",
        "x-forwarded-host",
        "x-forwarded-proto",
        "x-forwarded-port",
        "x-forwarded-prefix",
    ]
```

If `trusted_proxies` is misconfigured, Symfony may see the wrong host/protocol and reject legitimate same-origin requests or (worse) accept cross-origin ones. Make sure `TRUSTED_PROXIES` is set to the proxy's IP range in your deployment environment.

### Why not session-based CSRF?

1. **Cacheability**: Stateless tokens allow full page caching. Session-bound tokens require a session to exist before the page is rendered, which defeats HTTP caching.
2. **Login form**: The login page is visited by unauthenticated users who have no session yet. A session-based token would force starting a session just to show the login form.
3. **Simplicity**: One protection model for all forms, no need to manage token storage or expiration.

### Adding CSRF protection to new forms

For any new form that performs a state-changing action:

1. Add a hidden field in the Twig template:
   ```html
   <input type="hidden" name="_csrf_token" value="{{ csrf_token('your-token-id') }}">
   ```
2. If the token ID is new, add it to `stateless_token_ids` in `config/packages/csrf.yaml`.
3. Validate in the controller using `$this->isCsrfTokenValid('your-token-id', $request->request->getString('_csrf_token'))` or the `#[IsCsrfTokenValid]` attribute.

The JavaScript double-submit enhancement works automatically for any `<input name="_csrf_token">` field -- no additional wiring needed.

### Testing CSRF protection

To verify protection is working, you can create a page on a different origin that attempts to POST to the application. The file `csrf-attack-test.html` (in the dx-tooling workspace root) contains five attack vectors:

| Attack | Method | Expected result |
|--------|--------|-----------------|
| Classic form POST | `<form>` targeting the app | Rejected (Origin mismatch) |
| fetch() with spoofed headers | JavaScript `fetch()` | Blocked by CORS |
| Forged double-submit cookie | Set cookie + POST | Cookie not sent (SameSite=Strict) |
| XHR with credentials | `XMLHttpRequest` | Blocked by CORS |
| Missing token entirely | POST without `_csrf_token` | Rejected |

All five must be rejected for the protection to be considered sound.


## Session Configuration

```yaml
session:
    cookie_samesite: "none"
    cookie_secure: true
    cookie_httponly: true
    name: PHPSESSID_JA
    handler_id: Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler
```

- `cookie_secure: true` -- session cookie is only sent over HTTPS.
- `cookie_httponly: true` -- session cookie is not accessible to JavaScript (mitigates XSS-based session theft).
- `cookie_samesite: "none"` -- allows the session cookie on cross-origin requests. This is set to `"none"` (rather than `"strict"` or `"lax"`) because the application needs to support cross-origin flows (e.g. OAuth callbacks, embedded contexts). CSRF protection does **not** rely on `SameSite` for the session cookie -- it relies on the stateless CSRF mechanism described above.
- `handler_id: PdoSessionHandler` -- sessions are stored in the database, not on the filesystem.


## Password Hashing

```yaml
password_hashers:
    Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: "auto"
```

Symfony's `"auto"` strategy selects the best available algorithm (currently bcrypt or argon2, depending on PHP extensions). The `SecurityUserProvider` implements `PasswordUpgraderInterface` to transparently rehash passwords on login when the algorithm changes.

In the `test` environment, cost parameters are reduced to minimum values for speed:

```yaml
when@test:
    security:
        password_hashers:
            Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface:
                algorithm: auto
                cost: 4          # bcrypt minimum
                time_cost: 3     # argon minimum
                memory_cost: 10  # argon minimum
```


## Access Control

```yaml
access_control:
    - { path: ^/api/, roles: PUBLIC_ACCESS }
    - { path: ^/sign-in, roles: PUBLIC_ACCESS }
    - { path: ^/sign-up, roles: PUBLIC_ACCESS }
    - { path: "^/organization/invitation/[^/]+$", roles: PUBLIC_ACCESS }
    - { path: ^/review, roles: ROLE_USER }
    - { path: ^/projects, roles: ROLE_USER }
    - { path: ^/conversation, roles: ROLE_USER }
    - { path: ^/workspace, roles: ROLE_USER }
    - { path: ^/organization, roles: ROLE_USER }
```

Only the first matching rule applies. Public pages (sign-in, sign-up, invitation acceptance) and API endpoints (`/api/`) are explicitly listed as public. All application features require `ROLE_USER`. The role hierarchy grants `ROLE_ADMIN` all permissions of `ROLE_USER`:

```yaml
role_hierarchy:
    ROLE_ADMIN: ROLE_USER
```

Routes not covered by `access_control` rules are accessible without authentication by default. When adding new verticals with protected routes, add corresponding `access_control` entries.
