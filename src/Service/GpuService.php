<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class GpuService
{
    private HttpClientInterface $httpClient;
    private string $gpuApiUrl;
    private string $geoportailApiUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        ParameterBagInterface $params
    ) {
        $this->httpClient = $httpClient;
        $this->gpuApiUrl = $params->get('app.gpu_api_url');
        $this->geoportailApiUrl = $params->get('app.geoportail_api_url');
    }

    /**
     * Fetch urban data (zones and prescriptions) intersecting with the given geometry
     */
    public function getUrbanData(array $geometry): array
    {
        $geomJson = json_encode($geometry, JSON_UNESCAPED_UNICODE);

        $zones = $this->fetchFromGpu('/zone-urba', $geomJson);
        $prescriptions = $this->fetchFromGpu('/prescription-surf', $geomJson);
        $documents = $this->fetchFromGpu('/document', $geomJson);

        return [
            'zones' => $zones['features'] ?? [],
            'prescriptions' => $prescriptions['features'] ?? [],
            'documents' => $documents['features'] ?? [],
        ];
    }

    /**
     * Fetch list of files for a specific document ID from GpU API
     */
    public function getFilesList(string $docId): array
    {
        try {
            $url = rtrim($this->geoportailApiUrl, '/') . '/document/' . $docId . '/files';
            $response = $this->httpClient->request('GET', $url);

            return $response->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the download URL for a specific file in a document
     */
    public function getFileDownloadUrl(string $docId, string $fileName): string
    {
        return rtrim($this->geoportailApiUrl, '/') . '/document/' . $docId . '/files/' . $fileName;
    }

    /**
     * Downloads a document file from the GPU to a temporary local path
     */
    public function downloadDocument(string $url, string $destinationPath): bool
    {
        try {
            $response = $this->httpClient->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $fileHandler = fopen($destinationPath, 'w');
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($fileHandler, $chunk->getContent());
            }
            fclose($fileHandler);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function fetchFromGpu(string $endpoint, string $geomJson): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->gpuApiUrl . $endpoint, [
                'query' => [
                    'geom' => $geomJson
                ]
            ]);

            return $response->toArray();
        } catch (\Exception $e) {
            // In a real app, log the error
            return [];
        }
    }
}
