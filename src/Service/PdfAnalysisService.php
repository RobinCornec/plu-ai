<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class PdfAnalysisService
{
    /**
     * Regex pour détecter les en-têtes de hiérarchie (Titre, Chapitre, Section, Zone, Secteur)
     * On devient plus strict pour éviter les faux positifs :
     * - Le mot clé doit être en MAJUSCULES ou capitalisé (ex: Titre 1 ou TITRE 1)
     * - On ajoute le support pour "Règlement applicable..." qui définit souvent la zone
     * - On ne met plus le flag /i pour éviter de capturer "titre accessoire" ou "secteurs en application"
     */
    private const HIERARCHY_PATTERN = '/^\s*((?:TITRE|Titre|CHAPITRE|Chapitre|SECTION|Section|SECTEUR|Secteur|ZONE|Zone|PARTIE|Partie|R[EÈ]GLEMENT\s+APPLICABLE|R[eè]glement\s+applicable|ORIENTATION|Orientation|OAP)S?\s+.*)$/u';

    /**
     * Regex pour détecter les Articles (doit être en début de ligne ou avec indentation)
     * On autorise un code de zone entre le mot Article et le numéro (ex: ARTICLE UA 1)
     */
    private const ARTICLE_PATTERN = '/^\s*((?:Article|ARTICLE)\s+(?:[A-ZÀ-Ÿ]{1,10}\s+)?(?:[A-Z]\.\s*)?[\d\.-]+.*)$/u';

    private const SUMMARY_PATTERN = '/\.{3,}\s*\d+$/';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Extracts text from a PDF file using pdftotext
     */
    public function extractText(string $pdfPath): string
    {
        if (!file_exists($pdfPath)) {
            throw new \InvalidArgumentException("Le fichier PDF n'existe pas : " . $pdfPath);
        }

        $process = new Process(['pdftotext', '-layout', $pdfPath, '-']);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error("Erreur lors de l'extraction PDF via pdftotext", [
                'path' => $pdfPath,
                'error' => $process->getErrorOutput()
            ]);

            // Tentative de fallback minimaliste si pdftotext échoue (très basique)
            return $this->extractTextBasique($pdfPath);
        }

        return (string) $process->getOutput();
    }

    /**
     * Split text into semantic chunks (articles) with hierarchy context
     *
     * @return array<int, array{title: string, hierarchy: array<string, string>, content: string, isGlobal: bool, page: int}>
     */
    public function splitIntoArticles(string $text): array
    {
        // On ne supprime plus les sauts de page mais on les utilise pour le comptage
        $lines = explode("\n", $text);
        $currentHierarchy = [];
        $articles = [];
        $currentPreamble = "";
        $isGlobal = false;
        $currentPage = 1;

        foreach ($lines as $line) {
            // Détection du saut de page (caractère Form Feed \x0C)
            if (str_contains($line, "\x0C")) {
                $currentPage++;
                $line = str_replace("\x0C", "", $line);
            }

            // On vérifie si la ligne est vide (en ignorant les espaces)
            if (trim($line) === '') {
                continue;
            }

            // On utilise rtrim pour garder les espaces à gauche (important pour -layout)
            $line = rtrim($line);

            // Tentative de détection de hiérarchie ou d'article
            if ($this->processLine($line, $currentHierarchy, $articles, $currentPreamble, $isGlobal, $currentPage)) {
                continue;
            }

            // Si ce n'est ni un titre ni un article, on l'ajoute au contenu courant
            $this->addContent($line, $articles, $currentPreamble);
        }

        return $this->finalizeArticles($articles, $currentPreamble);
    }

    /**
     * @param array<string, string> $currentHierarchy
     * @param array<int, array<string, mixed>> $articles
     */
    private function processLine(string $line, array &$currentHierarchy, array &$articles, string &$currentPreamble, bool &$isGlobal, int $currentPage): bool
    {
        // Détection de la hiérarchie
        if (preg_match(self::HIERARCHY_PATTERN, $line, $matches)) {
            $header = $matches[1];

            // Cas où on doit ignorer la hiérarchie et traiter comme du contenu (sommaire ou phrase trop longue)
            if ($this->shouldIgnoreHierarchy($header, $line)) {
                return false;
            }

            $this->updateHierarchy($header, $currentHierarchy, $isGlobal);

            return true;
        }

        // Détection d'un Article
        if (preg_match(self::ARTICLE_PATTERN, $line, $matches)) {
            $articles[] = [
                'title' => $matches[1],
                'hierarchy' => $currentHierarchy,
                'content' => $line . "\n",
                'isGlobal' => $isGlobal,
                'page' => $currentPage
            ];

            return true;
        }

        return false;
    }

    private function shouldIgnoreHierarchy(string $header, string $line): bool
    {
        // Si c'est une ligne de sommaire (beaucoup de points suivis d'un nombre), on ignore
        if (preg_match(self::SUMMARY_PATTERN, $line)) {
            return true;
        }

        // Si la ligne contient des grands espaces (>= 4), c'est probablement un tableau, pas un titre de hiérarchie
        if (preg_match('/\s{4,}/', $header)) {
            return true;
        }

        // Si c'est une phrase trop longue sans être un titre de zone explicite, on ignore
        if (strlen($header) > 200 && !stripos($header, 'ZONE')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, string> $currentHierarchy
     */
    private function updateHierarchy(string $header, array &$currentHierarchy, bool &$isGlobal): void
    {
        $headerUpper = mb_strtoupper($header);

        if (str_starts_with($headerUpper, 'TITRE')) {
            $currentHierarchy = ['titre' => $header];
            // Détection du Titre I ou Dispositions Générales comme global
            $isGlobal = str_contains($headerUpper, 'TITRE I')
                || str_contains($headerUpper, 'GÉNÉRAL')
                || str_contains($headerUpper, 'COMMUN');
        } elseif (str_starts_with($headerUpper, 'CHAPITRE')) {
            $currentHierarchy['chapitre'] = $header;
            unset($currentHierarchy['section'], $currentHierarchy['zone'], $currentHierarchy['secteur']);
        } elseif (str_starts_with($headerUpper, 'SECTION')) {
            $currentHierarchy['section'] = $header;
            unset($currentHierarchy['secteur']);
        } elseif ($this->isZoneHeader($headerUpper)) {
            // On change de zone, on réinitialise tout ce qui est en dessous du Titre
            $currentHierarchy['zone'] = $header;
            $isGlobal = false; // Dès qu'on entre dans une zone spécifique, ce n'est plus global
            unset($currentHierarchy['chapitre'], $currentHierarchy['section'], $currentHierarchy['secteur']);
        } elseif (str_starts_with($headerUpper, 'SECTEUR')) {
            $currentHierarchy['secteur'] = $header;
            $isGlobal = false;
        } elseif (str_starts_with($headerUpper, 'ORIENTATION') || str_starts_with($headerUpper, 'OAP')) {
            $currentHierarchy['orientation'] = $header;
            $isGlobal = false;
        }
    }

    private function isZoneHeader(string $headerUpper): bool
    {
        return str_contains($headerUpper, 'ZONE')
            || str_contains($headerUpper, 'RÈGLEMENT')
            || str_contains($headerUpper, 'REGLEMENT');
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     */
    private function addContent(string $line, array &$articles, string &$currentPreamble): void
    {
        if (!empty($articles)) {
            $articles[count($articles) - 1]['content'] .= $line . "\n";
        } else {
            $currentPreamble .= $line . "\n";
        }
    }

    /**
     * @param array<int, array{title: string, hierarchy: array<string, string>, content: string, isGlobal: bool, page: int}> $articles
     * @return array<int, array{title: string, hierarchy: array<string, string>, content: string, isGlobal: bool, page: int}>
     */
    private function finalizeArticles(array $articles, string $currentPreamble): array
    {
        // Si on a du préambule sans article (rare mais possible au début)
        if (!empty($currentPreamble) && empty($articles)) {
            $articles[] = [
                'title' => 'Préambule',
                'hierarchy' => [],
                'content' => $currentPreamble,
                'isGlobal' => true,
                'page' => 1
            ];
        }

        return $articles;
    }

    private function extractTextBasique(string $pdfPath): string
    {
        $content = file_get_contents($pdfPath);
        // Nettoyage très rudimentaire des caractères non-imprimables
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
    }
}
