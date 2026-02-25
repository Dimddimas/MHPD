<?php

namespace App\Entity;

use App\Repository\MarketPriceSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Schema real:
 *   id bigint PK | unit_id uuid FK | procedure_id uuid FK
 *   price numeric(10,2) NOT NULL | distance numeric(10,3) | collected_at timestamp
 *   source varchar(50)
 *
 *   UNIQUE: (unit_id, procedure_id, date(collected_at))
 *   NÃO existe coluna collected_date — é collected_at (timestamp)
 */
#[ORM\Entity(repositoryClass: MarketPriceSnapshotRepository::class)]
#[ORM\Table(name: 'market_price_snapshots')]
class MarketPriceSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MarketUnit::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true)]
    private ?MarketUnit $unit = null;

    #[ORM\ManyToOne(targetEntity: MarketProcedure::class)]
    #[ORM\JoinColumn(name: 'procedure_id', referencedColumnName: 'id', nullable: true)]
    private ?MarketProcedure $procedure = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, nullable: true)]
    private ?string $distance = null;

    #[ORM\Column(name: 'collected_at', type: 'datetime', options: ['default' => 'now()'])]
    private \DateTimeInterface $collectedAt;

    #[ORM\Column(length: 50, nullable: true, options: ['default' => 'medprev'])]
    private ?string $source = null;

    public function __construct() { $this->collectedAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getUnit(): ?MarketUnit { return $this->unit; }
    public function setUnit(?MarketUnit $v): static { $this->unit = $v; return $this; }
    public function getProcedure(): ?MarketProcedure { return $this->procedure; }
    public function setProcedure(?MarketProcedure $v): static { $this->procedure = $v; return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $v): static { $this->price = $v; return $this; }
    public function getDistance(): ?string { return $this->distance; }
    public function setDistance(?string $v): static { $this->distance = $v; return $this; }
    public function getCollectedAt(): \DateTimeInterface { return $this->collectedAt; }
    public function setCollectedAt(\DateTimeInterface $v): static { $this->collectedAt = $v; return $this; }
    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $v): static { $this->source = $v; return $this; }
}
