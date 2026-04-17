<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $gpuDocId = null;

    #[ORM\Column(length: 255)]
    private ?string $fileName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $updateDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $url = null;

    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentChunk::class, orphanRemoval: true)]
    private $documentChunks;

    #[ORM\ManyToMany(targetEntity: Analysis::class, mappedBy: 'documents')]
    private $analyses;

    public function __construct()
    {
        $this->documentChunks = new \Doctrine\Common\Collections\ArrayCollection();
        $this->analyses = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGpuDocId(): ?string
    {
        return $this->gpuDocId;
    }

    public function setGpuDocId(string $gpuDocId): self
    {
        $this->gpuDocId = $gpuDocId;
        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getUpdateDate(): ?string
    {
        return $this->updateDate;
    }

    public function setUpdateDate(?string $updateDate): self
    {
        $this->updateDate = $updateDate;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, DocumentChunk>
     */
    public function getDocumentChunks(): \Doctrine\Common\Collections\Collection
    {
        return $this->documentChunks;
    }

    public function addDocumentChunk(DocumentChunk $documentChunk): self
    {
        if (!$this->documentChunks->contains($documentChunk)) {
            $this->documentChunks[] = $documentChunk;
            $documentChunk->setDocument($this);
        }
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Analysis>
     */
    public function getAnalyses(): \Doctrine\Common\Collections\Collection
    {
        return $this->analyses;
    }
}
