<?php

namespace App\Repository;

use App\Entity\Analysis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Analysis>
 *
 * @method Analysis|null find($id, $lockMode = null, $lockVersion = null)
 * @method Analysis|null findOneBy(array $criteria, array $orderBy = null)
 * @method Analysis[]    findAll()
 * @method Analysis[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Analysis::class);
    }

    /**
     * Trouve une analyse déjà complétée pour un ensemble de documents GpU.
     * Un document est identifié par son gpuDocId, fileName et updateDate.
     */
    public function findExistingAnalysis(array $documentData): ?Analysis
    {
        if (empty($documentData)) {
            return null;
        }

        $qb = $this->createQueryBuilder('a')
            ->select('a')
            ->join('a.documents', 'd')
            ->where('a.status = :status')
            ->setParameter('status', 'completed');

        // On cherche une analyse qui contient EXACTEMENT les mêmes documents
        // ou au moins tous les documents prioritaires (Règlement).
        // Simplification : on cherche une analyse qui possède au moins les documents passés en paramètre.

        $count = 0;
        foreach ($documentData as $doc) {
            $alias = 'd' . $count;
            $qb->join('a.documents', $alias)
               ->andWhere($alias . '.gpuDocId = :gpuId' . $count)
               ->andWhere($alias . '.fileName = :fileName' . $count)
               ->setParameter('gpuId' . $count, $doc['gpuDocId'])
               ->setParameter('fileName' . $count, $doc['fileName']);

            $count++;
        }

        // On prend la plus récente
        return $qb->orderBy('a.completedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
