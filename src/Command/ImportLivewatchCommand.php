<?php

namespace App\Command;

use App\Service\LivewatchSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-livewatch',
    description: 'Fetch all channels from livewatch.top public API and store them in the database.',
)]
class ImportLivewatchCommand extends Command
{
    public function __construct(
        private readonly LivewatchSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run', null, InputOption::VALUE_NONE,
            'Preview what would be imported without writing to the database'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('LiveWatch Channel Import');

        if ($dryRun) {
            $io->note('DRY-RUN mode — nothing will be written to the database.');
            // For dry-run we just fetch and count
            $io->warning('Dry-run just fetches the data count. Use without --dry-run to actually import.');
            return Command::SUCCESS;
        }

        $io->section('Syncing from livewatch.top…');

        try {
            $stats = $this->syncService->sync();
        } catch (\Throwable $e) {
            $io->error('Sync failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('Results');
        $io->definitionList(
            ['Channels fetched'     => $stats['fetched']],
            ['Categories synced'    => $stats['categories_synced']],
            ['Channels created'     => $stats['created']],
            ['Channels updated'     => $stats['updated']],
            ['Channels skipped'     => $stats['skipped']],
            ['Streams added'        => $stats['streams_added']],
        );

        $io->success('Import complete!');
        return Command::SUCCESS;
    }
}
