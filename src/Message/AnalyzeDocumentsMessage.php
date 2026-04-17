<?php

namespace App\Message;

class AnalyzeDocumentsMessage
{
    private string $userId;
    private array $geocodeResult;
    private array $urbanData;
    private array $documents;
    private ?int $analysisId;

    public function __construct(
        string $userId,
        array $geocodeResult,
        array $urbanData,
        array $documents,
        ?int $analysisId = null
    ) {
        $this->userId = $userId;
        $this->geocodeResult = $geocodeResult;
        $this->urbanData = $urbanData;
        $this->documents = $documents;
        $this->analysisId = $analysisId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getGeocodeResult(): array
    {
        return $this->geocodeResult;
    }

    public function getUrbanData(): array
    {
        return $this->urbanData;
    }

    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function getAnalysisId(): ?int
    {
        return $this->analysisId;
    }
}
