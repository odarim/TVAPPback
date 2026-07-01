<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:init-db',
    description: 'Provision the full database structure by running all Doctrine migrations.',
)]
class InitDbCommand extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Also run app:seed-data after migrating')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Initialize database structure');

        // Show the target so nobody accidentally provisions the wrong database.
        $params = $this->connection->getParams();
        $host = $params['host'] ?? ($params['primary']['host'] ?? 'unknown');
        $dbName = $params['dbname'] ?? ($params['primary']['dbname'] ?? 'unknown');
        $io->definitionList(
            ['Host' => (string) $host],
            ['Database' => (string) $dbName],
            ['Driver' => (string) ($params['driver'] ?? 'unknown')],
        );

        if (!$input->getOption('force') && !$io->confirm(sprintf('Run all migrations against "%s"?', $dbName), true)) {
            $io->comment('Aborted.');

            return Command::SUCCESS;
        }

        // Apply every migration in a single transaction (Postgres has
        // transactional DDL, so a failure leaves the schema untouched).
        $io->section('Running migrations');
        $exitCode = $this->runCommand('doctrine:migrations:migrate', [
            'version' => 'latest',
            '--allow-no-migration' => true,
            '--all-or-nothing' => true,
        ], $output);

        if (Command::SUCCESS !== $exitCode) {
            $io->error('Migrations failed — database structure was not created.');

            return $exitCode;
        }

        if ($input->getOption('seed')) {
            $io->section('Seeding data');
            $seedExit = $this->runCommand('app:seed-data', [], $output);
            if (Command::SUCCESS !== $seedExit) {
                $io->warning('Migrations succeeded but seeding failed (data may already exist).');

                return $seedExit;
            }
        }

        $io->success(sprintf('Database "%s" structure is ready.', $dbName));

        return Command::SUCCESS;
    }

    /**
     * Run another registered console command non-interactively, streaming its
     * output to the current one.
     */
    private function runCommand(string $name, array $arguments, OutputInterface $output): int
    {
        $command = $this->getApplication()->find($name);
        $subInput = new ArrayInput($arguments);
        $subInput->setInteractive(false);

        return $command->run($subInput, $output);
    }
}
