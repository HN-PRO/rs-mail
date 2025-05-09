<?php

namespace App\Service;

class PasswordHashService
{
    /**
     * 使用 SHA-256 散列密码
     */
    public function hashPassword(string $password, string $salt = ''): string
    {
        // 如果没有提供盐值，则使用随机生成的盐值
        if (empty($salt)) {
            $salt = $this->generateSalt();
        }
        
        // 使用 crypt() 函数生成 SHA-256 兼容的哈希
        // crypt() 使用标准的 SHA-256 格式：$5$rounds=5000$salt$hash
        $hash = crypt($password, '$5$' . $salt);
        
        // 提取 crypt() 生成的哈希部分（去掉算法标识和盐值前缀）
        $hashParts = explode('$', $hash);
        $hashValue = end($hashParts);
        
        // 返回格式：{SHA256-CRYPT}$5$salt$hash
        return "{SHA256-CRYPT}$5$" . $salt . '$' . $hashValue;
    }
    
    /**
     * 验证密码
     */
    public function verifyPassword(string $password, string $hashedPassword): bool
    {
        // 检查是否是SHA-256-CRYPT格式
        if (str_starts_with($hashedPassword, '{SHA256-CRYPT}$5$')) {
            // 解析散列字符串
            $parts = explode('$', substr($hashedPassword, 15)); // 删除 "{SHA256-CRYPT}$5$" 前缀
            
            if (count($parts) >= 2) {
                $salt = $parts[0];
                $hash = $parts[1];
                
                // 使用相同的盐值和方法重新计算哈希
                $cryptedPassword = crypt($password, '$5$' . $salt);
                
                // 提取 crypt() 生成的哈希部分
                $cryptParts = explode('$', $cryptedPassword);
                $cryptHash = end($cryptParts);
                
                // 比较哈希值
                return hash_equals($hash, $cryptHash);
            }
            return false;
        } else if (str_starts_with($hashedPassword, 'SHA256$')) {
            // 旧格式的SHA-256哈希
            list(, $salt, $hash) = explode('$', $hashedPassword, 3);
            $computedHash = hash('sha256', $password . $salt);
            return hash_equals($hash, $computedHash);
        } else {
            // 向后兼容：如果不是SHA-256格式，则使用PHP的password_verify
            return password_verify($password, $hashedPassword);
        }
    }
    
    /**
     * 生成随机盐值
     */
    private function generateSalt(int $length = 16): string
    {
        // 生成符合SHA-256-CRYPT格式要求的盐值
        // 只使用字母、数字和特定字符
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789./';
        $salt = '';
        
        for ($i = 0; $i < $length; $i++) {
            $salt .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $salt;
    }
    
    /**
     * 检查密码是否需要重新散列（用于旧格式密码升级）
     */
    public function needsRehash(string $hashedPassword): bool
    {
        return !str_starts_with($hashedPassword, '{SHA256-CRYPT}$5$');
    }
} 