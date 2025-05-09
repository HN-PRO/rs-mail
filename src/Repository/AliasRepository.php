<?php

namespace App\Repository;

use App\Entity\Alias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Alias>
 */
class AliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alias::class);
    }

    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySourceAddress(string $sourceAddress): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.source LIKE :sourceAddress')
            ->setParameter('sourceAddress', '%' . $sourceAddress . '%')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByTargetAddress(string $targetAddress): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.destination LIKE :targetAddress')
            ->setParameter('targetAddress', '%' . $targetAddress . '%')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByDomain(string $domain): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.source LIKE :domain OR a.destination LIKE :domain')
            ->setParameter('domain', '%@' . $domain)
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
} 