<?php

namespace App\Repository;

use App\Entity\MarketUnit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketUnit::class);
    }

    /**
     * Para os selects dos filtros.
     * Usa facility_name como nome principal (fallback: social_name)
     */
    public function findOneById(?string $id): ?array
    {
        if (!$id) return null;
        return $this->createQueryBuilder('u')
            ->select('u.id', 'u.socialName', 'u.facilityName', 'u.city', 'u.state')
            ->where('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);
    }

    public function findAllForSelect(): array
    {
        return $this->createQueryBuilder('u')
            ->select(
                'u.id',
                'u.socialName',
                'u.facilityName',
                'u.city',
                'u.state',
                'u.ratingAvg',
                'u.ratingCount'
            )
            ->orderBy('u.facilityName', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }
}
