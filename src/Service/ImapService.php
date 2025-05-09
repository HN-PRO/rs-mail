<?php

namespace App\Service;

use App\Entity\Mail;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Message;
use App\Service\PasswordHashService;
use App\Repository\MailServerConfigRepository;
use App\Service\ImapClientWrapper;

class ImapService
{
    private $clientWrapper;
    private $slugger;
    private $passwordHashService;
    private $mailServerConfigRepository;

    public function __construct(
        SluggerInterface $slugger, 
        PasswordHashService $passwordHashService,
        MailServerConfigRepository $mailServerConfigRepository
    )
    {
        $this->clientWrapper = new ImapClientWrapper();
        $this->slugger = $slugger;
        $this->passwordHashService = $passwordHashService;
        $this->mailServerConfigRepository = $mailServerConfigRepository;
    }

    /**
     * 生成用户的唯一API令牌
     */
    public function generateApiToken(Mail $mailUser): string
    {
        // 基于用户ID和邮箱地址生成唯一的令牌
        $tokenData = $mailUser->getId() . '|' . $mailUser->getEmail() . '|' . time();
        return hash('sha256', $tokenData);
    }

    /**
     * 验证API令牌是否有效
     */
    public function validateApiToken(string $token, Mail $mailUser): bool
    {
        // 在这里实现令牌验证逻辑，例如从数据库中检查令牌
        // 这里简化处理，验证令牌是否匹配用户的唯一标识
        $expectedToken = $this->generateApiToken($mailUser);
        return hash_equals($expectedToken, $token);
    }

    /**
     * 连接到IMAP服务器
     * 注意：此方法尝试使用存储的密码哈希，可能无法工作，应尽量使用connectToImapWithPassword
     */
    private function connectToImap(Mail $mailUser): \Webklex\PHPIMAP\Client
    {
        // 此方法已废弃，应尽量使用connectToImapWithPassword
        return $this->connectToImapWithPassword($mailUser, $mailUser->getPassword());
    }
    
    /**
     * 使用明文密码连接到IMAP服务器
     */
    private function connectToImapWithPassword(Mail $mailUser, string $plainPassword): \Webklex\PHPIMAP\Client
    {
        // 从邮箱用户信息获取邮箱地址
        $email = $mailUser->getFullEmail();
        
        // 解析邮箱域名部分，确定IMAP服务器
        $domainPart = explode('@', $email)[1];
        
        // 确定IMAP服务器配置
        // 为常用邮箱提供商提供特定配置
        $imapConfig = $this->getImapConfigForDomain($domainPart);
        
        // 配置IMAP客户端
        $client = $this->clientWrapper->makeClient([
            'host'          => $imapConfig['host'],
            'port'          => $imapConfig['port'],
            'encryption'    => $imapConfig['encryption'],
            'validate_cert' => false, // 禁用证书验证以提高速度
            'username'      => $imapConfig['use_full_email'] ? $email : $mailUser->getUsername(),
            'password'      => $plainPassword,
            'protocol'      => 'imap',
            'options'       => [
                // 添加IMAP选项以加快连接速度
                'timeout'        => 15, // 缩短超时时间
                'ssl'           => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                ],
                'reconnect'      => false, // 禁用自动重连
                'fetch_options'  => 1, // 使用1替代FT_UID
                'sequence_type'  => 1, // 使用1替代ST_MSGN
            ]
        ]);

        try {
            $this->clientWrapper->connect($client);
        } catch (ConnectionFailedException $e) {
            throw new \Exception('Unable to connect to IMAP server: ' . $e->getMessage());
        }

