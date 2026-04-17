<?php

namespace App\Entity;

use App\Repository\DocumentChunkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentChunkRepository::class)]
class DocumentChunk
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'documentChunks')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Document $document = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    // Note: 'vector' type requires custom DBAL type registration or using a raw SQL type
    // For now we map it as a generic type, we will use raw SQL if needed for search
    #[ORM\Column(type: 'vector', nullable: true, options: ['geometry_type' => 'vector', 'dimensions' => 768])]
    private $embedding = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): self
    {
        $this->document = $document;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getEmbedding()
    {
        return $this->embedding;
    }

    public function setEmbedding($embedding): self
    {
        $this->embedding = $embedding;
        return $this;
    }
}
