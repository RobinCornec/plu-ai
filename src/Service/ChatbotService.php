<?php

namespace App\Service;

use App\Entity\Analysis;
use App\Repository\DocumentChunkRepository;
use Psr\Log\LoggerInterface;

class ChatbotService
{
    private ChatGptService $aiService;
    private DocumentChunkRepository $chunkRepository;
    private LoggerInterface $logger;

    public function __construct(
        ChatGptService $aiService,
        DocumentChunkRepository $chunkRepository,
        LoggerInterface $logger
    ) {
        $this->aiService = $aiService;
        $this->chunkRepository = $chunkRepository;
        $this->logger = $logger;
    }

    /**
     * @param Analysis $analysis
     * @param string $question
     * @param string|null $zoneCode Le code de zone (ex: UA, UH) pour filtrer les résultats
     * @return string
     */
    public function askQuestion(Analysis $analysis, string $question, ?string $zoneCode = null): string
    {
        // 1. Générer l'embedding de la question
        $questionEmbedding = $this->aiService->generateEmbedding($question);
        if (!$questionEmbedding) {
            return "Désolé, je ne peux pas traiter votre question pour le moment (erreur technique d'indexation).";
        }

        // 2. Chercher les fragments les plus similaires
        $this->logger->info("Recherche de chunks pour la zone : " . ($zoneCode ?? 'aucune'));
        // On récupère plus de chunks pour faire un re-ranking local basé sur des mots-clés
        $chunks = $this->chunkRepository->findSimilarChunks($analysis, $questionEmbedding, 40, $zoneCode);

        if (empty($chunks)) {
            $this->logger->warning("Aucun chunk trouvé pour l'analyse " . $analysis->getId());
            return "Désolé, je n'ai pas trouvé d'informations pertinentes dans les documents pour répondre à votre question.";
        }

        $keywords = ['hauteur', 'égout', 'faîtage', 'acrotère', 'implantation', 'recul', 'voirie', 'accès', 'stationnement', 'emprise', 'ces', 'clôture', 'aspect'];
        $importantWords = [];
        foreach ($keywords as $word) {
            if (mb_stripos($question, $word) !== false) {
                $importantWords[] = $word;
            }
        }

        // On ajoute le zoneCode comme mot clé ultra important pour le re-ranking
        if ($zoneCode) {
            $importantWords[] = $zoneCode;
        }

        // Mots-clés étendus pour couvrir les questions fréquentes
        $synonyms = [
            'logement' => ['social', 'locatif', 'accession', 'sociale', 'HLM', 'mixité'],
            'social' => ['logement', 'locatif', 'accession', 'quota', 'pourcentage'],
            'vélo' => ['cycles', 'stationnement', 'local', 'moto', 'deux-roues'],
            'répurgation' => ['déchets', 'ordures', 'ménagères', 'collecte', 'local'],
            'limite' => ['séparative', 'recul', 'mitoyen', 'implantation'],
            'prix' => ['plafond', 'loyer', 'redevance'],
        ];

        foreach ($importantWords as $word) {
            $wordLower = mb_strtolower($word);
            if (isset($synonyms[$wordLower])) {
                $importantWords = array_merge($importantWords, $synonyms[$wordLower]);
            }
        }
        $importantWords = array_unique($importantWords);

        $zoneRadical = null;
        if ($zoneCode) {
            $zoneRadical = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $zoneCode), 0, 2));
        }
        if (!empty($importantWords)) {
            usort($chunks, function($a, $b) use ($importantWords, $zoneCode, $zoneRadical) {
                $scoreA = 0;
                $scoreB = 0;
                $metadataA = is_string($a['metadata']) ? json_decode($a['metadata'], true) : $a['metadata'];
                $metadataB = is_string($b['metadata']) ? json_decode($b['metadata'], true) : $b['metadata'];

                // Boost énorme si le secteur/zone matche exactement dans la hiérarchie
                if ($zoneCode) {
                    if (str_contains($metadataA['hierarchy']['secteur'] ?? '', $zoneCode)) $scoreA += 10;
                    if (str_contains($metadataB['hierarchy']['secteur'] ?? '', $zoneCode)) $scoreB += 10;
                    if (str_contains($metadataA['hierarchy']['zone'] ?? '', $zoneCode)) $scoreA += 5;
                    if (str_contains($metadataB['hierarchy']['zone'] ?? '', $zoneCode)) $scoreB += 5;
                }

                // Boost spécifique pour l'Article 10 de la zone (ex: ARTICLE UA 10)
                if ($zoneRadical) {
                    if (preg_match('/ARTICLE\s+' . preg_quote($zoneRadical, '/') . '\s*10/i', $metadataA['title'] ?? '')) $scoreA += 15;
                    if (preg_match('/ARTICLE\s+' . preg_quote($zoneRadical, '/') . '\s*10/i', $metadataB['title'] ?? '')) $scoreB += 15;
                }

                foreach ($importantWords as $word) {
                    if (mb_stripos($a['content'], $word) !== false) {
                        $scoreA += ($word === $zoneCode) ? 5 : 1;
                    }
                    if (mb_stripos($b['content'], $word) !== false) {
                        $scoreB += ($word === $zoneCode) ? 5 : 1;
                    }
                    if (mb_stripos($metadataA['title'] ?? '', $word) !== false) $scoreA += 2;
                    if (mb_stripos($metadataB['title'] ?? '', $word) !== false) $scoreB += 2;
                }

                return $scoreB <=> $scoreA; // Plus de mots clés en premier
            });
        }

        // On ne garde que les 10 meilleurs après re-ranking
        $chunks = array_slice($chunks, 0, 10);

        $this->logger->info(sprintf("Utilisation de %d chunks après re-ranking", count($chunks)));
        foreach ($chunks as $chunk) {
            $metadata = is_string($chunk['metadata']) ? json_decode($chunk['metadata'], true) : $chunk['metadata'];
            $this->logger->debug("Chunk: " . ($metadata['title'] ?? 'Sans titre'));
        }

        // 3. Préparer le contexte pour le LLM
        $zonalContext = "";
        $globalContext = "";

        // On ne garde que les 15 meilleurs après re-ranking
        $chunks = array_slice($chunks, 0, 15);

        foreach ($chunks as $chunk) {
            $metadata = is_string($chunk['metadata']) ? json_decode($chunk['metadata'], true) : $chunk['metadata'];
            $hierarchy = $metadata['hierarchy'] ?? [];
            $docName = $metadata['source'] ?? ($metadata['title'] ?? 'Document');
            $docUrl = $metadata['url'] ?? '';
            $source = sprintf(
                "Document: %s | Lien: %s | Article: %s | Page: %s | %s",
                $docName,
                $docUrl,
                $metadata['title'] ?? 'Sans titre',
                $metadata['page'] ?? '?',
                $this->formatHierarchy($hierarchy)
            );

            // Nettoyage des caractères non UTF-8 valides
            $cleanContent = mb_convert_encoding($chunk['content'], 'UTF-8', 'UTF-8');
            $cleanContent = preg_replace('/Page \d+ sur \d+/i', '', $cleanContent);
            $cleanContent = preg_replace('/Plan Local d’Urbanisme.*?\d{4}/i', '', $cleanContent);

            // Tronquer si trop long pour éviter de saturer le contexte du petit modèle local
            if (strlen($cleanContent) > 15000) {
                $cleanContent = mb_substr($cleanContent, 0, 15000) . "... [Tronqué]";
            }

            $formattedChunk = sprintf("### %s\n%s\n\n", $source, trim($cleanContent));

            if (($metadata['isGlobal'] ?? false) === true) {
                $globalContext .= $formattedChunk;
            } else {
                $zonalContext .= $formattedChunk;
            }
        }

        // 4. Appeler le LLM
        $systemPrompt = <<<EOT