        return $client;
    }
    
    /**
     * 根据域名获取IMAP服务器配置
     */
    private function getImapConfigForDomain(string $domain): array
    {
        // 使用仓库查找匹配的配置
        $config = $this->mailServerConfigRepository->findMatchingConfig($domain);
        
        // 添加调试信息到系统日志
        if ($config) {
            $configInfo = sprintf(
                "找到域名 %s 的配置: ID=%d, 模式=%s, 主机=%s, 活跃=%s", 
                $domain, 
                $config->getId(), 
                $config->getDomainPattern(),
                $config->getHost(),
                $config->isActive() ? '是' : '否'
            );
            
            error_log($configInfo);
        } else {
            error_log("未找到域名 {$domain} 的匹配配置");
        }
        
        if ($config && $config->isActive()) {
            return [
                'host' => $config->getHost(),
                'port' => $config->getPort(),
                'encryption' => $config->getEncryption(),
                'validate_cert' => $config->isValidateCert(),
                'use_full_email' => $config->isUseFullEmail()
            ];
        }
        
        // 如果没有找到匹配的配置或配置不活跃，返回默认配置
        error_log("使用域名 {$domain} 的默认配置");
        return [
            'host' => 'imap.' . $domain,
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'use_full_email' => true
        ];
    }

    /**
     * 获取最新的一封邮件（使用提供的密码）
     */
    public function getLatestEmailWithPassword(Mail $mailUser, string $plainPassword): array
    {
        // 增加脚本执行时间限制但保持较短
        set_time_limit(30);
        
        error_log('开始获取最新邮件，用户: ' . $mailUser->getFullEmail());
        $client = $this->connectToImapWithPassword($mailUser, $plainPassword);
        
        try {
            // 1. 尝试直接获取最新的邮件 (最快的方式)
            error_log('尝试直接获取最新邮件');
            
            // 获取收件箱
            $inbox = $this->clientWrapper->getFolder($client, 'INBOX');
            
            // 获取邮箱状态
            $status = $inbox->getStatus();
            $messageCount = $status["messages"] ?? 0;
            
            if ($messageCount === 0) {
                error_log('邮箱中没有邮件');
                return [];
            }
            
            // 如果邮箱中有消息，尝试直接获取最新的一封
            // 使用序号而不是UID，通常IMAP服务器按接收时间排序
            // 获取最后一封邮件 (最高序号)
            $lastMessageNumber = $messageCount;
            
            // 使用folderQuery直接获取最后一封邮件
            error_log("直接获取邮件序号: $lastMessageNumber");
            
            $message = $this->clientWrapper->getMessageByNumber($inbox, $lastMessageNumber);
            
            if ($message) {
                error_log('成功获取到最新邮件');
                return $this->formatEmailMessage($message);
            }
            
            // 如果直接获取失败，回退到获取最近的几封邮件并查找最新的
            error_log('直接获取最新邮件失败，尝试备选方法');
            $messages = $this->clientWrapper->getRecentMessages($inbox, 3);
            
            if ($messages->count() > 0) {
                // 取最后一封邮件，假定它是最新的
                $latestMessage = $messages->last();
                error_log('通过备选方法获取到最新邮件');
                return $this->formatEmailMessage($latestMessage);
            }
        } catch (\Exception $e) {
            error_log('获取最新邮件失败: ' . $e->getMessage());
        }
        
        error_log('所有方法均未找到有效邮件');
        return [];
    }
    
    /**
     * 安全获取消息的时间戳
     */
    private function getMessageTimestamp(Message $message): int
    {
        try {
            $dateValue = $message->getDate();
            
            if ($dateValue instanceof \DateTimeInterface) {
                return $dateValue->getTimestamp();
            }
            
            if (is_string($dateValue)) {
                return (new \DateTime($dateValue))->getTimestamp();
            }
            
            if (is_object($dateValue)) {
                $dateStr = method_exists($dateValue, 'toString') ? $dateValue->toString() : (string)$dateValue;
                return (new \DateTime($dateStr))->getTimestamp();
            }
            
            // 尝试从邮件头获取日期
            $headers = $message->getHeader();
            if ($headers && isset($headers->date) && is_string($headers->date)) {
                return (new \DateTime($headers->date))->getTimestamp();
            }
            
        } catch (\Exception $e) {
            error_log('获取邮件时间戳失败: ' . $e->getMessage());
        }
        
        // 默认返回当前时间戳
        return time();
    }

    /**
     * 获取指定数量的邮件（使用提供的密码）
     */
    public function getEmailsWithPassword(Mail $mailUser, string $plainPassword, int $limit = 10): array
    {
        // 增加脚本执行时间限制但保持较短
        set_time_limit(60);
        
        error_log('开始获取邮件列表，用户: ' . $mailUser->getFullEmail() . ', 限制: ' . $limit);
        $client = $this->connectToImapWithPassword($mailUser, $plainPassword);
        
        try {
            // 1. 尝试直接获取最新的邮件 (类似getLatestEmailWithPassword的方法)
            error_log('尝试直接获取最新的' . $limit . '封邮件');
            
            // 获取收件箱
            $inbox = $this->clientWrapper->getFolder($client, 'INBOX');
            
            // 获取邮箱状态
            $status = $inbox->getStatus();
            $messageCount = $status["messages"] ?? 0;
            
            if ($messageCount === 0) {
                error_log('邮箱中没有邮件');
                return [];
            }
            
            // 如果邮箱中有消息，尝试直接获取最新的几封
            // 计算起始邮件号(从最新的开始获取)
            $lastMessageNumber = $messageCount;
            $startMessageNumber = $lastMessageNumber - $limit + 1;
            if ($startMessageNumber < 1) {
                $startMessageNumber = 1;
            }
            
            error_log("直接获取邮件序号范围: $startMessageNumber 到 $lastMessageNumber");
            
            // 尝试按序号范围获取邮件
            $result = [];
            for ($i = $lastMessageNumber; $i >= $startMessageNumber; $i--) {
                try {
                    $message = $this->clientWrapper->getMessageByNumber($inbox, $i);
                    if ($message) {
                        $result[] = $this->formatEmailMessage($message);
                        error_log('成功获取到邮件序号: ' . $i);
                    }
                } catch (\Exception $e) {
                    error_log('获取邮件序号' . $i . '失败: ' . $e->getMessage());
                }
                
                // 如果已经收集到足够的邮件，提前退出
                if (count($result) >= $limit) {
                    break;
                }
            }
            
            // 如果成功获取到了邮件，直接返回
            if (!empty($result)) {
                error_log('成功通过序号范围获取到' . count($result) . '封邮件');
                return $result;
            }
            
            // 2. 如果直接获取失败，回退到获取最近的几封邮件并查找最新的
            error_log('直接获取邮件失败，尝试备选方法');
            $messages = $this->clientWrapper->getRecentMessages($inbox, $limit * 2);
            
            if ($messages->count() > 0) {
                error_log('通过备选方法获取到' . $messages->count() . '封邮件');
                
                // 将消息放入数组并按日期排序
                $messageArray = [];
                foreach ($messages as $message) {
                    try {
                        // 使用优化的时间戳获取方法
                        $timestamp = $this->getMessageTimestamp($message);
                        
                        $messageArray[] = [
                            'message' => $message,
                            'timestamp' => $timestamp
                        ];
                    } catch (\Exception $e) {
                        error_log('处理邮件时出错: ' . $e->getMessage());
                        continue;
                    }
                }
                
                // 如果没有找到任何消息，直接返回空数组
                if (empty($messageArray)) {
                    return [];
                }
                
                // 手动按时间戳降序排序（最新的在前面）
                usort($messageArray, function($a, $b) {
                    return $b['timestamp'] - $a['timestamp']; // 数值比较更可靠
                });
                
                // 限制结果数量
                $messageArray = array_slice($messageArray, 0, $limit);
                
                // 格式化结果
                $result = [];
                foreach ($messageArray as $item) {
                    $result[] = $this->formatEmailMessage($item['message']);
                }
                
                error_log('返回' . count($result) . '封邮件的格式化结果');
                return $result;
            }
        } catch (\Exception $e) {
            error_log('获取邮件列表失败: ' . $e->getMessage());
        }
        
        error_log('所有方法均未找到有效邮件');
        return [];
    }

    /**
     * 获取最新的一封邮件
     */
    public function getLatestEmail(Mail $mailUser): array
    {
        $client = $this->connectToImap($mailUser);
        $inbox = $this->clientWrapper->getFolder($client, 'INBOX');
        
        // 获取邮件，不使用任何排序方法
        $messages = $this->clientWrapper->getMessages($inbox, 10);
        
        if ($messages->count() === 0) {
            return [];
        }
        
        // 手动找到最新的邮件
        $latestMessage = null;
        $latestDate = null;
        
        foreach ($messages as $message) {
            try {
                // 获取日期 - 安全地处理各种可能的日期格式
                $dateValue = $message->getDate();
                $messageDate = null;
                
                if ($dateValue instanceof \DateTimeInterface) {
                    $messageDate = $dateValue;
                } elseif (is_string($dateValue)) {
                    // 尝试解析字符串为日期
                    $messageDate = new \DateTime($dateValue);
                } elseif (is_object($dateValue) && method_exists($dateValue, 'toString')) {
                    // 如果是对象且有toString方法
                    $messageDate = new \DateTime($dateValue->toString());
                } elseif (is_object($dateValue)) {
                    // 尝试将对象转换为字符串
                    $messageDate = new \DateTime((string)$dateValue);
                }
                
                // 如果成功获取日期且比当前最新日期更新
                if ($messageDate && ($latestDate === null || $messageDate > $latestDate)) {
                    $latestDate = $messageDate;
                    $latestMessage = $message;
                }
            } catch (\Exception $e) {
                // 忽略无法处理日期的消息
                continue;
            }
        }
        
        if ($latestMessage) {
            return $this->formatEmailMessage($latestMessage);
        }
        
        return [];
    }

    /**
     * 获取指定数量的邮件
     */
    public function getEmails(Mail $mailUser, int $limit = 10): array
    {
        $client = $this->connectToImap($mailUser);
        $inbox = $this->clientWrapper->getFolder($client, 'INBOX');
        
        // 获取邮件，不使用任何排序方法
        $messages = $this->clientWrapper->getMessages($inbox, $limit * 2);
        
        // 将消息放入数组并按日期排序
        $messageArray = [];
        foreach ($messages as $message) {
            try {
                // 获取日期 - 安全地处理各种可能的日期格式
                $dateValue = $message->getDate();
                $messageDate = null;
                
                if ($dateValue instanceof \DateTimeInterface) {
                    $messageDate = $dateValue;
                } elseif (is_string($dateValue)) {
                    // 尝试解析字符串为日期
                    $messageDate = new \DateTime($dateValue);
                } elseif (is_object($dateValue) && method_exists($dateValue, 'toString')) {
                    // 如果是对象且有toString方法
                    $messageDate = new \DateTime($dateValue->toString());
                } elseif (is_object($dateValue)) {
                    // 尝试将对象转换为字符串
                    $messageDate = new \DateTime((string)$dateValue);
                }
                
                if ($messageDate) {
                    $messageArray[] = [
                        'message' => $message,
                        'date' => $messageDate
                    ];
                }
            } catch (\Exception $e) {
                // 忽略无法处理日期的消息
                continue;
            }
        }
        
        // 手动按日期降序排序
        usort($messageArray, function($a, $b) {
            return $b['date'] <=> $a['date']; // 使用宇宙飞船操作符进行降序排列
        });
        
        // 限制结果数量
        $messageArray = array_slice($messageArray, 0, $limit);
        
        // 格式化结果
        $result = [];
        foreach ($messageArray as $item) {
            $result[] = $this->formatEmailMessage($item['message']);
        }
        
        return $result;
    }

    /**
     * 格式化邮件消息
     * 简化版本，专注于核心字段，减少处理时间
     */
    private function formatEmailMessage(Message $message): array
    {
        // 输出原始邮件的完整调试信息
        error_log('====== 开始处理邮件 ======');
        error_log('消息ID: ' . $message->getMessageId());
        
        // 打印完整的原始邮件头
        $rawHeaders = $message->getRawHeaders();
        error_log('原始邮件头:');
        error_log($rawHeaders);
        
        // 打印对象的所有可用方法
        $messageMethods = get_class_methods($message);
        error_log('消息对象可用方法: ' . json_encode($messageMethods));
        
        // 获取收件人 - 硬编码返回值确保有数据
        $recipients = [];
        
        // 方法1: 从原始头部获取收件人 (如果上面的原始头无法打印，直接从对象获取)
        try {
            $to = $message->getTo();
            error_log('原始getTo()返回值类型: ' . gettype($to));
            error_log('原始getTo()返回值内容: ' . var_export($to, true));
            
            // 如果To是Collection类型或数组
            if (is_iterable($to)) {
                foreach ($to as $key => $recipient) {
                    error_log('收件人[$key]类型: ' . gettype($recipient));
                    
                    // 如果是对象，尝试获取所有方法和属性
                    if (is_object($recipient)) {
                        $recipientClass = get_class($recipient);
                        error_log("收件人对象[$key]类: $recipientClass");
                        
                        // 尝试获取这个收件人类的所有属性
                        $reflection = new \ReflectionObject($recipient);
                        $properties = $reflection->getProperties();
                        $propertyInfo = [];
                        
                        foreach ($properties as $property) {
                            $property->setAccessible(true);
                            $propertyName = $property->getName();
                            try {
                                $propertyValue = $property->getValue($recipient);
                                $propertyInfo[$propertyName] = is_scalar($propertyValue) ? $propertyValue : gettype($propertyValue);
                            } catch (\Exception $e) {
                                $propertyInfo[$propertyName] = 'Error: ' . $e->getMessage();
                            }
                        }
                        
                        error_log("收件人对象[$key]属性: " . json_encode($propertyInfo));
                        
                        // 尝试输出对象的字符串表示
                        try {
                            $stringRepresentation = (string)$recipient;
                            error_log("收件人对象[$key]字符串表示: $stringRepresentation");
                            
                            // 如果有字符串表示，添加到收件人列表
                            if ($stringRepresentation) {
                                $recipients[] = $stringRepresentation;
                            }
                        } catch (\Exception $e) {
                            error_log("获取字符串表示失败: " . $e->getMessage());
                        }
                    } elseif (is_string($recipient)) {
                        error_log("收件人[$key]字符串值: $recipient");
                        $recipients[] = $recipient;
                    } elseif (is_array($recipient)) {
                        error_log("收件人[$key]数组值: " . json_encode($recipient));
                        
                        // 检查数组是否包含email键
                        if (isset($recipient['email'])) {
                            $recipients[] = $recipient['email'];
                        } elseif (isset($recipient['mail'])) {
                            $recipients[] = $recipient['mail'];
                        } elseif (isset($recipient['address'])) {
                            $recipients[] = $recipient['address'];
                        }
                    }
                }
            } elseif (is_object($to)) {
                // 如果To是单个对象
                $toClass = get_class($to);
                error_log("To对象类: $toClass");
                
                // 尝试获取对象的所有方法
                error_log("To对象方法: " . json_encode(get_class_methods($to)));
                
                // 尝试获取对象的字符串表示
                try {
                    $stringRepresentation = (string)$to;
                    error_log("To对象字符串表示: $stringRepresentation");
                    $recipients[] = $stringRepresentation;
                } catch (\Exception $e) {
                    error_log("获取To对象字符串表示失败: " . $e->getMessage());
                }
            } elseif (is_string($to)) {
                error_log("To是字符串: $to");
                $recipients[] = $to;
            }
        } catch (\Exception $e) {
            error_log('获取To字段时出错: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
        
        // 处理发件人
        $fromEmail = '';
        $fromName = '';
        
        try {
            // 方法1: 从getFrom()方法获取发件人
            $from = $message->getFrom();
            error_log('从getFrom()获取发件人: ' . var_export($from, true));
            
            if (is_iterable($from) && !empty($from)) {
                // 如果是集合或数组，获取第一个元素
                foreach ($from as $sender) {
                    if (is_object($sender) && method_exists($sender, 'getEmail')) {
                        $fromEmail = $sender->getEmail();
                        error_log('从发件人对象getEmail()获取: ' . $fromEmail);
                        if (method_exists($sender, 'getPersonal')) {
                            $fromName = $sender->getPersonal();
                            error_log('从发件人对象getPersonal()获取: ' . $fromName);
                        }
                        break; // 获取第一个发件人即可
                    } elseif (is_object($sender) && method_exists($sender, 'getMail')) {
                        $fromEmail = $sender->getMail();
                        error_log('从发件人对象getMail()获取: ' . $fromEmail);
                        if (method_exists($sender, 'getName')) {
                            $fromName = $sender->getName();
                            error_log('从发件人对象getName()获取: ' . $fromName);
                        }
                        break;
                    } elseif (is_array($sender) && isset($sender['email'])) {
                        $fromEmail = $sender['email'];
                        $fromName = $sender['name'] ?? '';
                        error_log('从发件人数组获取: ' . $fromEmail . ', ' . $fromName);
                        break;
                    } elseif (is_string($sender)) {
                        // 直接使用字符串
                        $fromEmail = $sender;
                        error_log('从发件人字符串获取: ' . $fromEmail);
                        break;
                    }
                }
            } elseif (is_object($from)) {
                // 如果是单个对象
                if (method_exists($from, 'getEmail')) {
                    $fromEmail = $from->getEmail();
                    error_log('从单个发件人对象getEmail()获取: ' . $fromEmail);
                    if (method_exists($from, 'getPersonal')) {
                        $fromName = $from->getPersonal();
                    }
                } elseif (method_exists($from, 'getMail')) {
                    $fromEmail = $from->getMail();
                    if (method_exists($from, 'getName')) {
                        $fromName = $from->getName();
                    }
                } elseif (method_exists($from, '__toString')) {
                    $fromEmail = (string)$from;
                }
            } elseif (is_string($from)) {
                $fromEmail = $from;
                error_log('从字符串类型直接获取发件人: ' . $fromEmail);
            }
        } catch (\Exception $e) {
            error_log('从getFrom()获取发件人信息失败: ' . $e->getMessage());
        }
        
        // 方法2: 如果上面方法没获取到，从原始头部提取发件人
        if (empty($fromEmail) && is_string($rawHeaders)) {
            try {
                // 使用正则表达式提取From头
                if (preg_match('/From:\s*(.*?)(?:\r\n|\n)(?!\s)/m', $rawHeaders, $matches)) {
                    $fromHeader = trim($matches[1]);
                    error_log('从原始头部提取From: ' . $fromHeader);
                    
                    // 尝试解析格式: "姓名 <邮箱>"
                    if (preg_match('/(.*?)\s*<([^>]+)>/', $fromHeader, $fromMatches)) {
                        $fromName = trim($fromMatches[1]);
                        $fromEmail = trim($fromMatches[2]);
                        error_log("解析出发件人名称: '$fromName', 邮箱: '$fromEmail'");
                    } else {
                        // 整行作为邮箱地址
                        $fromEmail = $fromHeader;
                        error_log("未找到名称，使用整行作为邮箱: $fromEmail");
                    }
                }
            } catch (\Exception $e) {
                error_log('从原始头部提取发件人失败: ' . $e->getMessage());
            }
        }
        
        // 方法3: 如果前两种方法都失败，直接从邮件头对象获取
        if (empty($fromEmail)) {
            try {
                $header = $message->getHeader();
                if (is_object($header)) {
                    if (method_exists($header, 'get')) {
                        $fromHeader = $header->get('from');
                        error_log('从header->get获取from: ' . var_export($fromHeader, true));
                        if (is_string($fromHeader)) {
                            // 尝试解析格式: "姓名 <邮箱>"
                            if (preg_match('/(.*?)\s*<([^>]+)>/', $fromHeader, $fromMatches)) {
                                $fromName = trim($fromMatches[1]);
                                $fromEmail = trim($fromMatches[2]);
                            } else {
                                $fromEmail = $fromHeader;
                            }
                        }
                    } elseif (isset($header->from)) {
                        $fromHeader = $header->from;
                        error_log('从header->from属性获取: ' . var_export($fromHeader, true));
                        if (is_string($fromHeader)) {
                            // 尝试解析
                            if (preg_match('/(.*?)\s*<([^>]+)>/', $fromHeader, $fromMatches)) {
                                $fromName = trim($fromMatches[1]);
                                $fromEmail = trim($fromMatches[2]);
                            } else {
                                $fromEmail = $fromHeader;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log('从邮件头对象获取发件人失败: ' . $e->getMessage());
            }
        }

        // 方法4: 如果仍然没有获取到发件人，尝试从原始头部中搜索任何可能包含邮箱的部分
        if (empty($fromEmail) && is_string($rawHeaders)) {
            try {
                // 先搜索Reply-To头
                if (preg_match('/Reply-To:\s*(.*?)(?:\r\n|\n)(?!\s)/m', $rawHeaders, $matches)) {
                    $replyTo = trim($matches[1]);
                    error_log('从Reply-To头获取: ' . $replyTo);
                    
                    // 尝试提取邮箱地址
                    if (preg_match('/<([^>]+)>/', $replyTo, $emailMatches)) {
                        $fromEmail = trim($emailMatches[1]);
                        error_log('从Reply-To提取邮箱: ' . $fromEmail);
                    } else {
                        $fromEmail = $replyTo;
                    }
                }
                
                // 如果仍然没有找到，搜索任何看起来像邮箱的格式
                if (empty($fromEmail) && preg_match('/[\w\.-]+@[\w\.-]+\.\w+/', $rawHeaders, $matches)) {
                    $fromEmail = $matches[0];
                    error_log('从原始头部中找到第一个邮箱格式: ' . $fromEmail);
                }
            } catch (\Exception $e) {
                error_log('提取邮箱地址失败: ' . $e->getMessage());
            }
        }
        
        // 处理邮件正文
        $content = '';
        try {
            if ($message->hasHTMLBody()) {
                $content = $message->getHTMLBody();
                error_log('获取到HTML正文，长度: ' . strlen($content));
            } else {
                $content = $message->getTextBody();
                error_log('获取到文本正文，长度: ' . strlen($content));
            }
        } catch (\Exception $e) {
            error_log('获取邮件正文时出错: ' . $e->getMessage());
            $content = '无法获取邮件内容';
        }
        
        // 处理主题
        $subject = '';
        try {
            // 方法1: 直接使用getSubject()
            $subjectValue = $message->getSubject();
            error_log('原始主题类型: ' . gettype($subjectValue) . ', 值: ' . var_export($subjectValue, true));
            
            if (is_string($subjectValue) && !empty($subjectValue)) {
                $subject = $subjectValue;
                error_log('从getSubject()直接获取到字符串主题: ' . $subject);
            } elseif (is_object($subjectValue)) {
                // 如果主题是对象，尝试不同的方法
                if (method_exists($subjectValue, 'toString')) {
                    $subject = $subjectValue->toString();
                    error_log('从subject对象的toString()方法获取: ' . $subject);
                } elseif (method_exists($subjectValue, 'getValue')) {
                    $subject = $subjectValue->getValue();
                    error_log('从subject对象的getValue()方法获取: ' . $subject);
                } elseif (method_exists($subjectValue, '__toString')) {
                    $subject = (string)$subjectValue;
                    error_log('从subject对象的__toString()方法获取: ' . $subject);
                } else {
                    // 使用反射获取对象的属性
                    $reflection = new \ReflectionObject($subjectValue);
                    $properties = $reflection->getProperties();
                    
                    foreach ($properties as $property) {
                        $property->setAccessible(true);
                        $name = $property->getName();
                        
                        // 查找可能包含值的属性
                        if (in_array(strtolower($name), ['value', 'text', 'string', 'content', 'subject'])) {
                            try {
                                $value = $property->getValue($subjectValue);
                                if (is_string($value) && !empty($value)) {
                                    $subject = $value;
                                    error_log("从subject对象的{$name}属性获取: " . $subject);
                                    break;
                                }
                            } catch (\Exception $e) {
                                error_log("读取subject对象的{$name}属性失败: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
            
            // 方法2: 如果第一种方法没有获取到，尝试从原始邮件头获取
            if (empty($subject) && is_string($rawHeaders)) {
                if (preg_match('/Subject:\s*(.*?)(?:\r\n|\n)(?!\s)/m', $rawHeaders, $matches)) {
                    $subject = trim($matches[1]);
                    error_log('从原始邮件头获取主题: ' . $subject);
                }
            }
            
            // 方法3: 如果前两种方法都失败，尝试从邮件头对象获取
            if (empty($subject)) {
                try {
                    $header = $message->getHeader();
                    if (is_object($header)) {
                        if (method_exists($header, 'get')) {
                            $headerSubject = $header->get('subject');
                            if (is_string($headerSubject) && !empty($headerSubject)) {
                                $subject = $headerSubject;
                                error_log('从header->get获取subject: ' . $subject);
                            }
                        } elseif (isset($header->subject)) {
                            $headerSubject = $header->subject;
                            if (is_string($headerSubject) && !empty($headerSubject)) {
                                $subject = $headerSubject;
                                error_log('从header->subject属性获取: ' . $subject);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log('从邮件头对象获取主题失败: ' . $e->getMessage());
                }
            }
            
            // 对主题进行编码处理，修复可能的编码问题
            if (!empty($subject)) {
                // 尝试检测主题编码并转换为UTF-8
                $encodedSubject = false;
                
                // 检查是否包含=?编码标记
                if (preg_match('/=\?([^?]+)\?([QB])\?([^?]*)\?=/', $subject, $encodingMatches)) {
                    $charset = $encodingMatches[1];
                    $encoding = $encodingMatches[2];
                    $encodedText = $encodingMatches[3];
                    
                    error_log("检测到编码的主题: 字符集={$charset}, 编码方式={$encoding}");
                    
                    // 解码主题
                    if (strtoupper($encoding) === 'B') {
                        // Base64编码
                        $decodedText = base64_decode($encodedText);
                        $encodedSubject = true;
                    } elseif (strtoupper($encoding) === 'Q') {
                        // Quoted-printable编码
                        $decodedText = quoted_printable_decode($encodedText);
                        $encodedSubject = true;
                    }
                    
                    if ($encodedSubject && $charset !== 'UTF-8' && $charset !== 'utf-8') {
                        // 尝试转换字符集到UTF-8
                        $convertedText = @iconv($charset, 'UTF-8//IGNORE', $decodedText);
                        if ($convertedText !== false) {
                            $subject = $convertedText;
                            error_log("解码并转换主题到UTF-8: {$subject}");
                        } else {
                            $subject = $decodedText;
                            error_log("无法转换字符集，使用解码后的文本: {$subject}");
                        }
                    } elseif ($encodedSubject) {
                        $subject = $decodedText;
                        error_log("解码主题: {$subject}");
                    }
                } elseif (function_exists('mb_detect_encoding')) {
                    // 如果没有明确的编码标记，尝试检测编码
                    $detectedEncoding = mb_detect_encoding($subject, ['UTF-8', 'ISO-8859-1', 'GBK', 'GB2312'], true);
                    if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
                        $convertedText = @iconv($detectedEncoding, 'UTF-8//IGNORE', $subject);
                        if ($convertedText !== false) {
                            $subject = $convertedText;
                            error_log("检测到主题编码为{$detectedEncoding}，已转换为UTF-8");
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('获取主题时出错: ' . $e->getMessage());
            $subject = '无主题';
        }
        
        // 处理日期
        $dateStr = '';
        try {
            $date = $message->getDate();
            error_log('原始日期类型: ' . gettype($date) . ', 值: ' . var_export($date, true));
            
            // 转换为日期字符串
            if ($date instanceof \DateTimeInterface) {
                $dateStr = $date->format('Y-m-d H:i:s');
                error_log('使用DateTimeInterface日期: ' . $dateStr);
            } elseif (is_string($date) && !empty($date)) {
                try {
                    $dateStr = (new \DateTime($date))->format('Y-m-d H:i:s');
                    error_log('从字符串解析日期: ' . $dateStr);
                } catch (\Exception $e) {
                    error_log('从字符串解析日期失败: ' . $e->getMessage());
                }
            } elseif (is_object($date)) {
                // 尝试多种方法从对象中获取日期
                if (method_exists($date, 'getValue')) {
                    try {
                        $dateValue = $date->getValue();
                        if (is_string($dateValue) && !empty($dateValue)) {
                            $dateStr = (new \DateTime($dateValue))->format('Y-m-d H:i:s');
                            error_log('从getValue()获取日期: ' . $dateStr);
                        }
                    } catch (\Exception $e) {
                        error_log('从getValue()获取日期失败: ' . $e->getMessage());
                    }
                } elseif (method_exists($date, 'toString')) {
                    try {
                        $dateValue = $date->toString();
                        if (is_string($dateValue) && !empty($dateValue)) {
                            $dateStr = (new \DateTime($dateValue))->format('Y-m-d H:i:s');
                            error_log('从toString()获取日期: ' . $dateStr);
                        }
                    } catch (\Exception $e) {
                        error_log('从toString()获取日期失败: ' . $e->getMessage());
                    }
                } elseif (method_exists($date, '__toString')) {
                    try {
                        $dateValue = (string)$date;
                        if (!empty($dateValue)) {
                            $dateStr = (new \DateTime($dateValue))->format('Y-m-d H:i:s');
                            error_log('从__toString()获取日期: ' . $dateStr);
                        }
                    } catch (\Exception $e) {
                        error_log('从__toString()获取日期失败: ' . $e->getMessage());
                    }
                }
            }
            
            // 如果上面的方法都失败，尝试从邮件头中获取日期
            if (empty($dateStr)) {
                try {
                    $headers = $message->getHeader();
                    if (is_object($headers)) {
                        if (isset($headers->date) && !empty($headers->date)) {
                            try {
                                $dateStr = (new \DateTime($headers->date))->format('Y-m-d H:i:s');
                                error_log('从headers->date获取日期: ' . $dateStr);
                            } catch (\Exception $e) {
                                error_log('从headers->date解析日期失败: ' . $e->getMessage());
                            }
                        } elseif (method_exists($headers, 'get')) {
                            $headerDate = $headers->get('date');
                            if (is_string($headerDate) && !empty($headerDate)) {
                                try {
                                    $dateStr = (new \DateTime($headerDate))->format('Y-m-d H:i:s');
                                    error_log('从headers->get("date")获取日期: ' . $dateStr);
                                } catch (\Exception $e) {
                                    error_log('从headers->get("date")解析日期失败: ' . $e->getMessage());
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log('从邮件头获取日期失败: ' . $e->getMessage());
                }
            }
            
            // 从原始邮件头中提取Date字段作为最后的尝试
            if (empty($dateStr) && is_string($rawHeaders)) {
                if (preg_match('/Date:\s*(.*?)(?:\r\n|\n)(?!\s)/m', $rawHeaders, $matches)) {
                    $dateHeader = trim($matches[1]);
                    try {
                        $dateStr = (new \DateTime($dateHeader))->format('Y-m-d H:i:s');
                        error_log('从原始邮件头Date字段解析日期: ' . $dateStr);
                    } catch (\Exception $e) {
                        error_log('从原始邮件头Date字段解析日期失败: ' . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('获取日期时出错: ' . $e->getMessage());
        }
        
        // 如果所有方法都失败，使用当前时间作为最后的回退
        if (empty($dateStr)) {
            $dateStr = (new \DateTime())->format('Y-m-d H:i:s');
            error_log('无法获取有效日期，使用当前时间作为回退: ' . $dateStr);
        }
        
        // 移除重复的收件人
        $recipients = array_unique(array_filter($recipients, function($item) {
            return $item !== null && trim($item) !== '';
        }));
        
        error_log('格式化后的收件人: ' . json_encode($recipients));
        error_log('格式化后的发件人: ' . ($fromName ? "$fromName <$fromEmail>" : $fromEmail));
        error_log('格式化后的主题: ' . $subject);
        error_log('====== 完成处理邮件 ======');
        
        // 返回最终结果
        return [
            'recipientsName' => $recipients[0] ?? '未知收件人',
            'recipientsTo' => !empty($recipients) ? $recipients : ['未知收件人'],
            'subject' => $subject ?: '无主题',
            'sentDate' => $dateStr,
            'fromEmail' => !empty($fromEmail) ? ($fromName ? "$fromName <$fromEmail>" : $fromEmail) : '未知发件人',
            'content' => $content,
        ];
    }

    /**
     * 测试IMAP连接（使用指定的密码而非存储的哈希）
     */
    public function testConnection(Mail $mailUser, string $plainPassword): array
    {
        // 从邮箱用户信息获取邮箱地址
        $email = $mailUser->getFullEmail();
        
        // 解析邮箱域名部分，确定IMAP服务器
        $domainPart = explode('@', $email)[1];
        
        // 获取IMAP服务器配置
        $imapConfig = $this->getImapConfigForDomain($domainPart);
        
        // 配置IMAP客户端
        $client = $this->clientWrapper->makeClient([
            'host'          => $imapConfig['host'],
            'port'          => $imapConfig['port'],
            'encryption'    => $imapConfig['encryption'],
            'validate_cert' => $imapConfig['validate_cert'],
            'username'      => $imapConfig['use_full_email'] ? $email : $mailUser->getUsername(),
            'password'      => $plainPassword, // 使用提供的明文密码
            'protocol'      => 'imap'
        ]);

        try {
            $this->clientWrapper->connect($client);
            
            // 如果成功连接，获取一些基本信息
            $folders = $this->clientWrapper->getFolders($client);
            $folderNames = [];
            
            foreach ($folders as $folder) {
                $folderNames[] = $folder->name;
            }
            
            return [
                'status' => 'success',
                'message' => '连接成功',
                'folders' => $folderNames,
                'server' => $imapConfig['host'],
                'username' => $imapConfig['use_full_email'] ? $email : $mailUser->getUsername()
            ];
        } catch (ConnectionFailedException $e) {
            throw new \Exception('无法连接到IMAP服务器: ' . $e->getMessage());
        }
    }
} 
