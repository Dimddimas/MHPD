<?php

namespace App\Entity;

use App\Repository\MarketCollectionLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema real:
 *   id bigint PK | procedure_tuss varchar(20) | total_units int
 *   total_snapshots int | status varchar(20) | execution_time_ms int | created_at timestamp
 *
 *   NÃO existem: collected_date, total_units_scraped, total_snapshots_saved,
 *                error_message, duration_seconds
 */
#[ORM\Entity(repositoryClass: MarketCollectionLogRepository::class)]
#[ORM\Table(name: 'market_collection_logs')]
class MarketCollectionLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private ?int $id = null;

    #[ORM\Column(name: 'procedure_tuss', length: 20, nullable: true)]
    private ?string $procedureTuss = null;

    #[ORM\Column(name: 'total_units', type: 'integer', nullable: true)]
    private ?int $totalUnits = null;

    #[ORM\Column(name: 'total_snapshots', type: 'integer', nullable: true)]
    private ?int $totalSnapshots = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'execution_time_ms', type: 'integer', nullable: true)]
    private ?int $executionTimeMs = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', options: ['default' => 'now()'])]
    private \DateTimeInterface $createdAt;

    public function __construct() { $this->createdAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getProcedureTuss(): ?string { return $this->procedureTuss; }
    public function setProcedureTuss(?string $v): static { $this->procedureTuss = $v; return $this; }
    public function getTotalUnits(): ?int { return $this->totalUnits; }
    public function setTotalUnits(?int $v): static { $this->totalUnits = $v; return $this; }
    public function getTotalSnapshots(): ?int { return $this->totalSnapshots; }
    public function setTotalSnapshots(?int $v): static { $this->totalSnapshots = $v; return $this; }
    public function getStatus(): ?string { return $this->status; }
    public function setStatus(?string $v): static { $this->status = $v; return $this; }
    public function getExecutionTimeMs(): ?int { return $this->executionTimeMs; }
    public function setExecutionTimeMs(?int $v): static { $this->executionTimeMs = $v; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
