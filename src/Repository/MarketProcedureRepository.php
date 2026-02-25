<?php

namespace App\Repository;

use App\Entity\MarketProcedure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketProcedureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketProcedure::class);
    }

    /** Para os selects dos filtros — retorna id, name, tuss_code, type */
    public function findOneById(?string $id): ?array
    {
        if (!$id) return null;
        return $this->createQueryBuilder('p')
            ->select('p.id', 'p.name', 'p.tussCode', 'p.type')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);
    }

    public function findAllForSelect(): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id', 'p.name', 'p.tussCode', 'p.type')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /** Tipos distintos de exame (coluna 'type') */
    public function findTypes(): array
    {
        return $this->createQueryBuilder('p')
            ->select('DISTINCT p.type')
            ->where('p.type IS NOT NULL')
            ->orderBy('p.type', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
