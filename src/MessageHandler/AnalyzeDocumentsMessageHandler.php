<?php

namespace App\MessageHandler;

use App\Entity\Document;
use App\Message\AnalyzeDocumentsMessage;
use App\Entity\DocumentChunk;
use App\Service\ChatGptService;
use App\Service\GpuService;
use App\Service\PdfAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Event\Telemetry\Info;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsMessageHandler]
class AnalyzeDocumentsMessageHandler
{
    private LoggerInterface $logger;
    private GpuService $gpuService;
    private PdfAnalysisService $pdfService;
    private ChatGptService $chatGptService;
    private EntityManagerInterface $entityManager;
    private string $tempDir;

    public function __construct(
        LoggerInterface $logger,
        GpuService $gpuService,
        PdfAnalysisService $pdfService,
        ChatGptService $chatGptService,
        EntityManagerInterface $entityManager,
        ParameterBagInterface $params
    ) {
        $this->logger = $logger;
        $this->gpuService = $gpuService;
        $this->pdfService = $pdfService;
        $this->chatGptService = $chatGptService;
        $this->entityManager = $entityManager;
        $this->tempDir = $params->get('kernel.project_dir') . '/var/tmp';

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    public function __invoke(AnalyzeDocumentsMessage $message): void
    {
        set_time_limit(600); // Augmenter le temps d'exécution à 10 minutes pour le worker
        $geocodeResult = $message->getGeocodeResult();
        $analysisId = $message->getAnalysisId();
        $documents = $message->getDocuments();
        $this->logger->info('Démarrage de l\'analyse pour : ' . ($geocodeResult['properties']['label'] ?? 'Inconnu'));

        $analysis = null;
        if ($analysisId) {
            $analysis = $this->entityManager->getRepository(\App\Entity\Analysis::class)->find($analysisId);
            if ($analysis) {
                $analysis->setStatus('processing');
                $this->entityManager->flush();
            }
        }

        foreach ($documents as $documentFeature) {
            $docId = $documentFeature['properties']['gpu_doc_id'] ?? $documentFeature['properties']['id'] ?? null;
            if (!$docId) continue;

            $this->logger->info('Récupération de la liste des fichiers pour le document : ' . $docId);
            $files = $this->gpuService->getFilesList($docId);

            // 2. Filtrage intelligent des fichiers : on priorise les règlements écrits
            $priorityFiles = array_filter($files, function($file) {
                $title = $file['title'] ?? '';
                $name = $file['name'] ?? '';
                $path = $file['path'] ?? '';
                return stripos($title, 'REGLEMENT') !== false ||
                       stripos($name, 'REGLEMENT') !== false ||
                       stripos($path, 'REGLEMENT') !== false;
            });

            $this->logger->info(sprintf('Nombre de fichiers trouvés : %d, Prioritaires : %d', count($files), count($priorityFiles)));

            if (empty($priorityFiles)) {
                $priorityFiles = $files;
            }

            // 3. Traitement séquentiel pour la mémoire
            foreach ($priorityFiles as $file) {
                $fileName = $file['name'] ?? null;
                if (!$fileName) continue;

                $fileDate = $documentFeature['properties']['gpu_timestamp'] ?? null;
                $downloadUrl = $this->gpuService->getFileDownloadUrl($docId, $fileName);

                // 4. Vérification de l'existence du document (Mutualisation)
                $existingDocument = $this->entityManager->getRepository(Document::class)->findOneBy([
                    'gpuDocId' => $docId,
                    'fileName' => $fileName,
                    'updateDate' => $fileDate
                ]);

                if ($existingDocument) {
                    $this->logger->info(sprintf('Réutilisation du document existant : %s', $fileName));
                    if ($analysis) {
                        $this->linkDocumentToAnalysis($analysis->getId(), $existingDocument->getId());
                        $analysis->addDocument($existingDocument);
                    }
                    continue;
                }

                $tempPath = $this->tempDir . '/' . uniqid() . '-' . $fileName;

                $this->logger->info('Analyse d\'un nouveau fichier : ' . $fileName);

                if ($this->gpuService->downloadDocument($downloadUrl, $tempPath)) {
                    try {
                        // Création du nouveau Document
                        $document = new Document();
                        $document->setGpuDocId($docId);
                        $document->setFileName($fileName);
                        $document->setUpdateDate($fileDate);
                        $document->setTitle($file['title'] ?? $fileName);
                        $document->setUrl($downloadUrl);

                        $this->entityManager->persist($document);
                        $this->entityManager->flush();

                        if ($analysis) {
                            $this->linkDocumentToAnalysis($analysis->getId(), $document->getId());
                            $analysis->addDocument($document);
                        }

                        // Extraction
                        $text = $this->pdfService->extractText($tempPath);

                        // Chunking par article
                        $articles = $this->pdfService->splitIntoArticles($text);

                        $this->logger->info(sprintf('Extrait %d articles de %s', count($articles), $fileName));

                        foreach ($articles as $article) {
                            $chunk = new DocumentChunk();
                            $chunk->setContent($article['content']);
                            $chunk->setDocument($document);

                            $metadata = [
                                'source' => $fileName,
                                'title' => $article['title'] ?? $file['title'] ?? $fileName,
                                'url' => $downloadUrl,
                                'docId' => $docId,
                                'date' => $fileDate,
                                'hierarchy' => $article['hierarchy'] ?? [],
                                'isGlobal' => $article['isGlobal'] ?? false,
                                'page' => $article['page'] ?? 1
                            ];

                            $chunk->setMetadata($metadata);

                            // Vectorisation
                            $embedding = $this->chatGptService->generateEmbedding($article['content']);
                            if ($embedding) {
                                $this->saveChunkWithVector($chunk, $embedding);
                            }
                        }

                        $this->entityManager->flush();
                        $this->entityManager->clear(); // Libère la mémoire

                        // Recharger l'analysis après le clear
                        if ($analysisId) {
                            $analysis = $this->entityManager->getRepository(\App\Entity\Analysis::class)->find($analysisId);
                        }

                    } catch (\Exception $e) {
                        $this->logger->error('Erreur lors de l\'analyse de ' . $fileName . ' : ' . $e->getMessage());
                    } finally {
                        if (file_exists($tempPath)) {
                            unlink($tempPath);
                        }
                    }
                }
            }
        }

        if ($analysisId) {
            $analysis = $this->entityManager->getRepository(\App\Entity\Analysis::class)->find($analysisId);
            if ($analysis) {
                $analysis->markAsCompleted();
                $this->entityManager->flush();
            }
        }

        $this->logger->info('Analyse terminée pour : ' . ($geocodeResult['properties']['label'] ?? 'Inconnu'));
    }

    private function saveChunkWithVector(DocumentChunk $chunk, array $embedding): void
    {
        $vectorStr = '[' . implode(',', $embedding) . ']';

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO document_chunk (content, metadata, embedding, document_id) VALUES (?, ?, ?::vector, ?)',
            [
                $chunk->getContent(),
                json_encode($chunk->getMetadata(), JSON_UNESCAPED_UNICODE),
                $vectorStr,
                $chunk->getDocument()->getId()
            ]
        );
    }

    private function linkDocumentToAnalysis(int $analysisId, int $documentId): void
    {
        $conn = $this->entityManager->getConnection();

        // Vérifier si le lien existe déjà
        $exists = $conn->fetchOne(
            'SELECT 1 FROM analysis_document WHERE analysis_id = ? AND document_id = ?',
            [$analysisId, $documentId]
        );

        if (!$exists) {
            $conn->executeStatement(
                'INSERT INTO analysis_document (analysis_id, document_id) VALUES (?, ?)',
                [$analysisId, $documentId]
            );
        }
    }

    // Ancienne méthode reuseExistingChunks supprimée car intégrée dans __invoke via Document entity
}
