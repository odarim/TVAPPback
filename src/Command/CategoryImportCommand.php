<?php

namespace App\Command;

use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-categories',
    description: 'Import a specific list of categories.',
)]
class CategoryImportCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $categoryNames = [
            'All Channels', 'Animation', 'Auto', 'Business', 'Classic', 
            'Comedy', 'Cooking', 'Culture', 'Documentary', 'Education', 
            'Entertainment', 'Family', 'General', 'Kids', 'Legislative', 
            'Lifestyle', 'Movies', 'Music', 'News', 'Outdoor', 
            'Relax', 'Religious', 'Science', 'Series', 'Shop', 
            'Show', 'Sports', 'Top News', 'Travel', 'Weather'
        ];

        foreach ($categoryNames as $name) {
            $existing = $this->entityManager->getRepository(Category::class)->findOneBy(['name' => $name]);
            if (!$existing) {
                $category = new Category();
                $category->setName($name);
                $category->setSlug(strtolower(str_replace(' ', '-', $name)));
                $this->entityManager->persist($category);
                $io->note(sprintf('Added category: %s', $name));
            } else {
                $io->note(sprintf('Category already exists: %s', $name));
            }
        }

        $this->entityManager->flush();
        $io->success('Categories imported successfully!');

        return Command::SUCCESS;
    }
}
