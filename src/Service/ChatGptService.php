<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ChatGptService
{
    private HttpClientInterface $httpClient;
    private ?string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct(
        HttpClientInterface $httpClient,
        ParameterBagInterface $params
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $params->get('app.openai_api_key');
        $this->baseUrl = $params->get('app.ai_base_url');
        $this->model = $params->get('app.ai_model');
    }

    public function generateQuickSummary(array $urbanData): string
    {
        if (empty($this->baseUrl)) {
            return "Résumé non disponible (Configuration LLM manquante).";
        }

        $zones = $urbanData['zones'] ?? [];
        $prescriptions = $urbanData['prescriptions'] ?? [];

        $context = "Données d'urbanisme pour une parcelle :\n";
        foreach ($zones as $zone) {
            $props = $zone['properties'] ?? [];
            $context .= "- Zone : " . ($props['libelle'] ?? 'N/A') . " (" . ($props['typezone'] ?? 'N/A') . ")\n";
        }
        foreach ($prescriptions as $p) {
            $props = $p['properties'] ?? [];
            $context .= "- Prescription : " . ($props['libelle'] ?? 'N/A') . "\n";
        }

        $prompt = "En tant qu'expert en urbanisme, fais un résumé très court (3-4 phrases) des règles principales pour cette parcelle à partir des données suivantes. Sois précis sur le type de zone. Si aucune donnée n'est fournie, indique que les informations sont limitées.\n\n" . $context;

        return $this->askChat($prompt);
    }

    public function explainZoneType(string $typeZone, string $libelle): string
    {
        $prompt = "En tant qu'expert en urbanisme français, explique brièvement ce qu'est une zone de type '$typeZone' ($libelle) dans un PLU. Quelles sont les intentions générales de ce type de zone et quel genre de constructions y sont généralement autorisées ou interdites ? Réponds en 4-5 phrases maximum.";

        return $this->askChat($prompt);
    }

    public function generateEmbedding(string $text): ?array
    {
        if (empty($this->baseUrl)) {
            return null;
        }

        try {
            $url = rtrim($this->baseUrl, '/') . '/embeddings';
            $model = (strpos($this->baseUrl, '11434') !== false) ? 'nomic-embed-text' : $this->model;

            $response = $this->httpClient->request('POST', $url, [
                'timeout' => 300,
                'headers' => [
                    'Authorization' => 'Bearer ' . ($this->apiKey ?: 'no-key'),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'input' => $text,
                ],
            ]);

            $data = $response->toArray();
            if (isset($data['data'][0]['embedding'])) {
                return $data['data'][0]['embedding'];
            }
            if (isset($data['embedding'])) {
                return $data['embedding'];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function askChat(string $prompt, string $systemPrompt = 'Tu es un assistant spécialisé en urbanisme français.'): string
    {
        try {
            $url = rtrim($this->baseUrl, '/') . '/chat/completions';
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => 300,
                'headers' => [
                    'Authorization' => 'Bearer ' . ($this->apiKey ?: 'no-key'),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0,
                    'max_tokens' => 1000,
                    'options' => [
                        'temperature' => 0,
                        'num_ctx' => 16384,
                        'stop' => ["###"],
                    ]
                ],
            ]);

            $data = $response->toArray();
            return $data['choices'][0]['message']['content'] ?? "Erreur lors de la génération de la réponse.";
        } catch (\Exception $e) {
            return "Désolé, je ne peux pas répondre pour le moment. (" . $e->getMessage() . ")";
        }
    }
}
