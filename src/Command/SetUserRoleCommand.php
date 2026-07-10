<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:set-user-role',
    description: 'Update a user\'s role(s) by email address.',
)]
class SetUserRoleCommand extends Command
{
    /**
     * Roles that can be assigned. ROLE_USER is granted implicitly by the
     * User entity and is therefore never stored explicitly.
     */
    private const ASSIGNABLE_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the user to update')
            ->addArgument('roles', InputArgument::IS_ARRAY, 'Role(s) to assign, e.g. ROLE_ADMIN (or just "admin")')
            ->addOption('add', null, InputOption::VALUE_NONE, 'Add the given role(s) instead of replacing the existing set')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the given role(s) from the user')
            ->setHelp(<<<'HELP'
Set a user's roles (replaces the current set):
  <info>php bin/console %command.name% user@example.com ROLE_ADMIN</info>

Add a role without dropping existing ones:
  <info>php bin/console %command.name% user@example.com ROLE_SUPER_ADMIN --add</info>

Remove a role:
  <info>php bin/console %command.name% user@example.com ROLE_ADMIN --remove</info>

Demote a user back to a plain user (removes all elevated roles):
  <info>php bin/console %command.name% user@example.com</info>

Assignable roles: ROLE_ADMIN, ROLE_SUPER_ADMIN. ROLE_USER is always implied.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $add = $input->getOption('add');
        $remove = $input->getOption('remove');
        if ($add && $remove) {
            $io->error('The --add and --remove options cannot be used together.');

            return Command::INVALID;
        }

        // --- Email -----------------------------------------------------------
        $email = trim((string) $input->getArgument('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('"%s" is not a valid email address.', $email));

            return Command::INVALID;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error(sprintf('No user found with email "%s".', $email));

            return Command::FAILURE;
        }

        // --- Normalise & validate the requested roles ------------------------
        $requested = [];
        foreach ($input->getArgument('roles') as $role) {
            $role = strtoupper(trim((string) $role));
            if ('' === $role) {
                continue;
            }
            if (!str_starts_with($role, 'ROLE_')) {
                $role = 'ROLE_'.$role;
            }
            if ('ROLE_USER' === $role) {
                continue; // implicit, never stored
            }
            if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
                $io->error(sprintf(
                    'Unknown role "%s". Assignable roles are: %s.',
                    $role,
                    implode(', ', self::ASSIGNABLE_ROLES),
                ));

                return Command::INVALID;
            }
            $requested[] = $role;
        }
        $requested = array_values(array_unique($requested));

        if (($add || $remove) && [] === $requested) {
            $io->error('The --add and --remove options require at least one role.');

            return Command::INVALID;
        }

        // Current stored roles (excluding the implicit ROLE_USER).
        $current = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => 'ROLE_USER' !== $role,
        ));

        // --- Compute the new set ---------------------------------------------
        if ($add) {
            $new = array_values(array_unique(array_merge($current, $requested)));
        } elseif ($remove) {
            $new = array_values(array_diff($current, $requested));
        } else {
            $new = $requested; // replace
        }

        sort($current);
        $sortedNew = $new;
        sort($sortedNew);

        if ($current === $sortedNew) {
            $io->info(sprintf(
                'No change: "%s" already has roles [%s].',
                $email,
                implode(', ', $user->getRoles()),
            ));

            return Command::SUCCESS;
        }

        $user->setRoles($new);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Updated "%s": roles are now [%s].',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
