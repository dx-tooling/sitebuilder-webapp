# Frontend Book

How to build and integrate client-side application logic with **Stimulus** in this project. This aligns with `.cursor/rules/05-frontend.mdc` and `docs/archbook.md` (Client-Side Organization).

---

## 1. Where Stimulus Controllers Live

Stimulus controllers are **colocated with verticals**:

```
src/<Vertical>/Presentation/Resources/assets/controllers/<name>_controller.ts
```

Example: `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_editor_controller.ts`

This keeps frontend code within the same vertical boundaries as the feature. Use **TypeScript** (`.ts`); avoid `.js` for new controllers.

---

## 2. Build and Integration (Four Steps)

To make a new Stimulus controller available to the app, you need to: (1) add its path to Asset Mapper, (2) add it to the TypeScript `source_dir`, (3) import and register it in `bootstrap.ts`, and (4) attach it in Twig with values, targets, and actions.

### 2.1 Asset Mapper

In `config/packages/asset_mapper.yaml`, add the vertical’s controllers directory to `paths`:

```yaml
framework:
    asset_mapper:
        paths:
            - assets/
            - src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/
        missing_import_mode: strict
```

Add **one entry per vertical** that defines Stimulus controllers. Use the `assets/controllers/` path under that vertical.

### 2.2 TypeScript `source_dir`

In the same file, under `sensiolabs_typescript`, add the same path to `source_dir` so the TypeScript compiler includes it:

```yaml
sensiolabs_typescript:
    source_dir:
        - "%kernel.project_dir%/assets/"
        - "%kernel.project_dir%/src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/"
```

### 2.3 Bootstrap: Import and Register

In `assets/bootstrap.ts`, import the controller and register it with a **kebab-case** name:

```ts
import ChatEditorController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_editor_controller.ts";

const app = startStimulusApp();

app.register("chat-editor", ChatEditorController);

webuiBootstrap(app);
```

The name you pass to `app.register()` is the one you use in Twig: `stimulus_controller('chat-editor', ...)`, `stimulus_target('chat-editor', '…')`, `stimulus_action('chat-editor', '…')`.

### 2.4 Twig: Attach Controller, Targets, Actions

In the template, attach the controller and pass **values** (URLs, IDs, flags):

```twig
<div {{ stimulus_controller('chat-editor', {
    runUrl: path('chat_based_content_editor.presentation.run'),
    workspacePath: defaultWorkspacePath|default('')
}) }}>
```

Use **targets** so the controller can find DOM elements:

```twig
<div {{ stimulus_target('chat-editor', 'messages') }}>…</div>
<input {{ stimulus_target('chat-editor', 'instruction') }} …>
<button {{ stimulus_target('chat-editor', 'submit') }}>…</button>
```

Bind **actions** to DOM events (e.g. `submit`, `click`):

```twig
<form {{ stimulus_action('chat-editor', 'handleSubmit', 'submit') }}>
```

`stimulus_action(controllerName, methodName, event)`. Omit the third argument to use the default event for the element (e.g. `submit` for forms, `click` for buttons).

---

## 3. Controller Structure (TypeScript)

### 3.1 Class and Inheritance

```ts
import { Controller } from "@hotwired/stimulus";

export default class ChatEditorController extends Controller {
    // values, targets, connect, disconnect, actions
}
```

Export a **default** class so `bootstrap.ts` can import it.

### 3.2 Values

Use `static values` for data passed from Twig (URLs, IDs, booleans):

```ts
static values = {
    runUrl: String,
    workspacePath: { type: String, default: "" },
    someFlag: Boolean,
};
```

Declare typed getters so the rest of the controller is type-safe:

```ts
declare readonly runUrlValue: string;
declare readonly workspacePathValue: string;
declare readonly someFlagValue: boolean;
```

### 3.3 Targets

Use `static targets` for DOM elements the controller needs to read or update:

```ts
static targets = [
    "messages",
    "instruction",
    "workspacePath",
    "submit",
];
```

