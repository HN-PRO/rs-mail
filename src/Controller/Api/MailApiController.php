<?php

namespace App\Controller\Api;

use App\Entity\Mail;
use App\Repository\ApiTokenRepository;
use App\Repository\MailRepository;
use App\Service\ImapService;
use App\Service\PasswordHashService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\AtLeastOneOf;

#[Route('/api/mail')]
class MailApiController extends AbstractController
{
    private $imapService;
    private $apiTokenRepository;
    private $mailRepository;
    private $passwordHashService;

    public function __construct(
        ImapService $imapService,
        ApiTokenRepository $apiTokenRepository,
        MailRepository $mailRepository,
        PasswordHashService $passwordHashService
    ) {
        $this->imapService = $imapService;
        $this->apiTokenRepository = $apiTokenRepository;
        $this->mailRepository = $mailRepository;
        $this->passwordHashService = $passwordHashService;
    }

    /**
     * 获取最新的一封邮件
     */
    #[Route('/latest', name: 'api_mail_latest', methods: ['GET'])]
    public function getLatestEmail(Request $request): JsonResponse
    {
        // 增加脚本执行时间限制，防止处理大量邮件时超时
        // 但保持较短以快速响应
        set_time_limit(30);
        
        // 验证API令牌
        $token = $request->query->get('token');
        if (!$token) {
            return $this->createErrorResponse('缺少API令牌', Response::HTTP_UNAUTHORIZED);
        }

        // 获取原始密码（如果提供）
        $plainPassword = $request->query->get('password');
        
        // 打印请求查询参数用于调试
        error_log('请求参数: ' . json_encode($request->query->all()));

        // 根据令牌查找对应的邮箱用户
        $apiToken = $this->apiTokenRepository->findByToken($token);
        if (!$apiToken || $apiToken->isExpired()) {
            return $this->createErrorResponse('无效或已过期的API令牌', Response::HTTP_UNAUTHORIZED);
        }

        $mailUser = $apiToken->getMail();
        if (!$mailUser || $mailUser->getStatus() !== '活跃') {
            return $this->createErrorResponse('邮箱用户不存在或已禁用', Response::HTTP_FORBIDDEN);
        }

        try {
            // 添加缓存键，防止频繁请求
            $cacheKey = 'mail_latest_' . $mailUser->getId() . '_' . md5($plainPassword ?? '');
            $cached = $this->getCachedData($cacheKey);
            
            // 获取请求中的no_cache参数值
            $noCache = $request->query->get('no_cache', 'false');
            
            // 如果找到缓存且不强制刷新，直接返回缓存结果
            if ($cached && $noCache !== 'true') {
                return $this->createSuccessResponse($cached);
            }
            
            // 获取最新的一封邮件
            $email = [];
            
            if (!empty($plainPassword)) {
                // 如果提供了原始密码，使用它连接IMAP
                $email = $this->imapService->getLatestEmailWithPassword($mailUser, $plainPassword);
            } else {
                // 尝试使用存储的密码（可能是哈希值，通常不会成功）
                $email = $this->imapService->getLatestEmail($mailUser);
            }
            
            if (empty($email)) {
                return $this->createErrorResponse('没有找到邮件', Response::HTTP_NOT_FOUND);
            }
            
            // 缓存结果30秒
            $this->setCachedData($cacheKey, $email, 30);
            
            return $this->createSuccessResponse($email);
        } catch (\Exception $e) {
            return $this->createErrorResponse('获取邮件失败: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 获取指定数量的邮件
     */
    #[Route('/list', name: 'api_mail_list', methods: ['GET'])]
    public function getEmails(Request $request): JsonResponse
    {
        // 增加脚本执行时间限制，防止处理大量邮件时超时
        // 但保持合理以快速响应
        set_time_limit(60);
        
        // 验证API令牌
        $token = $request->query->get('token');
        if (!$token) {
            return $this->createErrorResponse('缺少API令牌', Response::HTTP_UNAUTHORIZED);
        }

        // 获取请求参数
        $limit = (int) $request->query->get('limit', 10);
        // 限制最大数量为20封，以提高性能
        if ($limit <= 0 || $limit > 20) {
            $limit = 10;
        }
        
        // 打印请求查询参数用于调试
        error_log('请求参数: ' . json_encode($request->query->all()) . ', 处理后的limit: ' . $limit);
        
        // 获取原始密码（如果提供）
        $plainPassword = $request->query->get('password');

        // 根据令牌查找对应的邮箱用户
        $apiToken = $this->apiTokenRepository->findByToken($token);
        if (!$apiToken || $apiToken->isExpired()) {
            return $this->createErrorResponse('无效或已过期的API令牌', Response::HTTP_UNAUTHORIZED);
        }

        $mailUser = $apiToken->getMail();
        if (!$mailUser || $mailUser->getStatus() !== '活跃') {
            return $this->createErrorResponse('邮箱用户不存在或已禁用', Response::HTTP_FORBIDDEN);
        }

        try {
            // 添加缓存键，防止频繁请求
            $cacheKey = 'mail_list_' . $mailUser->getId() . '_' . $limit . '_' . md5($plainPassword ?? '');
            $cached = $this->getCachedData($cacheKey);
            
            // 获取请求中的no_cache参数值
            $noCache = $request->query->get('no_cache', 'false');
            
            // 如果找到缓存且不强制刷新，直接返回缓存结果
            if ($cached && $noCache !== 'true') {
                return $this->createSuccessResponse($cached);
            }
            
            // 获取邮件列表
            $emails = [];
            
            if (!empty($plainPassword)) {
                // 如果提供了原始密码，使用它连接IMAP
                $emails = $this->imapService->getEmailsWithPassword($mailUser, $plainPassword, $limit);
            } else {
                // 尝试使用存储的密码（可能是哈希值，通常不会成功）
                $emails = $this->imapService->getEmails($mailUser, $limit);
            }
            
            if (empty($emails)) {
                return $this->createErrorResponse('没有找到邮件', Response::HTTP_NOT_FOUND);
            }
            
            // 缓存结果30秒
            $this->setCachedData($cacheKey, $emails, 30);
            
            return $this->createSuccessResponse($emails);
        } catch (\Exception $e) {
            return $this->createErrorResponse('获取邮件失败: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 获取缓存数据
     */
    private function getCachedData(string $key)
    {
        try {
            $cacheFile = sys_get_temp_dir() . '/mail_api_' . $key . '.json';
            if (file_exists($cacheFile)) {
                $expiresAt = filemtime($cacheFile) + 30; // 30秒缓存
                if (time() < $expiresAt) {
                    $content = file_get_contents($cacheFile);
                    if ($content) {
                        return json_decode($content, true);
                    }
                } else {
                    // 缓存过期，删除文件
                    @unlink($cacheFile);
                }
            }
        } catch (\Exception $e) {
            // 忽略缓存错误
        }
        return null;
    }
    
    /**
     * 设置缓存数据
     */
    private function setCachedData(string $key, $data, int $ttl = 30): void
    {
        try {
            $cacheFile = sys_get_temp_dir() . '/mail_api_' . $key . '.json';
            file_put_contents($cacheFile, json_encode($data), LOCK_EX);
        } catch (\Exception $e) {
            // 忽略缓存错误
        }
    }

    /**
     * 创建成功响应
     */
    private function createSuccessResponse($data): JsonResponse
    {
        return new JsonResponse([
            'code' => 200,
            'success' => true,
            'msg' => '操作成功',
            'data' => $data,
            'timestamp' => time() * 1000
        ]);
    }

    /**
     * 创建错误响应
     */
    private function createErrorResponse(string $message, int $statusCode = 400): JsonResponse
    {
        return new JsonResponse([
            'code' => $statusCode,
            'success' => false,
            'msg' => $message,
            'data' => null,
            'timestamp' => time() * 1000
        ], $statusCode);
    }
} 