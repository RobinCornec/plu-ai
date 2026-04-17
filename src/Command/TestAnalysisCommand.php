<?php

namespace App\Command;

use App\Message\AnalyzeDocumentsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:test-analysis',
    description: 'Envoie un message de test pour analyser une parcelle (Quimper par défaut)',
)]
class TestAnalysisCommand extends Command
{
    private MessageBusInterface $messageBus;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    private \App\Service\GpuService $gpuService;

    public function __construct(
        MessageBusInterface $messageBus,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        \App\Service\GpuService $gpuService
    ) {
        parent::__construct();
        $this->messageBus = $messageBus;
        $this->entityManager = $entityManager;
        $this->gpuService = $gpuService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Données de test pour Quimper
        $geocodeResult = [
            'coordinates' => ['lng' => -4.102035, 'lat' => 47.996194],
            'properties' => ['label' => 'Quimper Centre (Test)']
        ];

        $point = [
            'type' => 'Point',
            'coordinates' => [$geocodeResult['coordinates']['lng'], $geocodeResult['coordinates']['lat']]
        ];
        $urbanData = $this->gpuService->getUrbanData($point);

        $io->info('Création d\'une entité Analysis de test...');
        $analysis = new \App\Entity\Analysis();
        $analysis->setUserId('test@example.com');
        $analysis->setGeocodeResult($geocodeResult);
        $analysis->setPluData($urbanData);
        $analysis->setStatus('pending');
        $this->entityManager->persist($analysis);
        $this->entityManager->flush();

        $io->info('Envoi du message d\'analyse pour Quimper...');

        $this->messageBus->dispatch(new AnalyzeDocumentsMessage(
            'test@example.com',
            $geocodeResult,
            $urbanData,
            $urbanData['documents'] ?? [],
            $analysis->getId()
        ));

        $io->success(sprintf('Message envoyé avec Analysis ID %d ! Lancez "php bin/console messenger:consume async -vv" pour traiter.', $analysis->getId()));

        return Command::SUCCESS;
    }
}
