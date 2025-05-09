<?php

namespace App\Repository;

use App\Entity\Domain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domain>
 */
class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByName(string $name): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.domain LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
} 