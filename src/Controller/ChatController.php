<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Service\ChatbotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/chat', name: 'api_chat_')]
class ChatController extends AbstractController
{
    private ChatbotService $chatbotService;
    private EntityManagerInterface $entityManager;

    public function __construct(ChatbotService $chatbotService, EntityManagerInterface $entityManager)
    {
        $this->chatbotService = $chatbotService;
        $this->entityManager = $entityManager;
    }

    #[Route('/{token}', name: 'ask', methods: ['POST'])]
    public function ask(string $token, Request $request): JsonResponse
    {
        set_time_limit(300); // Augmenter le temps d'exécution PHP à 5 minutes
        $analysis = $this->entityManager->getRepository(Analysis::class)->findOneBy(['token' => $token]);

        if (!$analysis) {
            return new JsonResponse(['error' => 'Analyse non trouvée'], 404);
        }

        if ($analysis->getStatus() !== 'completed') {
            return new JsonResponse(['error' => 'L\'analyse est encore en cours. Veuillez patienter.'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $question = $data['question'] ?? '';

        if (empty($question)) {
            return new JsonResponse(['error' => 'La question ne peut pas être vide'], 400);
        }

        // Récupérer le code de zone depuis les données de l'analyse
        $pluData = $analysis->getPluData();
        $zoneCode = null;
        if (!empty($pluData['zones'])) {
            // On prend le libellé de la première zone trouvée
            $zoneCode = $pluData['zones'][0]['properties']['libelle'] ?? null;
        }

        try {
            $response = $this->chatbotService->askQuestion($analysis, $question, $zoneCode);

            return new JsonResponse([
                'success' => true,
                'response' => $response
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Une erreur est survenue lors de la génération de la réponse : ' . $e->getMessage()
            ], 500);
        }
    }
}
