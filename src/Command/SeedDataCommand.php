<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\Channel;
use App\Entity\Package;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-data',
    description: 'Seed the database with sample channels and a user.',
)]
class SeedDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create Admin User
        $user = new User();
        $user->setEmail('admin@streampulse.com');
        $user->setFullName('Admin User');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'admin123'));
        $this->entityManager->persist($user);

        // Create Categories
        $categories = [];
        foreach (['News', 'Sports', 'Movies', 'General', 'Documentary'] as $name) {
            $category = new Category();
            $category->setName($name);
            $category->setSlug(strtolower($name));
            $this->entityManager->persist($category);
            $categories[] = $category;
        }

        // Create Channels
        $channelsData = [
            ['France 2', 'https://placehold.co/200x200/6366f1/ffffff?text=F2', 'General'],
            ['CNN International', 'https://placehold.co/200x200/ef4444/ffffff?text=CNN', 'News'],
            ['Eurosport', 'https://placehold.co/200x200/3b82f6/ffffff?text=Euro', 'Sports'],
            ['HBO Max', 'https://placehold.co/200x200/a855f7/ffffff?text=HBO', 'Movies'],
        ];

        foreach ($channelsData as [$name, $logo, $catName]) {
            $channel = new Channel();
            $channel->setName($name);
            $channel->setLogo($logo);
            $channel->setSlug(strtolower(str_replace(' ', '-', $name)));
            $channel->setIsActive(true);
            
            foreach ($categories as $cat) {
                if ($cat->getName() === $catName) {
                    $channel->setCategory($cat);
                    break;
                }
            }
            
            $this->entityManager->persist($channel);
        }

        // Create Packages
        $packagesData = [
            ['Basic', 'Access to essential channels', 9.99, 1],
            ['Premium', 'High-quality streams and sports', 19.99, 3],
            ['Ultimate', 'Everything included, unlimited devices', 29.99, 5],
        ];

        foreach ($packagesData as [$name, $desc, $price, $maxDevices]) {
            $package = new Package();
            $package->setName($name);
            $package->setDescription($desc);
            $package->setPrice($price);
            $package->setMaxDevices($maxDevices);
            $package->setIsActive(true);
            $this->entityManager->persist($package);
        }

        $this->entityManager->flush();

        $io->success('Database seeded with sample data!');

        return Command::SUCCESS;
    }
}
