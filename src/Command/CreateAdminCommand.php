<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create (or promote) an admin user with ROLE_ADMIN.',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email address')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password (min 8 chars)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Full name', 'Admin')
            ->addOption('super', null, InputOption::VALUE_NONE, 'Also grant ROLE_SUPER_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create admin user');

        // --- Email -----------------------------------------------------------
        $email = $input->getOption('email');
        if (!$email) {
            $email = $io->ask('Email address', null, function (?string $value): string {
                $value = trim((string) $value);
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('Please enter a valid email address.');
                }

                return $value;
            });
        } else {
            $email = trim($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $io->error(sprintf('"%s" is not a valid email address.', $email));

                return Command::INVALID;
            }
        }

        // --- Existing user? --------------------------------------------------
        $existing = $this->userRepository->findOneBy(['email' => $email]);
        $resetPassword = true;

        if ($existing instanceof User) {
            $io->warning(sprintf('A user with email "%s" already exists.', $email));
            if (!$io->confirm('Promote this user to admin?', true)) {
                $io->comment('Aborted.');

                return Command::SUCCESS;
            }
            $resetPassword = $io->confirm('Reset the password too?', false);
        }

        // --- Password --------------------------------------------------------
        $password = null;
        if ($resetPassword) {
            $password = $input->getOption('password');
            if (!$password) {
                $password = $io->askHidden('Password (min 8 chars)', function (?string $value): string {
                    if (null === $value || strlen($value) < 8) {
                        throw new \RuntimeException('Password must be at least 8 characters.');
                    }

                    return $value;
                });
            } elseif (strlen($password) < 8) {
                $io->error('Password must be at least 8 characters.');

                return Command::INVALID;
            }
        }

        // --- Create or update ------------------------------------------------
        $user = $existing ?? new User();
        if (!$existing) {
            $user->setEmail($email);
            $user->setFullName($input->getOption('name'));
            $user->setIsActive(true);
        }

        // Merge ROLE_ADMIN (and optionally ROLE_SUPER_ADMIN) without dropping
        // any roles the user may already have. ROLE_USER is added implicitly by
        // the entity's getRoles(), so we don't store it here.
        $roles = array_values(array_unique(array_filter(
            array_merge($user->getRoles(), ['ROLE_ADMIN'], $input->getOption('super') ? ['ROLE_SUPER_ADMIN'] : []),
            static fn (string $role): bool => $role !== 'ROLE_USER',
        )));
        $user->setRoles($roles);

        if ($resetPassword && null !== $password) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s admin user "%s" (roles: %s).',
            $existing ? 'Updated' : 'Created',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
