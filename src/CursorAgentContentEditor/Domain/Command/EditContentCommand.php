<?php

declare(strict_types=1);

namespace App\CursorAgentContentEditor\Domain\Command;

use App\CursorAgentContentEditor\Domain\Agent\ContentEditorAgent;
use App\CursorAgentContentEditor\Infrastructure\Observer\ConsoleObserver;
use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\Commandline\Command\EnhancedCommand;
use EnterpriseToolingForSymfony\SharedBundle\Locking\Service\LockService;
use EnterpriseToolingForSymfony\SharedBundle\Rollout\Service\RolloutService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name       : 'app:cursor-agent:domain:edit-content',
    description: 'Edit files in a folder using natural language instructions via an AI agent.',
    aliases    : ['edit-content']
)]
final class EditContentCommand extends EnhancedCommand
{
    public function __construct(
        RolloutService                           $rolloutService,
        EntityManagerInterface                   $entityManager,
        LoggerInterface                          $logger,
        LockService                              $lockService,
        ParameterBagInterface                    $parameterBag,
        private WorkspaceToolingServiceInterface $fileEditingFacade,
        private AgentExecutionContextInterface   $executionContext
    ) {
        parent::__construct(
            $rolloutService,
            $entityManager,
            $logger,
            $lockService,
            $parameterBag
        );
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'folder',
                InputArgument::REQUIRED,
                'The path to the folder containing files to edit.'
            )
            ->addArgument(
                'instruction',
                InputArgument::REQUIRED,
                'Natural language instruction describing what to edit.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $folder */
        $folder = $input->getArgument('folder');
        /** @var string $instruction */
        $instruction = $input->getArgument('instruction');

        if (!is_dir($folder)) {
            throw new RuntimeException("Directory does not exist: {$folder}");
        }

        $resolvedFolder = realpath($folder);
        if ($resolvedFolder === false) {
            throw new RuntimeException('Could not resolve folder path.');
        }

        $output->writeln("<info>Working folder:</info> {$resolvedFolder}");
        $output->writeln("<info>Instruction:</info> {$instruction}");
        $output->writeln('');

        $workspaceId = hash('sha256', $resolvedFolder);
        $projectName = basename($resolvedFolder);
        $agentImage  = $_ENV['CURSOR_AGENT_IMAGE'] ?? null;
        if (!is_string($agentImage) || $agentImage === '') {
            $agentImage = 'node:22-slim';
        }
        $workingDir = '/workspace';

        $this->executionContext->setContext(
            $workspaceId,
            $resolvedFolder,
            null,
            $projectName,
            $agentImage
        );

        $observer     = new ConsoleObserver($output);
        $shouldStream = !$output->isQuiet();
        if ($shouldStream) {
            $this->executionContext->setOutputCallback($observer);
        }

        try {
            $apiKey = $_ENV['CURSOR_AGENT_API_KEY'] ?? '';
            if (!is_string($apiKey) || $apiKey === '') {
                throw new RuntimeException('CURSOR_AGENT_API_KEY is not configured.');
            }

            $agent = new ContentEditorAgent($this->fileEditingFacade);
            $output->writeln('<comment>Running cursor agent CLI...</comment>');
            $output->writeln('');
            $output->writeln('<info>Agent response:</info>');

            $result = $agent->run($workingDir, $instruction, $apiKey);
        } finally {
            $this->executionContext->clearContext();
        }

        if (!$shouldStream) {
            $output->writeln('<info>Agent response:</info>');
            $output->writeln($result);
        } else {
            $output->writeln('');
        }

        return self::SUCCESS;
    }
}
