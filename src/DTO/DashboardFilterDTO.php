<?php

namespace App\DTO;

class DashboardFilterDTO
{
    public function __construct(
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        /** @var string[] */
        public readonly array $procedureIds = [],
        /** @var string[] */
        public readonly array $unitIds = [],
        public readonly ?string $rating = null,
    ) {}

    public static function fromRequest(array $query): self
    {
        return new self(
            dateFrom:     $query['date_from']      ?? (new \DateTime('-30 days'))->format('Y-m-d'),
            dateTo:       $query['date_to']        ?? (new \DateTime())->format('Y-m-d'),
            procedureIds: (array)($query['procedure_ids'] ?? []),
            unitIds:      (array)($query['unit_ids']      ?? []),
            rating:       $query['rating']         ?? null,
        );
    }
}
