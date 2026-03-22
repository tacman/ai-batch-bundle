<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Symfony\AI\Platform\Batch\BatchInput;
use Symfony\AI\Platform\Batch\BatchStatus;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;
use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;

final class SymfonyBatchPlatformClient implements BatchCapablePlatformInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {
    }

    public function supportsBatch(): bool
    {
        return true;
    }

    public function submitBatch(array $requests, array $options = []): BatchJob
    {
        if ([] === $requests) {
            throw new \InvalidArgumentException('At least one request is required to submit a batch.');
        }

        $model = $requests[0]->model;

        $inputs = (static function () use ($requests): \Generator {
            foreach ($requests as $request) {
                $userMessage = null === $request->imageUrl
                    ? Message::ofUser($request->userPrompt)
                    : Message::ofUser($request->userPrompt, new ImageUrl($request->imageUrl, 'low'));

                yield new BatchInput(
                    $request->customId,
                    new MessageBag(
                        Message::forSystem($request->systemPrompt),
                        $userMessage,
                    )
                );
            }
        })();

        $job = $this->platform()->submitBatch($model, $inputs, $options);

        return $this->toLegacyJob($job);
    }

    public function checkBatch(string $batchId): BatchJob
    {
        return $this->toLegacyJob($this->platform()->getBatch($batchId));
    }

    public function fetchResults(BatchJob $job): iterable
    {
        $providerJob = $this->platform()->getBatch($job->id);

        foreach ($this->platform()->fetchResults($providerJob) as $result) {
            if ($result->isSuccess()) {
                yield new BatchResult(
                    customId: $result->getId(),
                    content: $result->getContent(),
                    success: true,
                    promptTokens: $result->getInputTokens(),
                    outputTokens: $result->getOutputTokens(),
                );

                continue;
            }

            yield new BatchResult(
                customId: $result->getId(),
                content: null,
                success: false,
                error: $result->getError(),
            );
        }
    }

    public function cancelBatch(string $batchId): BatchJob
    {
        return $this->toLegacyJob($this->platform()->cancelBatch($batchId));
    }

    private function platform(): \Symfony\AI\Platform\Batch\BatchPlatform
    {
        return PlatformFactory::createBatch($this->apiKey, $this->httpClient);
    }

    private function toLegacyJob(\Symfony\AI\Platform\Batch\BatchJob $job): BatchJob
    {
        $processedCount = $job->getProcessedCount();
        $failedCount = $job->getFailedCount();

        return new BatchJob(
            id: $job->getId(),
            status: $this->mapStatus($job->getStatus()),
            provider: 'openai',
            outputFileId: $job->getOutputFileId(),
            errorFileId: $job->getErrorFileId(),
            totalCount: $job->getTotalCount(),
            completedCount: max(0, $processedCount - $failedCount),
            failedCount: $failedCount,
        );
    }

    private function mapStatus(BatchStatus $status): string
    {
        return match ($status) {
            BatchStatus::PENDING => 'validating',
            BatchStatus::PROCESSING => 'in_progress',
            BatchStatus::COMPLETED => 'completed',
            BatchStatus::FAILED => 'failed',
            BatchStatus::CANCELLED => 'cancelled',
            BatchStatus::EXPIRED => 'expired',
        };
    }
}
