<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Random\RandomException;

#[ORM\Entity(repositoryClass: \App\Repository\AnalysisRepository::class)]
#[ORM\Table(name: 'analysis')]
class Analysis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, unique: true)]
    private string $token;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $userId;

    #[ORM\Column(type: Types::JSON)]
    private array $documentIds = [];

    #[ORM\Column(type: Types::JSON)]
    private array $geocodeResult = [];

    #[ORM\Column(type: Types::JSON)]
    private array $pluData = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pluSummary = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $documentAnalysis = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $documentSummary = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::STRING, length: 50, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $progress = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $progressDetails = null;

    #[ORM\ManyToMany(targetEntity: Document::class, inversedBy: 'analyses')]
    #[ORM\JoinTable(name: 'analysis_document')]
    private Collection $documents;

    /**
     * @throws RandomException
     */
    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->token = bin2hex(random_bytes(16));
        $this->documents = new ArrayCollection();
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): self
    {
        if (!$this->documents->contains($document)) {
            $this->documents[] = $document;
        }
        return $this;
    }

    public function removeDocument(Document $document): self
    {
        $this->documents->removeElement($document);
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getDocumentIds(): array
    {
        return $this->documentIds;
    }

    public function setDocumentIds(array $documentIds): self
    {
        $this->documentIds = $documentIds;
        return $this;
    }

    public function getGeocodeResult(): array
    {
        return $this->geocodeResult;
    }

    public function setGeocodeResult(array $geocodeResult): self
    {
        $this->geocodeResult = $geocodeResult;
        return $this;
    }

    public function getPluData(): array
    {
        return $this->pluData;
    }

    public function setPluData(array $pluData): self
    {
        $this->pluData = $pluData;
        return $this;
    }

    public function getPluSummary(): ?string
    {
        return $this->pluSummary;
    }

    public function setPluSummary(?string $pluSummary): self
    {
        $this->pluSummary = $pluSummary;
        return $this;
    }

    public function getDocumentAnalysis(): ?array
    {
        return $this->documentAnalysis;
    }

    public function setDocumentAnalysis(?array $documentAnalysis): self
    {
        $this->documentAnalysis = $documentAnalysis;
        return $this;
    }

    public function getDocumentSummary(): ?string
    {
        return $this->documentSummary;
    }

    public function setDocumentSummary(?string $documentSummary): self
    {
        $this->documentSummary = $documentSummary;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function markAsCompleted(): self
    {
        $this->status = 'completed';
        $this->completedAt = new DateTimeImmutable();
        return $this;
    }

    public function markAsFailed(): self
    {
        $this->status = 'failed';
        $this->completedAt = new DateTimeImmutable();
        return $this;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function setProgress(int $progress): self
    {
        $this->progress = $progress;
        return $this;
    }

    public function getProgressDetails(): ?array
    {
        return $this->progressDetails;
    }

    public function setProgressDetails(?array $progressDetails): self
    {
        $this->progressDetails = $progressDetails;
        return $this;
    }
}
