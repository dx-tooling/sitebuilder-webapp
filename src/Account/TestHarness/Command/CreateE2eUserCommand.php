<?php

declare(strict_types=1);

namespace App\Account\TestHarness\Command;

use App\Account\Domain\Service\AccountDomainService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

/**
 * Create a signed-up user (and default org) for e2e tests.
 *
 * Only runs when APP_ENV=test. Use to prepare a test user without exercising the sign-up UI.
 */
#[AsCommand(
    name: 'app:e2e:create-user',
    description: 'Create a test user and default organization for e2e (only in test env)'
)]
final class CreateE2eUserCommand extends Command
{
    public function __construct(
        private readonly AccountDomainService $accountDomainService,
        private readonly KernelInterface      $kernel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Plain password (default: e2e-secret)', 'e2e-secret');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernel->getEnvironment() !== 'test') {
            $output->writeln('<error>This command only runs in the test environment (APP_ENV=test).</error>');

            return Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $password */
        $password = $input->getOption('password');

        try {
            $this->accountDomainService->register($email, $password);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('E2E test user created.');
        $io->writeln(sprintf('Email: %s', $email));
        $io->writeln(sprintf('Password: %s', $password));
        $io->writeln('Sign in at /en/account/sign-in with these credentials.');

        return Command::SUCCESS;
    }
}
