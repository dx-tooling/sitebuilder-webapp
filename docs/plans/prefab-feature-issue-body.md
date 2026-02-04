## Summary

In deployments that provide a root-folder file **`prefabs.yaml`**, when a user signs up and their organization is created, that organization should automatically receive one or more **prefabricated** projects. A prefab supplies all required project attributes (name, link, GitHub access key, LLM provider, LLM API key). A boolean attribute **"keys are visible"** controls whether those keys are ever shown to users of that organization—including in the edit-project UI and in "reuse existing key" flows.

## Goals

- **Prefab-driven projects**: When `prefabs.yaml` exists at the app root (e.g. provided by a hosting overlay like sitebuilder-webapp-hosting-joboo), new organizations get one or more preconfigured projects at creation time.
- **Keys used but not exposed**: Prefab-provided keys (GitHub token, LLM API key) are stored on the project and used for chat editor sessions and workspace operations. When **keys are visible = false**, they must never become visible to org users—not on the edit project form, and not in any "reuse existing key" UI (so they must be excluded from lists of existing keys for create/edit project).
- **Sample only in OSS**: The sitebuilder-webapp repo can ship a **sample** prefab file (e.g. `prefabs.yaml.example` or documented in docs) for structure reference; the open-source project does not ship a real `prefabs.yaml`. Actual prefabs are provided by private hosting (e.g. sitebuilder-webapp-hosting-joboo) via the same pattern used for secrets and deployment file overrides (work dir overlay: hosting repo adds `prefabs.yaml` into the deployed root).

## Prefab attributes

Each prefab entry should provide:

| Attribute | Description |
|-----------|-------------|
| **Project name** | Name of the created project |
| **Project link** | Git URL (e.g. `https://github.com/org/repo.git`) |
| **GitHub access key** | Token used for Git operations |
| **LLM Model Provider** | e.g. OpenAI, Anthropic, etc. |
| **LLM API Key** | API key for the chosen provider |
| **Keys are visible** | Boolean. If `false`: keys are set and used by the app but **never** shown to org users (edit form must not display them; they must not appear in "reuse existing key" lists for GitHub or LLM keys). If `true`: keys are stored and can be shown/reused as for normal user-created projects. |

## Behaviour

1. **When**: On organization creation (triggered by `AccountCoreCreatedSymfonyEvent` → `AccountCoreCreatedSymfonyEventSubscriber` → `createOrganization`). After the organization is created, if a valid `prefabs.yaml` is present at the app root, create one project per prefab entry for that organization.
2. **Where config is read**: App reads `prefabs.yaml` from the project root (e.g. `getcwd()` or Symfony kernel project dir). In hosting-joboo, this file is added in the deploy work dir (like `.env.preprod.local` and config overlays), so the deployed instance sees the hosting-specific prefabs.
3. **Visibility of keys**:
   - **Edit project**: For projects created from a prefab with "keys are visible = false", the edit form must not display the actual GitHub token or LLM API key (e.g. show placeholder or "Managed by deployment").
   - **Reuse existing keys**: Any API that returns "existing keys" for the organization (e.g. for the project form’s "reuse existing key" UI) must **exclude** keys that belong to projects where "keys are visible = false". This applies to both LLM API keys (current `getExistingLlmApiKeys`) and, if/when implemented, any similar "reuse existing GitHub token" list.
   - Keys are still used internally for chat editor and workspace operations; only the UI and reuse lists must hide them.

## Implementation notes

- **Schema**: Define a clear YAML schema for `prefabs.yaml` (e.g. list of prefab objects with the attributes above). Validate on load; skip prefab creation for that org if the file is missing, malformed, or invalid.
- **Persistence**: Projects created from prefabs are normal `Project` entities. Need a way to mark "created from prefab" and "keys are visible" (e.g. columns or a small prefab-metadata table) so that:
  - Edit form and "existing keys" logic can exclude or mask accordingly.
  - Optional: allow "keys are visible" to be stored per project so the rule is consistent for the lifetime of the project.
- **Hosting**: In sitebuilder-webapp-hosting-joboo, add `prefabs.yaml` (or `prefabs.preprod.yaml` etc.) to the set of files copied into the work dir during deploy, similar to `config/packages/preprod/` and `.env.preprod.local`. Prefab contents may reference secrets; those can be injected via env or from decrypted secrets in the hosting repo (same pattern as existing env overrides).

## Out of scope (for this issue)

- Defining the actual prefab content for Joboo (that lives in the private hosting repo).
- Changes to the open-source repo are limited to: feature implementation, sample/documentation prefab file, and tests; no real prefabs or secrets in the public repo.

## References

- Organization creation: `AccountCoreCreatedSymfonyEvent` → `AccountCoreCreatedSymfonyEventSubscriber` → `OrganizationDomainService::createOrganization`
- Project entity and keys: `Project` (githubToken, llmApiKey, llmModelProvider, etc.)
- Existing "reuse" UI: `ProjectMgmtFacade::getExistingLlmApiKeys`, `project_form.twig` (existing LLM keys section)
- Hosting and secrets: sitebuilder-webapp-hosting-joboo README and deploy script (work dir, decrypted env, config overlays)
