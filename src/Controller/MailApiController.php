<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class MailApiController extends AbstractController
{
    /**
     * 获取最新的一封邮件
     */
    #[Route('/latest-email', name: 'api_latest_email', methods: ['POST'])]
    public function getLatestEmail(Request $request): JsonResponse
    {
        // 增加脚本执行时间限制，以防止处理大量邮件时超时
        set_time_limit(180);
        
        // 从请求中获取API令牌和密码
        $data = json_decode($request->getContent(), true);
        $apiToken = $data['apiToken'] ?? null;
        $password = $data['password'] ?? null;
        
        // 验证参数
        if (!$apiToken || !$password) {
            return $this->json([
                'code' => 400,
                'success' => false,
                'msg' => '缺少必要参数'
            ]);
        }
        
        try {
            // 获取对应的邮箱用户
            $mailUser = $this->apiTokenRepository->findMailUserByToken($apiToken);
            
            if (!$mailUser) {
                return $this->json([
                    'code' => 401,
                    'success' => false,
                    'msg' => 'API令牌无效'
                ]);
            }
            
            // 获取最新邮件
            $email = $this->imapService->getLatestEmailWithPassword($mailUser, $password);
            
            if (empty($email)) {
                return $this->json([
                    'code' => 200,
                    'success' => true,
                    'msg' => '邮箱中没有邮件',
                    'data' => null
                ]);
            }
            
            return $this->json([
                'code' => 200,
                'success' => true,
                'msg' => '操作成功',
                'data' => $email,
                'timestamp' => (new \DateTime())->getTimestamp() * 1000
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'code' => 500,
                'success' => false,
                'msg' => '获取邮件失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取邮件列表
     */
    #[Route('/emails', name: 'api_emails', methods: ['POST'])]
    public function getEmails(Request $request): JsonResponse
    {
        // 增加脚本执行时间限制，以防止处理大量邮件时超时
        set_time_limit(180);
        
        // 从请求中获取API令牌、密码和限制数量
        $data = json_decode($request->getContent(), true);
        $apiToken = $data['apiToken'] ?? null;
        $password = $data['password'] ?? null;
        $limit = isset($data['limit']) ? (int)$data['limit'] : 10;
        
        // 限制最大获取数量，以防止请求过多导致超时
        $limit = min($limit, 20);
        
        // 验证参数
        if (!$apiToken || !$password) {
            return $this->json([
                'code' => 400,
                'success' => false,
                'msg' => '缺少必要参数'
            ]);
        }
        
        try {
            // 获取对应的邮箱用户
            $mailUser = $this->apiTokenRepository->findMailUserByToken($apiToken);
            
            if (!$mailUser) {
                return $this->json([
                    'code' => 401,
                    'success' => false,
                    'msg' => 'API令牌无效'
                ]);
            }
            
            // 获取邮件列表
            $emails = $this->imapService->getEmailsWithPassword($mailUser, $password, $limit);
            
            return $this->json([
                'code' => 200,
                'success' => true,
                'msg' => '操作成功',
                'data' => $emails,
                'timestamp' => (new \DateTime())->getTimestamp() * 1000
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'code' => 500,
                'success' => false,
                'msg' => '获取邮件失败: ' . $e->getMessage()
            ]);
        }
    }
} 