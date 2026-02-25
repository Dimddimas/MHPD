<?php

namespace App\Service\Agent\Tool;

interface ToolInterface
{
    public function getName(): string;
    public function getDefinition(): array;
    public function execute(array $input): array;
}
