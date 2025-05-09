<?php

namespace App\Repository;

use App\Entity\Mail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends ServiceEntityRepository<Mail>
 */
class MailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mail::class);
    }

    /**
     * 查找所有邮件并按ID降序排序
     */
    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * 创建查询构建器，查找所有邮件并按ID降序排序
     */
    public function findAllQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.id', 'DESC');
    }

    /**
     * 按状态查找邮件
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.status = :status')
            ->setParameter('status', $status)
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 按电子邮件地址查找邮件
     */
    public function findByEmail(string $email): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.email LIKE :email')
            ->setParameter('email', '%' . $email . '%')
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * 创建按电子邮件地址查找邮件的查询构建器
     */
    public function findByEmailQueryBuilder(string $email): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.email LIKE :email')
            ->setParameter('email', '%' . $email . '%')
            ->orderBy('m.id', 'DESC');
    }

    /**
     * 按域名ID查找邮件
     */
    public function findByDomain(int $domainId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.domain = :domainId')
            ->setParameter('domainId', $domainId)
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * 创建按域名ID查找邮件的查询构建器
     */
    public function findByDomainQueryBuilder(int $domainId): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.domain = :domainId')
            ->setParameter('domainId', $domainId)
            ->orderBy('m.id', 'DESC');
    }
    
    /**
     * 创建用于批量删除的查询构建器
     */
    public function findForDeleteQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('m');
    }
} 