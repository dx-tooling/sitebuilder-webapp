<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Domain\Command;

use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\Commandline\Command\EnhancedCommand;
use EnterpriseToolingForSymfony\SharedBundle\Locking\Service\LockService;
use EnterpriseToolingForSymfony\SharedBundle\Rollout\Service\RolloutService;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:llm-file-editing:domain:edit-file-demo',
    description: '',
    aliases: ['edit-file-demo']
)]
final class EditFileDemoCommand extends EnhancedCommand
{
    public function __construct(
        RolloutService                         $rolloutService,
        EntityManagerInterface                 $entityManager,
        LoggerInterface                        $logger,
        LockService                            $lockService,
        ParameterBagInterface                  $parameterBag,
        private TextOperationsService          $textOperationsService,
        private FileOperationsServiceInterface $fileOperationsService,
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
    }

    public function execute(
        InputInterface  $input,
        OutputInterface $output
    ): int {
        $demoFilePath = __DIR__ . '/../Resources/fixtures/demo-file.txt';
        $outputPath   = '/var/tmp/demo-file-edited.txt';

        $hardCodedDiff = <<<'DIFF'
        @@ -1,8 +1,9 @@
         The world is changed.
         I feel it in the water.
         I feel it in the earth.
         I smell it in the air.
         Much that once was is lost, for none now live who remember it.

        +One ring to rule them all, one ring to find them.
         It began with the forging of the Great Rings. Three were given to the Elves, immortal, wisest and fairest of all beings. Seven to the Dwarf-Lords, great miners and craftsmen of the mountain halls. And nine, nine rings were gifted to the race of Men, who above all else desire power. For within these rings was bound the strength and the will to govern each race. But they were all of them deceived, for another ring was made. Deep in the land of Mordor, in the Fires of Mount Doom, the Dark Lord Sauron forged a master ring, and into this ring he poured his cruelty, his malice and his will to dominate all life.
        DIFF;

        $modifiedContent = $this->textOperationsService->applyDiffToFile($demoFilePath, $hardCodedDiff);
        $this->fileOperationsService->writeFileContent($outputPath, $modifiedContent);

        $output->writeln($modifiedContent);

        return self::SUCCESS;
    }
}
