<?php

namespace App\Controller;

use App\Repository\SystemLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin/system-log')]
class SystemLogController extends AbstractController
{
    #[Route('/', name: 'app_system_log_index')]
    public function index(Request $request, SystemLogRepository $systemLogRepository): Response
    {
        $searchTerm = $request->query->get('search', '');
        $operationType = $request->query->get('operation_type', '所有操作');
        $date = $request->query->get('date', '');
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        
        $logs = null;
        
        if (!empty($searchTerm)) {
            $logs = $systemLogRepository->findByOperatorPaginated($searchTerm, $page, $limit);
        } elseif ($operationType !== '所有操作') {
            $logs = $systemLogRepository->findByOperationTypePaginated($operationType, $page, $limit);
        } elseif (!empty($date)) {
            $dateObj = new \DateTimeImmutable($date);
            $logs = $systemLogRepository->findByDatePaginated($dateObj, $page, $limit);
        } else {
            $logs = $systemLogRepository->findAllSortedPaginated($page, $limit);
        }
        
        // 获取可用的操作类型列表
        $operationTypes = ['添加用户', '编辑用户', '删除用户', '重置密码', '添加域名', '编辑域名', '删除域名', '添加别名', '编辑别名', '删除别名'];
        
        return $this->render('system_log/index.html.twig', [
            'logs' => $logs,
            'search_term' => $searchTerm,
            'operation_type' => $operationType,
            'date' => $date,
            'operation_types' => $operationTypes,
        ]);
    }
    
    #[Route('/export', name: 'app_system_log_export')]
    public function export(Request $request, SystemLogRepository $systemLogRepository): Response
    {
        $searchTerm = $request->query->get('search', '');
        $operationType = $request->query->get('operation_type', '所有操作');
        $date = $request->query->get('date', '');
        
        $logs = [];
        
        if (!empty($searchTerm)) {
            $logs = $systemLogRepository->findByOperator($searchTerm);
        } elseif ($operationType !== '所有操作') {
            $logs = $systemLogRepository->findByOperationType($operationType);
        } elseif (!empty($date)) {
            $dateObj = new \DateTimeImmutable($date);
            $logs = $systemLogRepository->findByDate($dateObj);
        } else {
            $logs = $systemLogRepository->findAllSorted();
        }
        
        // 创建CSV内容
        $csvContent = "时间,操作人,操作类型,IP地址,详情\n";
        
        foreach ($logs as $log) {
            $csvContent .= sprintf(
                "%s,%s,%s,%s,%s\n",
                $log->getTime()->format('Y-m-d H:i:s'),
                $log->getOperatorName(),
                $log->getOperationType(),
                $log->getIpAddress(),
                $log->getDetails()
            );
        }
        
        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="system_logs_' . date('Y-m-d') . '.csv"');
        
        return $response;
    }
} 