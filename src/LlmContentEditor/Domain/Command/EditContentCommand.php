<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Domain\Command;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Infrastructure\Observer\ConsoleObserver;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\Commandline\Command\EnhancedCommand;
use EnterpriseToolingForSymfony\SharedBundle\Locking\Service\LockService;
use EnterpriseToolingForSymfony\SharedBundle\Rollout\Service\RolloutService;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name       : 'app:llm-content-editor:domain:edit-content',
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
        private ProjectMgmtFacadeInterface       $projectMgmtFacade,
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
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'The LLM API key (falls back to LLM_CONTENT_EDITOR_OPENAI_API_KEY env var if not provided).'
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

        // Get API key from option or fall back to env var for CLI usage
        /** @var string|null $apiKey */
        $apiKey = $input->getOption('api-key');
        if ($apiKey === null || $apiKey === '') {
            /** @var string $apiKey */
            $apiKey = $_ENV['LLM_CONTENT_EDITOR_OPENAI_API_KEY'] ?? '';
        }

        if ($apiKey === '') {
            throw new RuntimeException(
                'No API key provided. Use --api-key option or set LLM_CONTENT_EDITOR_OPENAI_API_KEY env var.'
            );
        }

        $output->writeln("<info>Working folder:</info> {$resolvedFolder}");
        $output->writeln("<info>Instruction:</info> {$instruction}");
        $output->writeln('');

        // Create agent config from default template
        $template    = $this->projectMgmtFacade->getAgentConfigTemplate(ProjectType::DEFAULT);
        $agentConfig = new AgentConfigDto(
            $template->backgroundInstructions,
            $template->stepInstructions,
            $template->outputInstructions
        );

        $agent    = new ContentEditorAgent($this->fileEditingFacade, LlmModelName::defaultForContentEditor(), $apiKey, $agentConfig);
        $observer = new ConsoleObserver($output);
        $agent->attach($observer);

        $prompt = sprintf(
            'The working folder is: %s' . "\n\n" .
            'Please perform the following task: %s',
            $resolvedFolder,
            $instruction
        );

        $output->writeln('<comment>Starting AI agent...</comment>');

        $message = new UserMessage($prompt);
        $stream  = $agent->stream($message);

        $output->writeln('');
        $output->writeln('<info>Agent response:</info>');

        foreach ($stream as $chunk) {
            if (is_string($chunk)) {
                $output->write($chunk);
            }
        }

        $output->writeln('');

        return self::SUCCESS;
    }
}
