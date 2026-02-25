<?php

namespace App\Entity;

use App\Repository\MarketProcedureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Schema real:
 *   id uuid PK | tuss_code varchar(20) NOT NULL | name text NOT NULL
 *   type varchar(50) | created_at timestamp
 */
#[ORM\Entity(repositoryClass: MarketProcedureRepository::class)]
#[ORM\Table(name: 'market_procedures')]
class MarketProcedure
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?Uuid $id = null;

    #[ORM\Column(name: 'tuss_code', length: 20)]
    private string $tussCode;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', options: ['default' => 'now()'])]
    private \DateTimeInterface $createdAt;

    public function __construct() { $this->createdAt = new \DateTime(); }

    public function getId(): ?Uuid { return $this->id; }
    public function setId(Uuid $id): static { $this->id = $id; return $this; }
    public function getTussCode(): string { return $this->tussCode; }
    public function setTussCode(string $v): static { $this->tussCode = $v; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(?string $v): static { $this->type = $v; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
