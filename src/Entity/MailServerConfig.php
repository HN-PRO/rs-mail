<?php

namespace App\Entity;

use App\Repository\MailServerConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailServerConfigRepository::class)]
class MailServerConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $domainPattern = null;

    #[ORM\Column(length: 255)]
    private ?string $host = null;

    #[ORM\Column]
    private ?int $port = null;

    #[ORM\Column(length: 50)]
    private ?string $encryption = null;

    #[ORM\Column]
    private ?bool $validateCert = true;

    #[ORM\Column]
    private ?bool $useFullEmail = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private ?bool $isActive = true;

    #[ORM\Column(nullable: true, length: 255)]
    private ?string $description = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomainPattern(): ?string
    {
        return $this->domainPattern;
    }

    public function setDomainPattern(string $domainPattern): static
    {
        $this->domainPattern = $domainPattern;
        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(string $host): static
    {
        $this->host = $host;
        return $this;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setPort(int $port): static
    {
        $this->port = $port;
        return $this;
    }

    public function getEncryption(): ?string
    {
        return $this->encryption;
    }

    public function setEncryption(string $encryption): static
    {
        $this->encryption = $encryption;
        return $this;
    }

    public function isValidateCert(): ?bool
    {
        return $this->validateCert;
    }

    public function setValidateCert(bool $validateCert): static
    {
        $this->validateCert = $validateCert;
        return $this;
    }

    public function isUseFullEmail(): ?bool
    {
        return $this->useFullEmail;
    }

    public function setUseFullEmail(bool $useFullEmail): static
    {
        $this->useFullEmail = $useFullEmail;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }
} 