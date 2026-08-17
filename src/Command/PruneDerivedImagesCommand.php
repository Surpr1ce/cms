<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MediaRepository;
use App\Service\Media\DerivedImages;

use function count;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes derived images nothing points at any more.
 *
 * Deleting a file already deletes what was derived from it, so this is not the
 * ordinary path. It is for the cases where the catalogue and the directory get
 * out of step and nothing was there to notice: a database restored from a backup,
 * a development dataset reloaded, a size removed from `ImageSize`, or bytes
 * removed by hand.
 *
 * **Nothing here reads the database to decide what to keep by guesswork.** A
 * derived name is `<size>-<stored filename>`, both halves of which this
 * application generated, so a file is an orphan exactly when its stored filename
 * is not catalogued or its size is not one that exists. Anything it cannot parse
 * is left alone — a pruner that deletes what it does not understand is a pruner
 * nobody should run.
 *
 * Safe at any moment. A derived image is a cache: whatever this removes is made
 * again the next time somebody asks for it.
 */
#[AsCommand(
    name: 'app:media:prune-derived',
    description: 'Removes resized images whose originals are no longer catalogued',
)]
final class PruneDerivedImagesCommand extends Command
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly DerivedImages $derived,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List what would be removed without removing anything',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $catalogued = [];

        foreach ($this->media->findAll() as $media) {
            $catalogued[] = $media->getFilename();
        }

        $orphans = $this->derived->orphans($catalogued);

        if ([] === $orphans) {
            $style->success('Nothing to remove.');

            return Command::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            $style->writeln(sprintf('  %s %s', $dryRun ? 'would remove' : 'removed', $orphan));
        }

        if ($dryRun) {
            $style->note(sprintf('%d file(s) would be removed. Nothing was.', count($orphans)));

            return Command::SUCCESS;
        }

        $this->derived->remove($orphans);

        $style->success(sprintf('Removed %d file(s). They will be made again if anybody asks.', count($orphans)));

        return Command::SUCCESS;
    }
}
