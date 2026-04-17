<?php

namespace App\Repository;

use App\Entity\DocumentChunk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentChunk>
 *
 * @method DocumentChunk|null find($id, $lockMode = null, $lockVersion = null)
 * @method DocumentChunk|null findOneBy(array $criteria, array $orderBy = null)
 * @method DocumentChunk[]    findAll()
 * @method DocumentChunk[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DocumentChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentChunk::class);
    }

    public function add(DocumentChunk $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findSimilarChunks(\App\Entity\Analysis $analysis, array $embedding, int $limit = 10, ?string $zoneCode = null): array
    {
        $vectorStr = '[' . implode(',', $embedding) . ']';
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT dc.content, dc.metadata
            FROM document_chunk dc
            JOIN analysis_document ad ON dc.document_id = ad.document_id
            WHERE ad.analysis_id = :analysisId
        ';

        $params = [
            'analysisId' => $analysis->getId(),
            'embedding' => $vectorStr,
            'limit' => $limit,
        ];

        // Si on a un code de zone, on filtre les fragments qui mentionnent cette zone dans leur hiérarchie
        // OU qui sont marqués comme globaux.
        // On améliore le filtre pour capturer le radical (ex: UA si on cherche UAc) sans matcher d'autres zones (ex: UHa)
        if ($zoneCode) {
            // Extrait "UA" de "UAc" ou "UA(b)"
            $radical = preg_replace('/[^A-Z]+$/i', '', $zoneCode);

            $sql .= " AND (
                dc.metadata->'hierarchy'->>'zone' ~* :zoneRegex
                OR dc.metadata->'hierarchy'->>'secteur' ~* :zoneRegex
                OR dc.metadata->'hierarchy'->>'zone' ~* :radicalRegex
                OR dc.metadata->'hierarchy'->>'secteur' ~* :radicalRegex
                OR dc.metadata->>'isGlobal' = 'true'
                OR dc.content ~* :zoneRegex
            )";
            // Regex PostgreSQL : \y correspond à une limite de mot (word boundary)
            $params['zoneRegex'] = '\y' . preg_quote($zoneCode) . '\y';
            $params['radicalRegex'] = '\y' . preg_quote($radical) . '\y';
        }

        // On trie par similarité vectorielle
        // On pourrait ajouter une pondération : dc.embedding <=> :embedding::vector - (CASE WHEN dc.metadata->'hierarchy'->>'secteur' ~* :zoneRegex THEN 0.1 ELSE 0 END)
        $sql .= ' ORDER BY dc.embedding <=> :embedding::vector LIMIT :limit';

        $stmt = $conn->executeQuery($sql, $params);

        return $stmt->fetchAllAssociative();
    }
}
