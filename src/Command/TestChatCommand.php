<?php

namespace App\Command;

use App\Entity\Analysis;
use App\Service\ChatbotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-chat',
    description: 'Teste le chatbot sur une analyse donnée',
)]
class TestChatCommand extends Command
{
    private ChatbotService $chatbotService;
    private EntityManagerInterface $entityManager;

    public function __construct(ChatbotService $chatbotService, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->chatbotService = $chatbotService;
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('token', InputArgument::REQUIRED, 'Token de l\'analyse')
            ->addArgument('question', InputArgument::REQUIRED, 'Question à poser')
            ->addOption('zone', null, InputOption::VALUE_REQUIRED, 'Code de zone pour filtrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = $input->getArgument('token');
        $question = $input->getArgument('question');
        $zone = $input->getOption('zone');

        $analysis = $this->entityManager->getRepository(Analysis::class)->findOneBy(['token' => $token]);

        if (!$analysis) {
            $io->error('Analyse non trouvée');
            return Command::FAILURE;
        }

        $io->info(sprintf('Question: %s', $question));
        if ($zone) {
            $io->info(sprintf('Zone filtrée: %s', $zone));
        }

        $io->note('Attente de la réponse de l\'IA...');

        try {
            $response = $this->chatbotService->askQuestion($analysis, $question, $zone);
            $io->success('Réponse de l\'IA :');
            $output->writeln($response);
        } catch (\Exception $e) {
            $io->error('Erreur: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
