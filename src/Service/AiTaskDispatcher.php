<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Doctrine\ORM\EntityManagerInterface;
use Tacman\AiBatch\Contract\AiTaskMessageInterface;
use Tacman\AiBatch\Contract\BatchableAiTaskHandlerInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Model\AiExecutionMode;

final class AiTaskDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SymfonyBatchPlatformClient $batchClient,
    ) {
    }

    public function dispatch(AiTaskMessageInterface $message, BatchableAiTaskHandlerInterface $handler, AiExecutionMode $mode): ?AiBatch
    {
        if (AiExecutionMode::Sync === $mode) {
            $handler->run($message);

            return null;
        }

        return $this->dispatchBatch([$message], $handler);
    }

    /**
     * @param iterable<AiTaskMessageInterface> $messages
     */
    public function dispatchBatch(iterable $messages, BatchableAiTaskHandlerInterface $handler): AiBatch
    {
        $requests = [];
        $subjectIds = [];

        foreach ($messages as $message) {
            $requests[] = $handler->toBatchRequest($message);
            $subjectIds[] = (string) $message->subjectId();
        }

        if ([] === $requests) {
            throw new \InvalidArgumentException('At least one message is required to submit a batch task.');
        }

        $job = $this->batchClient->submitBatch($requests, ['max_tokens' => 200]);

        $batch = new AiBatch();
        $batch->provider = $job->provider;
        $batch->task = $handler::taskName();
        $batch->datasetKey = 'task:'.$handler::taskName();
        $batch->requestCount = count($requests);
        $batch->meta = [
            'mode' => 'batch',
            'subject_ids' => $subjectIds,
        ];
        $batch->markSubmitted($job->id, $job->inputFileId ?? '');
        $batch->applyProviderStatus($job->status, $job->completedCount, $job->failedCount, $job->outputFileId, $job->errorFileId);

        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }
}
