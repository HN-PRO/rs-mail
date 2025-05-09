<?php

namespace App\Repository;

use App\Entity\SystemLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends ServiceEntityRepository<SystemLog>
 */
class SystemLogRepository extends ServiceEntityRepository
{
    private $paginator;

    public function __construct(ManagerRegistry $registry, PaginatorInterface $paginator)
    {
        parent::__construct($registry, SystemLog::class);
        $this->paginator = $paginator;
    }

    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('sl')
            ->orderBy('sl.time', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    public function findAllSortedPaginated(int $page = 1, int $limit = 10): object
    {
        $query = $this->createQueryBuilder('sl')
            ->orderBy('sl.time', 'DESC')
            ->getQuery();
            
        return $this->paginator->paginate($query, $page, $limit);
    }

    public function findByOperator(string $operatorName): array
    {
        return $this->createQueryBuilder('sl')
            ->andWhere('sl.operatorName = :operatorName')
            ->setParameter('operatorName', $operatorName)
            ->orderBy('sl.time', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    public function findByOperatorPaginated(string $operatorName, int $page = 1, int $limit = 10): object
    {
        $query = $this->createQueryBuilder('sl')
            ->andWhere('sl.operatorName = :operatorName')
            ->setParameter('operatorName', $operatorName)
            ->orderBy('sl.time', 'DESC')
            ->getQuery();
            
        return $this->paginator->paginate($query, $page, $limit);
    }

    public function findByOperationType(string $operationType): array
    {
        return $this->createQueryBuilder('sl')
            ->andWhere('sl.operationType = :operationType')
            ->setParameter('operationType', $operationType)
            ->orderBy('sl.time', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    public function findByOperationTypePaginated(string $operationType, int $page = 1, int $limit = 10): object
    {
        $query = $this->createQueryBuilder('sl')
            ->andWhere('sl.operationType = :operationType')
            ->setParameter('operationType', $operationType)
            ->orderBy('sl.time', 'DESC')
            ->getQuery();
            
        return $this->paginator->paginate($query, $page, $limit);
    }

    public function findByDate(\DateTimeImmutable $date): array
    {
        $startDate = $date->setTime(0, 0, 0);
        $endDate = $date->setTime(23, 59, 59);

        return $this->createQueryBuilder('sl')
            ->andWhere('sl.time >= :startDate')
            ->andWhere('sl.time <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('sl.time', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    public function findByDatePaginated(\DateTimeImmutable $date, int $page = 1, int $limit = 10): object
    {
        $startDate = $date->setTime(0, 0, 0);
        $endDate = $date->setTime(23, 59, 59);

        $query = $this->createQueryBuilder('sl')
            ->andWhere('sl.time >= :startDate')
            ->andWhere('sl.time <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('sl.time', 'DESC')
            ->getQuery();
            
        return $this->paginator->paginate($query, $page, $limit);
    }
} 