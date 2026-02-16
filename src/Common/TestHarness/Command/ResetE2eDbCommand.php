<?php

declare(strict_types=1);

namespace App\Common\TestHarness\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Reset the test database (drop, create, migrate) for e2e.
 *
 * Only runs when APP_ENV=test.
 */
#[AsCommand(
    name: 'app:e2e:reset-db',
    description: 'Drop, create and migrate the test database for e2e (only in test env)'
)]
final class ResetE2eDbCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernel->getEnvironment() !== 'test') {
            $output->writeln('<error>This command only runs in the test environment (APP_ENV=test).</error>');

            return Command::FAILURE;
        }

        $application = $this->getApplication();
        if ($application === null) {
            $output->writeln('<error>Application not available.</error>');

            return Command::FAILURE;
        }

        $drop = $application->find('doctrine:database:drop');
        $drop->run(new ArrayInput([
            '--env'       => 'test',
            '--if-exists' => true,
            '--force'     => true,
        ]), $output);

        $create = $application->find('doctrine:database:create');
        $create->run(new ArrayInput(['--env' => 'test']), $output);

        $migrate = $application->find('doctrine:migrations:migrate');
        $migrate->run(new ArrayInput([
            '--env'            => 'test',
            '--no-interaction' => true,
        ]), $output);

        $output->writeln('<info>Test database reset complete.</info>');

        return Command::SUCCESS;
    }
}
