<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Message\AnalyzeDocumentsMessage;
use App\Service\ChatGptService;
use App\Service\GeocodingService;
use App\Service\GpuService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class SearchController extends AbstractController
{
    private GeocodingService $geocodingService;
    private GpuService $gpuService;
    private ChatGptService $chatGptService;
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    public function __construct(
        GeocodingService $geocodingService,
        GpuService $gpuService,
        ChatGptService $chatGptService,
        MessageBusInterface $messageBus,
        EntityManagerInterface $entityManager
    ) {
        $this->geocodingService = $geocodingService;
        $this->gpuService = $gpuService;
        $this->chatGptService = $chatGptService;
        $this->messageBus = $messageBus;
        $this->entityManager = $entityManager;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    #[Route('/api/autocomplete', name: 'app_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        $query = $request->query->get('query', '');

        if (empty($query) || strlen($query) < 2) {
            return $this->json([
                'results' => []
            ]);
        }

        // Get autocomplete suggestions from the geocoding service
        $suggestions = $this->geocodingService->getAutocompleteSuggestions($query);

        return $this->json([
            'results' => $suggestions
        ]);
    }


    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    #[Route('/api/search', name: 'app_search', methods: ['POST'])]
    public function search(Request $request): JsonResponse
    {
        set_time_limit(120); // Augmenter le temps pour la recherche et le résumé initial
        $data = json_decode($request->getContent(), true);
        $query = $data['query'] ?? '';
        $codeInsee = $data['code_insee'] ?? null;

        if (empty($query)) {
            return $this->json([
                'success' => false,
                'error' => 'Query parameter is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $metadata = null;
        if ($codeInsee !== null) {
            $metadata = ['code_insee' => $codeInsee];
        }

        $geocodeResult = $this->geocodingService->geocode($query, $metadata);

        if (!$geocodeResult) {
            return $this->json([
                'success' => false,
                'error' => 'Location not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Étape 1 : Récupération des données structurées GpU
        $urbanData = [];
        $summary = null;
        $analysisInfo = [
            'ready' => false,
            'token' => null,
        ];

        if (isset($geocodeResult['coordinates'])) {
            $point = [
                'type' => 'Point',
                'coordinates' => [$geocodeResult['coordinates']['lng'], $geocodeResult['coordinates']['lat']]
            ];

            $urbanData = $this->gpuService->getUrbanData($point);

            // Avant de générer le résumé, vérifie si une analyse existe déjà pour ces documents
            $documentsToAnalyze = [];
            foreach ($urbanData['documents'] ?? [] as $docFeature) {
                $props = $docFeature['properties'] ?? [];
                $docId = $props['gpu_doc_id'] ?? $props['id'] ?? null;
                if (!$docId) {
                    continue;
                }
                try {
                    $files = $this->gpuService->getFilesList($docId);
                    foreach ($files as $file) {
                        if ($this->isPriorityDocument($file)) {
                            $documentsToAnalyze[] = [
                                'gpuDocId' => $docId,
                                'fileName' => $file['name'],
                                'updateDate' => $file['date'] ?? null,
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    // En cas d'erreur API ponctuelle, on ignore pour ne pas bloquer la recherche
                }
            }

            if (!empty($documentsToAnalyze)) {
                $existingAnalysis = $this->entityManager->getRepository(Analysis::class)->findExistingAnalysis($documentsToAnalyze);
                if ($existingAnalysis) {
                    $analysisInfo = [
                        'ready' => true,
                        'token' => $existingAnalysis->getToken(),
                    ];
                }
            }

            // Génération d'un résumé IA rapide basé sur ces données
            $summary = $this->chatGptService->generateQuickSummary($urbanData);
        }

        return $this->json([
            'success' => true,
            'geocode' => $geocodeResult,
            'urbanData' => $urbanData,
            'summary' => $summary,
            'analysis' => $analysisInfo,
        ]);
    }

    /**
     * @throws ExceptionInterface
     */
    #[Route('/api/chat-request', name: 'app_chat_request', methods: ['POST'])]
    public function chatRequest(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $geocodeResult = $data['geocode'] ?? null;
        $urbanData = $data['urbanData'] ?? [];
        $email = $data['email'] ?? 'anonymous@example.com';

        if (!$geocodeResult) {
            return $this->json([
                'success' => false,
                'error' => 'Geocode result is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Vérification si une analyse existe déjà pour ces documents
        $documentsToAnalyze = [];
        foreach ($urbanData['documents'] ?? [] as $docFeature) {
            $props = $docFeature['properties'] ?? [];
            $docId = $props['gpu_doc_id'] ?? $props['id'] ?? null;
            if (!$docId) continue;

            $files = $this->gpuService->getFilesList($docId);
            foreach ($files as $file) {
                if ($this->isPriorityDocument($file)) {
                    $documentsToAnalyze[] = [
                        'gpuDocId' => $docId,
                        'fileName' => $file['name'],
                        'updateDate' => $file['date'] ?? null,
                    ];
                }
            }
        }

        if (!empty($documentsToAnalyze)) {
            $existingAnalysis = $this->entityManager->getRepository(Analysis::class)->findExistingAnalysis($documentsToAnalyze);
            if ($existingAnalysis) {
                return $this->json([
                    'success' => true,
                    'already_exists' => true,
                    'message' => 'Une analyse existe déjà pour ces documents.',
                    'token' => $existingAnalysis->getToken()
                ]);
            }
        }

        // Créer l'entité Analysis pour le suivi
        $analysis = new Analysis();
        $analysis->setUserId($email);
        $analysis->setGeocodeResult($geocodeResult);
        $analysis->setPluData($urbanData);
        $analysis->setStatus('pending');

        $this->entityManager->persist($analysis);
        $this->entityManager->flush();

        // Dispatch a simple message to the queue
        $this->messageBus->dispatch(new AnalyzeDocumentsMessage(
            $email,
            $geocodeResult,
            $urbanData,
            $urbanData['documents'] ?? [],
            $analysis->getId()
        ));

        return $this->json([
            'success' => true,
            'message' => 'Demande d\'analyse approfondie envoyée. Vous recevrez une notification quand elle sera prête.',
            'token' => $analysis->getToken()
        ]);
    }

    private function isPriorityDocument(array $file): bool
    {
        $name = strtolower($file['name']);
        $title = strtolower($file['title'] ?? '');
        $path = strtolower($file['path'] ?? '');

        $isReglement = str_contains($name, 'reglement') ||
            str_contains($title, 'reglement') ||
            str_contains($path, 'reglement');

        $isOap = str_contains($name, 'oap') ||
            str_contains($title, 'oap') ||
            str_contains($path, 'oap');

        return $isReglement || $isOap;
    }

    #[Route('/api/analysis-status/{token}', name: 'app_analysis_status', methods: ['GET'])]
    public function status(string $token): JsonResponse
    {
        $analysis = $this->entityManager->getRepository(Analysis::class)->findOneBy(['token' => $token]);

        if (!$analysis) {
            return $this->json(['error' => 'Analyse non trouvée'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'status' => $analysis->getStatus(),
            'progress' => $analysis->getProgress(),
            'progressDetails' => $analysis->getProgressDetails()
        ]);
    }
}
