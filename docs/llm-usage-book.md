# LLM Usage Book

How AI models are used in this application, which providers are supported, and how per-project configuration works.

---

## 1. Concerns

The application has two distinct AI-powered concerns, each with its own provider and API key configuration:

| Concern | Description | Configured via |
|---|---|---|
| **Content Editing** | Chat-based content editor that rewrites and creates web page content | `contentEditingLlmModelProvider` + `contentEditingLlmModelProviderApiKey` |
| **PhotoBuilder** | Generates image prompts from page content, then generates images from those prompts | `photoBuilderLlmModelProvider` + `photoBuilderLlmModelProviderApiKey` (or falls back to content editing settings) |

### Content Editing

The content editing concern powers the `ChatBasedContentEditor` vertical. An LLM agent (`ContentEditorAgent`) receives the current page content plus user instructions and streams back edits. This is the primary feature of the application and its provider/key are **always required** on every project.

### PhotoBuilder

The PhotoBuilder concern has two sub-steps:

1. **Image Prompt Generation** -- An LLM agent (`ImagePromptAgent`) reads the page HTML and generates descriptive prompts for each image, plus a descriptive filename. This is a text-generation task using tool calls.
2. **Image Generation** -- An image-generation model turns each prompt into a PNG image. This is a dedicated image-generation API call.

Both sub-steps use the same provider and API key (the project's PhotoBuilder settings).

---

## 2. Providers

| Provider | Enum value | Content Editing | Image Prompt Generation | Image Generation |
|---|---|---|---|---|
| **OpenAI** | `openai` | Yes (required) | Yes | Yes |
| **Google Gemini** | `google` | No | Yes | Yes |

OpenAI is the only provider available for content editing. For the PhotoBuilder, either OpenAI or Google Gemini can be used.

---

## 3. Models

### Content Editing

| Provider | Model | Enum | Purpose |
|---|---|---|---|
| OpenAI | `gpt-5.2` | `LlmModelName::Gpt52` | Text generation for content editing |

### PhotoBuilder -- Image Prompt Generation

| Provider | Model | Enum | Purpose |
|---|---|---|---|
| OpenAI | `gpt-5.2` | `LlmModelName::Gpt52` | Text generation with tool calls to produce image prompts |
| Google | `gemini-3-pro-preview` | `LlmModelName::Gemini3ProPreview` | Text generation with tool calls to produce image prompts |

### PhotoBuilder -- Image Generation

| Provider | Model | Enum | Purpose |
|---|---|---|---|
| OpenAI | `gpt-image-1` | `LlmModelName::GptImage1` | Image generation from text prompt |
| Google | `gemini-3-pro-image-preview` | `LlmModelName::Gemini3ProImagePreview` | Image generation from text prompt |

---

## 4. Per-Project Configuration

Each project stores two sets of LLM settings:

### Content Editing (mandatory)

- `contentEditingLlmModelProvider` -- always `openai` (the only supported provider for content editing)
- `contentEditingLlmModelProviderApiKey` -- the user's OpenAI API key

These fields are required when creating or editing a project.

### PhotoBuilder (optional, with fallback)

- `photoBuilderLlmModelProvider` -- `openai` or `google`, nullable
- `photoBuilderLlmModelProviderApiKey` -- the matching API key, nullable

**Fallback rule**: When the PhotoBuilder fields are `null`, the application uses the content editing provider and API key for the PhotoBuilder. This is the default for all projects, including prefab-created projects.

The project form offers two options:

- **Option A** -- "Use Content Editing LLM settings for image generation" (default). No additional fields are shown. Under the hood, PhotoBuilder fields remain `null`.
- **Option B** -- "Use dedicated LLM settings for image generation". The user selects a provider (OpenAI or Google) and provides an API key. The same one-click key reuse UI is available.

### Effective Provider Resolution

```
function getEffectivePhotoBuilderProvider():
    if photoBuilderLlmModelProvider is not null:
        return photoBuilderLlmModelProvider
    return contentEditingLlmModelProvider

function getEffectivePhotoBuilderApiKey():
    if photoBuilderLlmModelProviderApiKey is not null:
        return photoBuilderLlmModelProviderApiKey
    return contentEditingLlmModelProviderApiKey
```

---

## 5. Prefab Projects

Prefab-based projects (created automatically when a new organization is set up) always use **Option A** -- the PhotoBuilder fields are not set in the prefab YAML and default to `null`, meaning they reuse the content editing settings.

The `keysVisible` flag in the prefab configuration controls whether API keys are shown to users in the project form. When `keysVisible` is `false`, keys are used by the application but never displayed or editable.

---

## 6. Key Security

- API keys are stored encrypted at rest in the database.
- The `keysVisible` flag prevents prefab-managed keys from being shown in the UI.
- The one-click key reuse feature only shows abbreviated keys (`sk-abc...xyz`) and filters by organization to prevent cross-organization leakage.
- API keys are never sent to the frontend; verification happens server-side via dedicated AJAX endpoints.

---

## 7. Architecture

### Prompt Generation

The `ImagePromptAgent` (NeuronAI agent) supports both providers:

- **OpenAI**: uses `NeuronAI\Providers\OpenAI\OpenAI` with model `gpt-5.2`
- **Google**: uses `NeuronAI\Providers\Gemini\Gemini` with model `gemini-3-pro-preview`

The agent's `provider()` method selects the right NeuronAI provider based on the project's effective PhotoBuilder provider.

### Image Generation

Image generation uses dedicated adapter classes behind `ImageGeneratorInterface`:

- `OpenAiImageGenerator` -- calls OpenAI's `/v1/images/generations` endpoint with model `gpt-image-1`
- `GeminiImageGenerator` -- calls Google's `generativelanguage.googleapis.com` endpoint with model `gemini-3-pro-image-preview`

A `PhotoBuilderImageGeneratorFactory` selects the correct adapter based on the project's effective PhotoBuilder provider.

### Content Editing

Content editing is handled by the `ContentEditorAgent` (NeuronAI agent) using OpenAI exclusively. This path is not affected by the PhotoBuilder provider configuration.
