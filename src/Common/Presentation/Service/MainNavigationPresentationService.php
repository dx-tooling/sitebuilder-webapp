<?php

declare(strict_types=1);

namespace App\Common\Presentation\Service;

use EnterpriseToolingForSymfony\WebuiBundle\Entity\MainNavigationEntry;
use EnterpriseToolingForSymfony\WebuiBundle\Service\AbstractMainNavigationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use ValueError;

readonly class MainNavigationPresentationService extends AbstractMainNavigationService
{
    public function __construct(
        RouterInterface               $router,
        RequestStack                  $requestStack,
        private ParameterBagInterface $parameterBag,
        private Security              $security,
        private TranslatorInterface   $translator,
    ) {
        $symfonyEnvironment = $this->parameterBag->get('kernel.environment');

        if (!is_string($symfonyEnvironment)) {
            throw new ValueError('Symfony environment is not a string.');
        }

        parent::__construct(
            $router,
            $requestStack,
            $symfonyEnvironment
        );
    }

    public function secondaryMainNavigationIsPartOfDropdown(): bool
    {
        return true;
    }

    public function getPrimaryMainNavigationTitle(): string
    {
        return $this->translator->trans('navigation.primary.title');
    }

    /**
     * @return list<MainNavigationEntry>
     */
    public function getPrimaryMainNavigationEntries(): array
    {
        $entries = [];

        if (!$this->security->isGranted('ROLE_USER')) {
            $entries = [
                $this->generateEntry(
                    $this->translator->trans('navigation.sign_in'),
                    'account.presentation.sign_in',
                ),
                $this->generateEntry(
                    $this->translator->trans('navigation.sign_up'),
                    'account.presentation.sign_up',
                )
            ];
        }

        if ($this->security->isGranted('ROLE_USER')) {
            $entries[] = $this->generateEntry(
                $this->translator->trans('navigation.projects'),
                'project_mgmt.presentation.list',
            );
        }

        if ($this->security->isGranted('CAN_REVIEW_WORKSPACES')) {
            $entries[] = $this->generateEntry(
                $this->translator->trans('navigation.reviewer_dashboard'),
                'workspace_mgmt.presentation.review_list',
            );
        }

        // Workflow docs is visible to all users (public page)
        $entries[] = $this->generateEntry(
            $this->translator->trans('navigation.workflow_docs'),
            'static_pages.presentation.workflow_docs',
        );

        return $entries;
    }

    public function getSecondaryMainNavigationTitle(): string
    {
        return $this->translator->trans('navigation.secondary.title');
    }

    /**
     * @return list<MainNavigationEntry>
     */
    protected function getSecondaryMainNavigationEntries(): array
    {
        $entries = [];

        if ($this->security->isGranted('ROLE_USER')) {
            $entries[] = $this->generateEntry(
                $this->translator->trans('navigation.your_account'),
                'account.presentation.dashboard',
            );
            $entries[] = $this->generateEntry(
                $this->translator->trans('navigation.organization'),
                'organization.presentation.dashboard',
            );
        }

        return $entries;
    }

    /**
     * @return list<MainNavigationEntry>
     */
    public function getFinalSecondaryMainNavigationEntries(): array
    {
        return $this->getSecondaryMainNavigationEntries();
    }

    public function getTertiaryMainNavigationTitle(): string
    {
        return $this->translator->trans('navigation.tertiary.title');
    }

    /**
     * @return list<MainNavigationEntry>
     */
    public function getTertiaryMainNavigationEntries(): array
    {
        $entries = [
            $this->generateEntry(
                $this->translator->trans('navigation.living_styleguide'),
                'webui.living_styleguide.show',
            ),
        ];

        return $entries;
    }

    public function getBrandLogoHtml(): string
    {
        return '<strong>DX·Tooling SiteBuilder</strong>';
    }
}
