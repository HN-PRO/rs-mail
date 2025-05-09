<?php

namespace App\Controller;

use App\Repository\AliasRepository;
use App\Repository\DomainRepository;
use App\Repository\MailRepository;
use App\Repository\SystemLogRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        DomainRepository $domainRepository,
        UserRepository $userRepository,
        MailRepository $mailRepository,
        AliasRepository $aliasRepository,
        SystemLogRepository $systemLogRepository
    ): Response {
        // 获取统计数据
        $domains = $domainRepository->findAll();
        $activeDomainCount = count($domains);
        
        // 获取邮箱用户数量
        $mailUsers = $mailRepository->findAll();
        $mailUserCount = count($mailUsers);
        
        $aliases = $aliasRepository->findAll();
        $aliasCount = count($aliases);
        
        // 获取最近活动
        $recentLogs = $systemLogRepository->findAllSorted();
        $recentLogs = array_slice($recentLogs, 0, 5);
        
        return $this->render('dashboard/index.html.twig', [
            'active_domain_count' => $activeDomainCount,
            'user_count' => $mailUserCount,
            'alias_count' => $aliasCount,
            'recent_logs' => $recentLogs,
            'domains' => $domains,
        ]);
    }
} 