Tu es un expert en urbanisme B2B. Tu dois extraire des informations FACTUELLES des extraits de PLU fournis, sans interpréter ni inventer.
Rappels OBLIGATOIRES :
- Cite toujours le **Document (lien téléchargeable)**, l'**Article exact** et la **Page**.
- Détaille toutes les CONDITIONS (largeur de voie, limites séparatives, retraits, cas particuliers).
- Si une donnée dépend d'informations non fournies (ex: largeur de voie, altitudes, plan), écris explicitement « Non vérifiable avec les seules données disponibles ».
- Si le contexte contient un tableau, REPRODUIS les intitulés de colonnes et les valeurs EXACTS tels qu'écrits (ne reformule pas).
- Ne répondre QUE sur la base du contexte. Si l'information n'est pas trouvée, indique « Non trouvé dans les extraits fournis ».
EOT;

        $userPrompt = <<<EOT
CONTEXTE TECHNIQUE (Règlement PLU) :
$zonalContext
$globalContext

QUESTION : $question (pour la zone $zoneCode)

CONSIGNE DE SORTIE :
- Réponds UNIQUEMENT par un tableau Markdown avec les colonnes : **Document (lien)** | **Article** | **Page** | **Détail (conditions, valeurs)**.
- Pour la colonne Document, fournis un lien Markdown cliquable au format \[NomDuFichier\](URL) en utilisant les informations de source et lien du contexte.
- Ne restitue QUE la ligne correspondant STRICTEMENT au secteur « $zoneCode » et reproduis les intitulés de colonnes exacts.
- Si des condtions existe, et que tu n'est pas capable de déterminer dans quel cas nous sommes, donnes toutes les conditions.
- Si des valeurs sont chiffrées (ex. hauteur) ne restitue QUE les valeurs exactes.
- N'inclus aucun article sectoriel hors-sujet.
- TRÈS IMPORTANT : Si tu ne trouves aucune information pertinente dans les extraits fournis pour répondre à la question, ne génère PAS de tableau. Réponds simplement par la phrase exacte : "Je ne trouve pas d'informations spécifiques sur ce sujet dans les extraits du règlement analysés."
EOT;

        $this->logger->debug("Contexte Zonal (1000 chars): " . substr($zonalContext, 0, 1000));
        $this->logger->debug("UserPrompt length: " . strlen($userPrompt));
        return $this->aiService->askChat($userPrompt, $systemPrompt);
    }

    private function formatHierarchy(array $hierarchy): string
    {
        $parts = [];
        if (!empty($hierarchy['titre'])) $parts[] = $hierarchy['titre'];
        if (!empty($hierarchy['zone'])) $parts[] = $hierarchy['zone'];
        if (!empty($hierarchy['section'])) $parts[] = $hierarchy['section'];
        if (!empty($hierarchy['secteur'])) $parts[] = $hierarchy['secteur'];
        if (!empty($hierarchy['orientation'])) $parts[] = $hierarchy['orientation'];

        return implode(' > ', $parts);
    }
}
