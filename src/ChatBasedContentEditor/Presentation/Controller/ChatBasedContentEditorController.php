<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Controller;

use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

final class ChatBasedContentEditorController extends AbstractController
{
    public function __construct(
        private readonly LlmContentEditorFacadeInterface $facade,
        #[Autowire(param: 'chat_based_content_editor.workspace_root')]
        private readonly string                          $workspaceRoot,
    ) {
    }

    #[Route(
        path: '/chat-based-content-editor',
        name: 'chat_based_content_editor.presentation.index',
        methods: [Request::METHOD_GET]
    )]
    public function index(): Response
    {
        return $this->render('@chat_based_content_editor.presentation/chat_based_content_editor.twig', [
            'runUrl'               => $this->generateUrl('chat_based_content_editor.presentation.run'),
            'defaultWorkspacePath' => $this->workspaceRoot,
        ]);
    }

    #[Route(
        path: '/chat-based-content-editor/run',
        name: 'chat_based_content_editor.presentation.run',
        methods: [Request::METHOD_POST]
    )]
    public function run(Request $request): Response
    {
        $instruction   = $request->request->getString('instruction');
        $workspacePath = $request->request->getString('workspace_path');

        if ($instruction === '') {
            return $this->json(['error' => 'Instruction is required.'], 400);
        }

        $resolved = $workspacePath !== '' ? $workspacePath : $this->workspaceRoot;
        if ($resolved === '') {
            return $this->json(['error' => 'Workspace path is required. Set CHAT_EDITOR_WORKSPACE_ROOT or provide it in the form.'], 400);
        }

        $real = realpath($resolved);
        if ($real === false || !is_dir($real)) {
            return $this->json(['error' => 'Workspace path does not exist or is not a directory.'], 400);
        }

        if ($this->workspaceRoot !== '') {
            $allowed = realpath($this->workspaceRoot);
            if ($allowed === false) {
                return $this->json(['error' => 'Configured workspace root does not exist.'], 400);
            }
            $prefix = $allowed . DIRECTORY_SEPARATOR;
            if ($real !== $allowed && !str_starts_with($real . DIRECTORY_SEPARATOR, $prefix)) {
                return $this->json(['error' => 'Workspace path is not under the allowed root.'], 400);
            }
        }

        if (!$this->isCsrfTokenValid('chat_based_content_editor_run', $request->request->getString('_csrf_token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Cache-Control', 'no-store');
        $response->setCallback(function () use ($real, $instruction): void {
            $generator = $this->facade->streamEdit($real, $instruction);
            foreach ($generator as $chunk) {
                echo json_encode($this->chunkToArray($chunk), JSON_THROW_ON_ERROR) . "\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
        });

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function chunkToArray(EditStreamChunkDto $chunk): array
    {
        $arr = ['chunkType' => $chunk->chunkType];
        if ($chunk->content !== null) {
            $arr['content'] = $chunk->content;
        }
        if ($chunk->event !== null) {
            $arr['event'] = $this->eventToArray($chunk->event);
        }
        if ($chunk->success !== null) {
            $arr['success'] = $chunk->success;
        }
        if ($chunk->errorMessage !== null) {
            $arr['errorMessage'] = $chunk->errorMessage;
        }

        return $arr;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventToArray(AgentEventDto $e): array
    {
        $arr = ['kind' => $e->kind];
        if ($e->toolName !== null) {
            $arr['toolName'] = $e->toolName;
        }
        if ($e->toolInputs !== null) {
            $arr['toolInputs'] = array_map(
                static fn (ToolInputEntryDto $t) => ['key' => $t->key, 'value' => $t->value],
                $e->toolInputs
            );
        }
        if ($e->toolResult !== null) {
            $arr['toolResult'] = $e->toolResult;
        }
        if ($e->errorMessage !== null) {
            $arr['errorMessage'] = $e->errorMessage;
        }

        return $arr;
    }
}
