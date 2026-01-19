<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain\Command;

use App\LlmFileEditing\Facade\LlmFileEditingFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\Commandline\Command\EnhancedCommand;
use EnterpriseToolingForSymfony\SharedBundle\Locking\Service\LockService;
use EnterpriseToolingForSymfony\SharedBundle\Rollout\Service\RolloutService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;


#[AsCommand(
    name       : 'app:llm-content-editor:domain:edit-content',
    description: '',
    aliases    : ['edit-content']
)]
final class EditContentCommand
    extends EnhancedCommand
{
    public function __construct(
        RolloutService                        $rolloutService,
        EntityManagerInterface                $entityManager,
        LoggerInterface                       $logger,
        LockService                           $lockService,
        ParameterBagInterface                 $parameterBag,
        private LlmFileEditingFacadeInterface $fileEditingFacade
    )
    {
        parent::__construct(
            $rolloutService,
            $entityManager,
            $logger,
            $lockService,
            $parameterBag
        );
    }

}
