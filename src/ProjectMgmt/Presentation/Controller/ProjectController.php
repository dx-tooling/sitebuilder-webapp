<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Presentation\Controller;

use App\ProjectMgmt\Domain\Service\ProjectService;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for project management.
 * Uses internal ProjectService and WorkspaceMgmtFacade for cross-vertical access.
 */
#[IsGranted('ROLE_USER')]
final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectService               $projectService,
        private readonly WorkspaceMgmtFacadeInterface $workspaceMgmtFacade,
    ) {
    }

    #[Route(
        path: '/projects',
        name: 'project_mgmt.presentation.list',
        methods: [Request::METHOD_GET]
    )]
    public function list(): Response
    {
        $projects = $this->projectService->findAll();

        $projectsWithStatus = [];
        foreach ($projects as $project) {
            $projectId = $project->getId();
            if ($projectId === null) {
                continue;
            }

            $workspace = $this->workspaceMgmtFacade->getWorkspaceForProject($projectId);

            $projectsWithStatus[] = [
                'project'   => $project,
                'workspace' => $workspace,
            ];
        }

        return $this->render('@project_mgmt.presentation/project_list.twig', [
            'projectsWithStatus' => $projectsWithStatus,
        ]);
    }

    #[Route(
        path: '/projects/new',
        name: 'project_mgmt.presentation.new',
        methods: [Request::METHOD_GET]
    )]
    public function new(): Response
    {
        return $this->render('@project_mgmt.presentation/project_form.twig', [
            'project' => null,
        ]);
    }

    #[Route(
        path: '/projects',
        name: 'project_mgmt.presentation.create',
        methods: [Request::METHOD_POST]
    )]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('project_create', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        $name        = $request->request->getString('name');
        $gitUrl      = $request->request->getString('git_url');
        $githubToken = $request->request->getString('github_token');

        if ($name === '' || $gitUrl === '' || $githubToken === '') {
            $this->addFlash('error', 'All fields are required.');

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        $this->projectService->create($name, $gitUrl, $githubToken);
        $this->addFlash('success', 'Project created successfully.');

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/projects/{id}/edit',
        name: 'project_mgmt.presentation.edit',
        methods: [Request::METHOD_GET],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function edit(string $id): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null) {
            throw $this->createNotFoundException('Project not found.');
        }

        return $this->render('@project_mgmt.presentation/project_form.twig', [
            'project' => $project,
        ]);
    }

    #[Route(
        path: '/projects/{id}',
        name: 'project_mgmt.presentation.update',
        methods: [Request::METHOD_POST],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function update(string $id, Request $request): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('project_update', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        $name        = $request->request->getString('name');
        $gitUrl      = $request->request->getString('git_url');
        $githubToken = $request->request->getString('github_token');

        if ($name === '' || $gitUrl === '' || $githubToken === '') {
            $this->addFlash('error', 'All fields are required.');

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        $this->projectService->update($project, $name, $gitUrl, $githubToken);
        $this->addFlash('success', 'Project updated successfully.');

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }
}
