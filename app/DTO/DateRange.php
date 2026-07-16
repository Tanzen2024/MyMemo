<?php

namespace App\DTO;

class DateRange
{
    public function __construct(
        public ?\DateTime $start,
        public ?\DateTime $end
    ) {}

    public function isValid(): bool
    {
        return $this->start !== null && $this->end !== null;
    }
}