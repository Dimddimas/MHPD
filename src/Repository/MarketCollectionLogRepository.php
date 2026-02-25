<?php

namespace App\Repository;

use App\Entity\MarketCollectionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketCollectionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketCollectionLog::class);
    }

    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Filtrado por período e status.
     * Usa created_at (não existe collected_date nesta tabela).
     */
    public function findFiltered(string $dateFrom, string $dateTo, ?string $status = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.createdAt BETWEEN :df AND :dt')
            ->setParameter('df', new \DateTime($dateFrom . ' 00:00:00'))
            ->setParameter('dt', new \DateTime($dateTo   . ' 23:59:59'))
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($status) {
            $qb->andWhere('l.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getArrayResult();
    }

    public function findStatusSummary(): array
    {
        return $this->createQueryBuilder('l')
            ->select('l.status', 'COUNT(l.id) as total')
            ->where('l.status IS NOT NULL')
            ->groupBy('l.status')
            ->getQuery()
            ->getArrayResult();
    }
}
