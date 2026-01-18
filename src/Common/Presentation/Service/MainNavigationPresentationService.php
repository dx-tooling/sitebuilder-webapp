<?php

declare(strict_types=1);

namespace App\Common\Presentation\Service;

use EnterpriseToolingForSymfony\WebuiBundle\Entity\MainNavigationEntry;
use EnterpriseToolingForSymfony\WebuiBundle\Service\AbstractMainNavigationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use ValueError;

readonly class MainNavigationPresentationService extends AbstractMainNavigationService
{
    public function __construct(
        RouterInterface               $router,
        RequestStack                  $requestStack,
        private ParameterBagInterface $parameterBag,
        private Security              $security,
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
        return 'Main';
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
                    'Sign In',
                    'account.presentation.sign_in',
                ),
                $this->generateEntry(
                    'Sign Up',
                    'account.presentation.sign_up',
                )
            ];
        }

        return $entries;
    }

    public function getSecondaryMainNavigationTitle(): string
    {
        return 'Other';
    }

    /**
     * @return list<MainNavigationEntry>
     */
    protected function getSecondaryMainNavigationEntries(): array
    {
        $entries = [];

        if ($this->security->isGranted('ROLE_USER')) {
            $entries[] = $this->generateEntry(
                'Your Account',
                'account.presentation.dashboard',
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
        return 'Utilities';
    }

    /**
     * @return list<MainNavigationEntry>
     */
    public function getTertiaryMainNavigationEntries(): array
    {
        $entries = [
            $this->generateEntry(
                'Living Styleguide',
                'webui.living_styleguide.show',
            )
        ];

        return $entries;
    }

    public function getBrandLogoHtml(): string
    {
        return '<strong>DX·Tooling SiteBuilder</strong>';
    }
}
