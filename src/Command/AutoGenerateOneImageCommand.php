<?php

namespace App\Command;

use App\Service\Image\AutoImageGenerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:images:auto-generate-one',
    description: 'Génère automatiquement 1 image (recette sinon ingrédient) si nécessaire.'
)]
final class AutoGenerateOneImageCommand extends Command
{
    public function __construct(
        private readonly AutoImageGenerationService $service,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->generateOne();
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
