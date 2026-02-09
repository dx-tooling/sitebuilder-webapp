<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Command;

use App\ChatBasedContentEditor\Facade\ChatBasedContentEditorFacadeInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Release stale conversations and recover stuck edit sessions.
 *
 * Intended to be run on a schedule (e.g. every 2 minutes via cron)
 * to prevent workspaces from being permanently locked.
 */
#[AsCommand(
    name: 'app:release-stale-conversations',
    description: 'Release conversations that have been inactive for too long and recover stuck edit sessions'
)]
final class ReleaseStaleConversationsCommand extends Command
{
    public function __construct(
        private readonly ChatBasedContentEditorFacadeInterface $facade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Stale conversation timeout in minutes', '5')
            ->addOption('running-timeout', null, InputOption::VALUE_REQUIRED, 'Stuck Running session timeout in minutes', '30')
            ->addOption('cancelling-timeout', null, InputOption::VALUE_REQUIRED, 'Stuck Cancelling session timeout in minutes', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $timeoutRaw */
        $timeoutRaw = $input->getOption('timeout');
        /** @var string $runningTimeoutRaw */
        $runningTimeoutRaw = $input->getOption('running-timeout');
        /** @var string $cancellingTimeoutRaw */
        $cancellingTimeoutRaw = $input->getOption('cancelling-timeout');

        $timeout           = (int) $timeoutRaw;
        $runningTimeout    = (int) $runningTimeoutRaw;
        $cancellingTimeout = (int) $cancellingTimeoutRaw;

        $releasedWorkspaces = $this->facade->releaseStaleConversations($timeout);
        $recoveredSessions  = $this->facade->recoverStuckEditSessions($runningTimeout, $cancellingTimeout);

        $output->writeln(sprintf(
            'Released %d stale workspace(s), recovered %d stuck session(s).',
            count($releasedWorkspaces),
            $recoveredSessions
        ));

        return Command::SUCCESS;
    }
}
