---
name: HTML Editor Build Fix
overview: "Issue-95: Asynchroner Docker-Build via Symfony Messenger mit Frontend-Polling. savePage() schreibt src/, dispatcht Message, Messenger-Container fuehrt Docker-Build aus, Frontend pollt Status."
todos:
  - id: enum-entity
    content: HtmlEditorBuildStatus Enum und HtmlEditorBuild Entity erstellen
    status: completed
  - id: message-handler
    content: RunHtmlBuildMessage und RunHtmlBuildHandler erstellen
    status: completed
  - id: controller-endpoints
    content: savePage() umbauen und pollBuildStatus() Endpoint hinzufuegen
    status: completed
  - id: twig-template
    content: Twig-Template um buildStatusUrl Value erweitern
    status: completed
  - id: frontend-polling
    content: html_editor_controller.ts um Polling-Logik erweitern
    status: completed
  - id: translations
    content: Neue Uebersetzungen fuer Build-Status hinzufuegen
    status: completed
  - id: migration
    content: Doctrine Migration generieren
    status: completed
  - id: quality-tests
    content: mise run quality und Tests ausfuehren
    status: completed
isProject: false
---

# Implementierungsplan: Asynchroner HTML-Editor Build (Issue-95)

## Ursache

- `savePage()` laeuft im **app-Container** (PHP-FPM, HTTP-Request)
- Der app-Container hat **keinen Docker-Socket** (siehe `docker-compose.yml` Zeile 7-9)
- Nur der **messenger-Container** hat den Docker-Socket (`/var/run/docker.sock`, Zeile 37)
- Daher muss der Docker-Build ueber Symfony Messenger dispatcht werden

## Neuer Ablauf

```mermaid
sequenceDiagram
    participant User
    participant Controller as Controller (app-Container)
    participant DB as Datenbank
    participant Queue as Symfony Messenger
    participant Handler as Handler (messenger-Container)
    participant Docker as Docker (agentImage)

    User->>Controller: POST /save-page
    Controller->>Controller: writeWorkspaceFile (src/)
    Controller->>DB: HtmlEditorBuild (status=pending)
    Controller->>Queue: dispatch(RunHtmlBuildMessage)
    Controller-->>User: 202 {buildId}

    Queue->>Handler: __invoke()
    Handler->>DB: status = running
    Handler->>Docker: runBuildInWorkspace()
    Docker-->>Handler: Build-Output
    Handler->>Handler: commitAndPush()
    Handler->>DB: status = completed

    loop Polling (1s Intervall)
        User->>Controller: GET /build-status/{buildId}
        Controller->>DB: load status
        Controller-->>User: {status, error?}
    end
```
