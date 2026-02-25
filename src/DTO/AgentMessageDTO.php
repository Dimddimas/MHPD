<?php

namespace App\DTO;

class AgentMessageDTO
{
    public function __construct(
        public readonly string $role,    
        public readonly string $content,
        public readonly ?string $createdAt = null,
    ) {}

    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
