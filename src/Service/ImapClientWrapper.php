<?php

namespace App\Service;

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/**
 * IMAP客户端包装器
 * 用于封装webklex/php-imap库并处理弃用警告
 */
class ImapClientWrapper
{
    private ClientManager $clientManager;
    
    public function __construct()
    {
        // 创建客户端管理器
        $this->clientManager = new ClientManager();
    }
    
    /**
     * 创建IMAP客户端
     */
    public function makeClient(array $config): Client
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 创建客户端
            $client = $this->clientManager->make($config);
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
            
            return $client;
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            throw $e;
        }
    }
    
    /**
     * 获取最新的消息
     * 尝试通过获取最新的UID找到最近的邮件，通常比获取所有消息并排序更快
     */
    public function getLatestMessages($folder, int $limit = 5)
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 获取文件夹的消息数量
            $status = $folder->getStatus();
            $messageCount = $status["messages"] ?? 0;
            
            if ($messageCount === 0) {
                // 如果没有消息，返回空集合
                return new \Webklex\PHPIMAP\Support\MessageCollection();
            }
            
            // 构建搜索查询，获取最新的几封邮件
            $folderQuery = $folder->messages();
            
            // 如果消息较多，通过UID搜索
            if ($messageCount > $limit) {
                // 尝试使用UID搜索最新的邮件
                // UID是递增的，所以较大的UID通常是较新的邮件
                $folderQuery->limit($limit)
                          ->setFetchBody(true)  // 预取正文内容
                          ->setFetchFlags(true) // 预取标志
                          ->setFetchOrder('uid', 'desc'); // 按UID降序
            } else {
                // 如果消息数量较少，只获取所有消息
                $folderQuery->all()
                          ->setFetchBody(true)
                          ->setFetchFlags(true);
            }
            
            $messages = $folderQuery->get();
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
            
            return $messages;
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            throw $e;
        }
    }
    
    /**
     * 连接到IMAP服务器
     */
    public function connect(Client $client): void
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 连接到服务器
            $client->connect();
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            throw $e;
        }
    }
    
    /**
     * 获取文件夹
     */
    public function getFolders(Client $client): array
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 获取文件夹
            $folders = $client->getFolders();
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
            
            return $folders;
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            throw $e;
        }
    }
    
    /**
     * 获取指定文件夹
     */
    public function getFolder(Client $client, string $folderName)
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 获取文件夹
            $folder = $client->getFolder($folderName);
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
            
            return $folder;
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            throw $e;
        }
    }
    
    /**
     * 获取消息
     */
    public function getMessages($folder, int $limit = 10, string $criteria = 'ALL', array $sortCriteria = [])
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 获取消息查询构建器
            $query = $folder->messages()->setFetchBody(true)->setFetchFlags(true);
            
            // 设置查询标准（如果提供）
            if (!empty($criteria)) {
                $query->where($criteria);
            }
            
            // 设置排序（如果提供）
            if (!empty($sortCriteria)) {
                foreach ($sortCriteria as $field => $direction) {
                    if ($direction === 'desc') {
                        $query->setFetchOrderBy($field, 'desc');
                    } else {
                        $query->setFetchOrderBy($field, 'asc');
                    }
                    break; // 目前只支持一个排序字段
                }
            }
            
            // 获取消息
            $messages = $query->limit($limit)->get();
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
            
            return $messages;
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            throw $e;
        }
    }
    
    /**
     * 通过消息编号直接获取消息
     * 这通常比获取整个文件夹的所有消息然后进行筛选要快
     */
    public function getMessageByNumber($folder, int $messageNumber)
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 直接按邮件序号获取单条消息
            $query = $folder->query()->setFetchBody(true);
            $message = $query->getMessage($messageNumber);
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
            
            return $message;
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            return null;
        }
    }
    
    /**
     * 获取最近的消息，使用RECENT标志
     * 这比获取所有消息然后排序更高效
     */
    public function getRecentMessages($folder, int $limit = 3)
    {
        // 禁用弃用警告
        $oldErrorLevel = error_reporting();
        error_reporting($oldErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        
        try {
            // 使用最高效的方式获取最近的邮件 - 尝试使用RECENT标志
            $status = $folder->getStatus();
            $messageCount = $status["messages"] ?? 0;
            
            if ($messageCount === 0) {
                return new \Webklex\PHPIMAP\Support\MessageCollection();
            }
            
            // 如果消息数量少于或等于请求的限制，只获取最后几条
            if ($messageCount <= $limit) {
                $startMsgNum = 1;
            } else {
                // 只获取最后limit条消息
                $startMsgNum = $messageCount - $limit + 1;
            }
            
            // 按顺序号范围获取
            $query = $folder->query()->setFetchBody(true)
                          ->setFetchOrder('date', 'desc');
            
            // 获取指定范围的消息
            if ($messageCount <= $limit) {
                $messages = $query->all()->get();
            } else {
                $messages = $query->whereMessageNumBetween($startMsgNum, $messageCount)->get();
            }
            
            // 恢复错误报告级别
            error_reporting($oldErrorLevel);
            
            return $messages;
        } catch (\Exception $e) {
            // 恢复错误报告级别，然后重新抛出异常
            error_reporting($oldErrorLevel);
            
            // 如果以上方法失败，回退到标准的获取限制数量的消息
            try {
                $query = $folder->messages()->setFetchBody(true)
                              ->setFetchFlags(true)
                              ->limit($limit);
                return $query->get();
            } catch (\Exception $e2) {
                throw $e2;
            }
        }
    }
} 