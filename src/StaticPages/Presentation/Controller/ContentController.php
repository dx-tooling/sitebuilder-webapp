<?php

declare(strict_types=1);

namespace App\StaticPages\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ContentController extends AbstractController
{
    #[Route(
        path   : '/',
        name   : 'static_pages.presentation.homepage',
        methods: [Request::METHOD_GET]
    )]
    public function homepageAction(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        return $this->render('@static_pages.presentation/homepage.html.twig');
    }

    #[Route(
        path   : '/about',
        name   : 'static_pages.presentation.about',
        methods: [Request::METHOD_GET]
    )]
    public function aboutAction(): Response
    {
        return $this->render('@static_pages.presentation/about.html.twig');
    }
}
