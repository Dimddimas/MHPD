<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'radar_sessions')]
#[ORM\HasLifecycleCallbacks]
class RadarSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $title = 'Sessão sem título';

    #[ORM\Column(type: 'json')]
    private array $history = [];

    #[ORM\Column(type: 'json')]
    private array $responses = [];

    #[ORM\Column(type: 'json')]
    private array $msgs = [];

    #[ORM\Column(type: 'integer')]
    private int $msgCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id)
    {
        $this->id        = $id;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }
    public function getHistory(): array { return $this->history; }
    public function setHistory(array $v): static { $this->history = $v; return $this; }
    public function getResponses(): array { return $this->responses; }
    public function setResponses(array $v): static { $this->responses = $v; return $this; }
    public function getMsgs(): array { return $this->msgs; }
    public function setMsgs(array $v): static { $this->msgs = $v; return $this; }
    public function getMsgCount(): int { return $this->msgCount; }
    public function setMsgCount(int $v): static { $this->msgCount = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'title'     => $this->title,
            'history'   => $this->history,
            'responses' => $this->responses,
            'msgs'      => $this->msgs,
            'msgCount'  => $this->msgCount,
            'updatedAt' => $this->updatedAt->getTimestamp() * 1000,
            'createdAt' => $this->createdAt->getTimestamp() * 1000,
        ];
    }
}
