<?php

namespace App\Entity;

use App\Repository\MarketUnitRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Schema real:
 *   id uuid PK | partner_id uuid NOT NULL | social_name varchar(255)
 *   facility_name varchar(255) | city varchar(100) | state varchar(10)
 *   neighborhood varchar(150) | zipcode varchar(20) | address_line varchar(255)
 *   number varchar(20) | rating_avg numeric(4,3) | rating_count int | created_at timestamp
 */
#[ORM\Entity(repositoryClass: MarketUnitRepository::class)]
#[ORM\Table(name: 'market_units')]
class MarketUnit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?Uuid $id = null;

    #[ORM\Column(name: 'partner_id', type: UuidType::NAME)]
    private Uuid $partnerId;

    #[ORM\Column(name: 'social_name', length: 255, nullable: true)]
    private ?string $socialName = null;

    #[ORM\Column(name: 'facility_name', length: 255, nullable: true)]
    private ?string $facilityName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $neighborhood = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $zipcode = null;

    #[ORM\Column(name: 'address_line', length: 255, nullable: true)]
    private ?string $addressLine = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(name: 'rating_avg', type: 'decimal', precision: 4, scale: 3, nullable: true)]
    private ?string $ratingAvg = null;

    #[ORM\Column(name: 'rating_count', type: 'integer', nullable: true)]
    private ?int $ratingCount = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', options: ['default' => 'now()'])]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    public function __construct() { $this->createdAt = new \DateTime(); }

    public function getId(): ?Uuid { return $this->id; }
    public function setId(Uuid $id): static { $this->id = $id; return $this; }
    public function getPartnerId(): Uuid { return $this->partnerId; }
    public function setPartnerId(Uuid $v): static { $this->partnerId = $v; return $this; }
    public function getSocialName(): ?string { return $this->socialName; }
    public function setSocialName(?string $v): static { $this->socialName = $v; return $this; }
    public function getFacilityName(): ?string { return $this->facilityName; }
    public function setFacilityName(?string $v): static { $this->facilityName = $v; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }
    public function getState(): ?string { return $this->state; }
    public function setState(?string $v): static { $this->state = $v; return $this; }
    public function getNeighborhood(): ?string { return $this->neighborhood; }
    public function setNeighborhood(?string $v): static { $this->neighborhood = $v; return $this; }
    public function getZipcode(): ?string { return $this->zipcode; }
    public function setZipcode(?string $v): static { $this->zipcode = $v; return $this; }
    public function getAddressLine(): ?string { return $this->addressLine; }
    public function setAddressLine(?string $v): static { $this->addressLine = $v; return $this; }
    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $v): static { $this->number = $v; return $this; }
    public function getRatingAvg(): ?string { return $this->ratingAvg; }
    public function setRatingAvg(?string $v): static { $this->ratingAvg = $v; return $this; }
    public function getRatingCount(): ?int { return $this->ratingCount; }
    public function setRatingCount(?int $v): static { $this->ratingCount = $v; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getLatitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $v): static { $this->latitude = $v; return $this; }

    public function getLongitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $v): static { $this->longitude = $v; return $this; }


    /** Nome de exibição: prefere facility_name, fallback para social_name */
    public function getDisplayName(): string
    {
        return $this->facilityName ?? $this->socialName ?? 'Unidade sem nome';
    }
}