Declare target accessors and presence flags:

```ts
declare readonly hasMessagesTarget: boolean;
declare readonly messagesTarget: HTMLElement;
declare readonly hasInstructionTarget: boolean;
declare readonly instructionTarget: HTMLTextAreaElement;
declare readonly hasSubmitTarget: boolean;
declare readonly submitTarget: HTMLButtonElement;
```

Always check `hasXTarget` before using `xTarget` if the target can be missing in some views.

### 3.4 Lifecycle: `connect` and `disconnect`

- **`connect()`**: Runs when the controller’s element is attached to the DOM. Use it to set up state, fetch, or subscribe to other components (e.g. LiveComponent events). Can be `async`.
- **`disconnect()`**: Runs when the element is removed. Use it to remove listeners, cancel work, or drop references so the controller can be garbage-collected.

```ts
async connect(): Promise<void> {
    this.boundHandler = this.handleEvent.bind(this);
    await this.setupDependency();
    this.someElement.addEventListener("change", this.boundHandler);
}

disconnect(): void {
    this.someElement?.removeEventListener("change", this.boundHandler);
    this.cleanup();
}
```

Bind handlers in `connect` and keep a reference so you can remove the same function in `disconnect`.

### 3.5 Actions (Event Handlers)

Action methods receive the `Event`:

```ts
handleSubmit(event: Event): void {
    event.preventDefault();
    // …
}
```

For `submit`, always `preventDefault()` if you are doing a custom `fetch` instead of a normal form POST. Disable inputs during the request and re-enable in `finally` to avoid double submit and to reflect loading state.

### 3.6 Calling the Backend

Use `fetch` with the URL from values. For forms, include CSRF (e.g. from a hidden `_csrf_token` or `default_csrf_tag()`), and set `X-Requested-With: XMLHttpRequest` if the backend treats that as AJAX:

```ts
const form = (event.target as HTMLElement).closest("form");
const csrfInput = form?.querySelector('input[name="_csrf_token"]') as HTMLInputElement | null;
const formData = new FormData();
if (csrfInput) formData.append("_csrf_token", csrfInput.value);
// … append other fields

const response = await fetch(this.runUrlValue, {
    method: "POST",
    headers: { "X-Requested-With": "XMLHttpRequest" },
    body: formData,
});
```

Handle non‑OK responses and parse JSON or streamed bodies as needed.

---

## 4. Checklist for a New Stimulus Controller

1. **Create**  
   `src/<Vertical>/Presentation/Resources/assets/controllers/<name>_controller.ts`

2. **Asset Mapper**  
   Add  
   `src/<Vertical>/Presentation/Resources/assets/controllers/`  
   to `config/packages/asset_mapper.yaml` → `framework.asset_mapper.paths`.

3. **TypeScript**  
   Add the same path to `sensiolabs_typescript.source_dir` in that file.

4. **Bootstrap**  
   In `assets/bootstrap.ts`:  
   - `import … from "../src/…/assets/controllers/<name>_controller.ts"`  
   - `app.register("kebab-name", ImportedController)`.

5. **Twig**  
   - `{{ stimulus_controller('kebab-name', { … }) }}` for the element that owns the behavior.  
   - `{{ stimulus_target('kebab-name', 'targetName') }}` on elements the controller needs.  
   - `{{ stimulus_action('kebab-name', 'methodName', 'event') }}` to wire events.

6. **Build and quality**  
   - `mise run frontend`  
   - `mise quality` (ESLint, TypeScript, Prettier).

---

## 5. References

- **Rules**: `.cursor/rules/05-frontend.mdc` (TypeScript, Stimulus, quality).
- **Architecture**: `docs/archbook.md` — Client-Side Organization, vertical layout.
- **Stimulus**: [Stimulus Handbook](https://stimulus.hotwired.dev/handbook/introduction).
- **Symfony UX**: [Stimulus in Symfony](https://symfony.com/doc/current/frontend.html#stimulus-symfony-ux).
