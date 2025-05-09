<?php

namespace App\Entity;

use App\Repository\MailRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: MailRepository::class)]
#[ORM\Table(name: 'virtual_users')]
class Mail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domain_id', referencedColumnName: 'id', nullable: false)]
    private ?Domain $domain = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    private ?int $quota = null;

    #[ORM\OneToMany(mappedBy: 'mail', targetEntity: ApiToken::class, cascade: ['persist', 'remove'])]
    private Collection $apiTokens;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = '活跃';
        $this->apiTokens = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        if (strpos($email, '@') !== false) {
            $this->email = $email;
        } else {
            if ($this->domain) {
                $this->email = $email . '@' . $this->domain->getDomain();
            } else {
                $this->email = $email;
            }
        }
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(?Domain $domain): static
    {
        $this->domain = $domain;
        return $this;
    }

    public function getUsername(): ?string
    {
        if ($this->email) {
            if (strpos($this->email, '@') !== false) {
                return explode('@', $this->email)[0];
            }
            return $this->email;
        }
        return null;
    }

    public function getFullEmail(): ?string
    {
        if ($this->email) {
            if (strpos($this->email, '@') !== false) {
                return $this->email;
            }
            if ($this->domain) {
                return $this->email . '@' . $this->domain->getDomain();
            }
        }
        return $this->email;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getQuota(): ?int
    {
        return $this->quota;
    }

    public function setQuota(?int $quota): static
    {
        $this->quota = $quota;
        return $this;
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function getApiTokens(): Collection
    {
        return $this->apiTokens;
    }

    public function addApiToken(ApiToken $apiToken): static
    {
        if (!$this->apiTokens->contains($apiToken)) {
            $this->apiTokens->add($apiToken);
            $apiToken->setMail($this);
        }

        return $this;
    }

    public function removeApiToken(ApiToken $apiToken): static
    {
        if ($this->apiTokens->removeElement($apiToken)) {
            // 设置ApiToken的mail为null（如果关联是可选的）
            // $apiToken->setMail(null);
        }

        return $this;
    }

    public function updateEmailWithDomain(): static
    {
        if ($this->email && $this->domain) {
            $username = $this->getUsername();
            $this->email = $username . '@' . $this->domain->getDomain();
        }
        return $this;
    }
} 