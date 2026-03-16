<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persists an AI batch job through its lifecycle.
 *
 * Workflow places:
 *   building → submitted → processing → completed
 *                                     ↘ failed | expired
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_batch')]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['provider', 'status'])]
class AiBatch
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Provider batch ID, e.g. "batch_abc123" */
    #[ORM\Column(nullable: true)]
    public ?string $providerBatchId = null;

    /** Provider: openai | anthropic | mistral */
    #[ORM\Column(length: 32)]
    public string $provider = 'openai';

    /** Task name from ai-pipeline: image_enrichment, ocr, classify, etc. */
    #[ORM\Column(length: 64)]
    public string $task = 'image_enrichment';

    /** Dataset key this batch covers, e.g. "fortepan/hu", "dc/0v83gg01j" */
    #[ORM\Column(nullable: true)]
    public ?string $datasetKey = null;

    /** building|submitted|processing|completed|failed|expired */
    #[ORM\Column(length: 32)]
    public string $status = 'building';

    /** Path to the local input JSONL file we uploaded */
    #[ORM\Column(nullable: true)]
    public ?string $inputFilePath = null;

    /** Provider file ID for the uploaded input */
    #[ORM\Column(nullable: true)]
    public ?string $inputFileId = null;

    /** Provider file ID for the output results */
    #[ORM\Column(nullable: true)]
    public ?string $outputFileId = null;

    /** Provider file ID for the error report */
    #[ORM\Column(nullable: true)]
    public ?string $errorFileId = null;

    #[ORM\Column]
    public int $requestCount = 0;

    #[ORM\Column]
    public int $completedCount = 0;

    #[ORM\Column]
    public int $failedCount = 0;

    /** Estimated cost in USD (requestCount * cost_per_request) */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    public ?string $estimatedCostUsd = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;

    /** Last time we polled the provider for status */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastPolledAt = null;

    /** How many results have been applied (written to 22_ai/ or zm) */
    #[ORM\Column]
    public int $appliedCount = 0;

    /** Free-form metadata: model used, prompt version, etc. */
    #[ORM\Column(type: Types::JSON)]
    public array $meta = [];

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['submitted', 'processing'], true);
    }

    public function isComplete(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool   { return in_array($this->status, ['failed', 'expired'], true); }
    public function isBuilding(): bool { return $this->status === 'building'; }

    public function markSubmitted(string $providerBatchId, string $inputFileId): void
    {
        $this->providerBatchId = $providerBatchId;
        $this->inputFileId     = $inputFileId;
        $this->status          = 'submitted';
        $this->submittedAt     = new \DateTimeImmutable();
    }

    public function applyProviderStatus(string $status, int $completed, int $failed, ?string $outputFileId, ?string $errorFileId): void
    {
        $this->status         = match ($status) {
            'validating', 'in_progress', 'finalizing' => 'processing',
            'completed'                                => 'completed',
            'failed', 'expired', 'cancelled'           => 'failed',
            default                                    => $this->status,
        };
        $this->completedCount = $completed;
        $this->failedCount    = $failed;
        $this->outputFileId   = $outputFileId ?? $this->outputFileId;
        $this->errorFileId    = $errorFileId  ?? $this->errorFileId;
        $this->lastPolledAt   = new \DateTimeImmutable();

        if ($this->isComplete()) {
            $this->completedAt = new \DateTimeImmutable();
        }
    }
}
