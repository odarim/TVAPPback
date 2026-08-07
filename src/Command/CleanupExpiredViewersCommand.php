<?php

namespace App\Command;

use App\Service\ChannelViewerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-viewers',
    description: 'Deletes expired "watching now" viewer rows (no heartbeat within TTL).',
)]
class CleanupExpiredViewersCommand extends Command
{
    public function __construct(
        private ChannelViewerService $channelViewerService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Cleaning up expired channel viewers...');

        $deleted = $this->channelViewerService->cleanupExpired();

        if ($deleted === 0) {
            $io->success('No expired viewers found.');
        } else {
            $io->success(sprintf('Deleted %d expired viewer row(s).', $deleted));
        }

        return Command::SUCCESS;
    }
}
