<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ai_batch_result')]
#[ORM\UniqueConstraint(name: 'uniq_ai_batch_result', columns: ['ai_batch_id', 'custom_id'])]
#[ORM\Index(columns: ['ai_batch_id'])]
class AiBatchResult
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public int $aiBatchId;

    #[ORM\Column(length: 191)]
    public string $customId;

    #[ORM\Column]
    public bool $success = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public array|string|null $content = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $error = null;

    #[ORM\Column]
    public int $promptTokens = 0;

    #[ORM\Column]
    public int $outputTokens = 0;

    #[ORM\Column(type: Types::JSON)]
    public array $raw = [];

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
