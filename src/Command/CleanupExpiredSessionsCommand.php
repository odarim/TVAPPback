<?php

namespace App\Command;

use App\Service\SessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-sessions',
    description: 'Deletes all expired active streaming sessions (no heartbeat within TTL).',
)]
class CleanupExpiredSessionsCommand extends Command
{
    public function __construct(
        private SessionService $sessionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Cleaning up expired streaming sessions...');

        $deleted = $this->sessionService->cleanupExpiredSessions();

        if ($deleted === 0) {
            $io->success('No expired sessions found.');
        } else {
            $io->success(sprintf('Deleted %d expired session(s).', $deleted));
        }

        return Command::SUCCESS;
    }
}
