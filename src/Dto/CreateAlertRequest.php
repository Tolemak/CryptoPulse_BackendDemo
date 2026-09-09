<?php

namespace App\Dto;

use App\Enum\AlertCondition;
use App\Enum\Pair;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateAlertRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?Pair $pair = null,

        #[Assert\NotNull]
        public ?AlertCondition $condition = null,

        #[Assert\NotNull]
        #[Assert\Positive]
        public ?float $threshold = null,

        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        public ?string $webhookUrl = null,
    ) {
    }
}
