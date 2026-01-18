<?php

declare(strict_types=1);

namespace App\Account\Domain\Command;

use App\Account\Domain\Entity\AccountCore;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\Commandline\Command\EnhancedCommand;
use EnterpriseToolingForSymfony\SharedBundle\Locking\Service\LockService;
use EnterpriseToolingForSymfony\SharedBundle\Rollout\Service\RolloutService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name       : 'app:account:domain:promote-account',
    description: 'Add a Symfony ROLE to an account based on email',
    aliases    : ['promote-account']
)]
final class PromoteAccountCommand extends EnhancedCommand
{
    public function __construct(
        RolloutService         $rolloutService,
        EntityManagerInterface $entityManager,
        LoggerInterface        $logger,
        LockService            $lockService,
        ParameterBagInterface  $parameterBag,
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
                'email',
                InputArgument::REQUIRED,
                'Email address of the account',
                null
            )
            ->addArgument(
                'role',
                InputArgument::REQUIRED,
                'Symfony role to add (e.g. ROLE_ADMIN)',
                null
            );
    }

    public function execute(
        InputInterface  $input,
        OutputInterface $output
    ): int {
        $email = $input->getArgument('email');
        $role  = $input->getArgument('role');
        if (!is_string($email) || !is_string($role)) {
            $output->writeln('<error>Invalid arguments.</error>');

            return self::FAILURE;
        }

        $repo    = $this->entityManager->getRepository(AccountCore::class);
        $account = $repo->findOneBy(['email' => $email]);
        if (!$account) {
            $output->writeln(sprintf('<error>No account found for email: %s</error>', $email));

            return self::FAILURE;
        }

        $roles = $account->getRoles();
        if (in_array($role, $roles, true)) {
            $output->writeln(sprintf('<info>Account already has role: %s</info>', $role));

            return self::SUCCESS;
        }

        $roles[] = $role;
        $account->setRoles($roles);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $output->writeln(sprintf('<info>Role %s added to account %s</info>', $role, $email));

        return self::SUCCESS;
    }
}
