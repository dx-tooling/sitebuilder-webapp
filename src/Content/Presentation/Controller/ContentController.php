<?php

declare(strict_types=1);

namespace App\Content\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ContentController extends AbstractController
{
    #[Route(
        path   : '/',
        name   : 'content.presentation.homepage',
        methods: [Request::METHOD_GET]
    )]
    public function homepageAction(): Response
    {
        return $this->render('@content.presentation/homepage.html.twig');
    }

    #[Route(
        path   : '/about',
        name   : 'content.presentation.about',
        methods: [Request::METHOD_GET]
    )]
    public function aboutAction(): Response
    {
        return $this->render('@content.presentation/about.html.twig');
    }
}
