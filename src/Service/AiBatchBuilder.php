<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Model\BatchRequest;

use function trigger_deprecation;

/**
 * Builds and submits a batch from an iterable of records.
 *
 * Usage:
 *   $batch = $builder->build('fortepan/hu', 'image_enrichment', $records);
 *   $builder->submit($batch);
 *
 * The caller provides a RequestFactory callable that turns a record
 * into a BatchRequest. This keeps the builder agnostic of the task.
 *
 *   $factory = fn(array $row) => new BatchRequest(
 *       customId:     (string)$row['id'],
 *       systemPrompt: $systemPrompt,
 *       userPrompt:   $userPrompt,
 *       model:        'gpt-4o-mini',
 *       imageUrl:     $row['thumbnail_url'],
 *   );
 */
final class AiBatchBuilder
{
    /** Docs say 50k, leave headroom */
    private const MAX_PER_BATCH = 49_000;

    public function __construct(
        private readonly BatchCapablePlatformInterface $client,
    ) {
        trigger_deprecation('tacman/ai-batch-bundle', '0.2', 'The "%s" service is deprecated, prefer dispatching task messages through the new task dispatcher services.', self::class);
    }

    /**
     * Build an AiBatch entity from an iterable of records.
     * Does NOT submit — call submit() separately.
     *
     * @param iterable<array>    $records
     * @param callable(array): BatchRequest $requestFactory
     */
    public function build(
        string   $datasetKey,
        string   $task,
        iterable $records,
        callable $requestFactory,
        array    $meta = [],
    ): AiBatch {
        $batch = new AiBatch();
        $batch->datasetKey = $datasetKey;
        $batch->task       = $task;
        $batch->provider   = 'openai'; // TODO: detect from $client
        $batch->meta       = $meta;

        // Write input JSONL to temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'ai_batch_') . '.jsonl';
        $handle  = fopen($tmpFile, 'w');
        $count   = 0;

        foreach ($records as $record) {
            if ($count >= self::MAX_PER_BATCH) {
                break;
            }
            $request = $requestFactory($record);
            fwrite($handle, json_encode($request->toOpenAiLine(), JSON_THROW_ON_ERROR) . "\n");
            $count++;
        }

        fclose($handle);

        $batch->inputFilePath = $tmpFile;
        $batch->requestCount  = $count;

        return $batch;
    }

    /**
     * Upload the input file and create the batch on the provider.
     * Updates $batch in place with providerBatchId and status.
     */
    public function submit(AiBatch $batch, array $options = []): AiBatch
    {
        if (!is_file($batch->inputFilePath)) {
            throw new \RuntimeException("Input file not found: {$batch->inputFilePath}");
        }

        // Read requests back from JSONL for submission
        // (AiBatchBuilder stores them as JSONL lines, not BatchRequest objects)
        $content = file_get_contents($batch->inputFilePath);
        $job = $this->client->submitBatch(
            $this->parseJsonlRequests($content),
            $options
        );

        $batch->markSubmitted($job->id, $job->inputFileId ?? '');

        // Clean up temp file after submission
        @unlink($batch->inputFilePath);
        $batch->inputFilePath = null;

        return $batch;
    }

    /** @return BatchRequest[] */
    private function parseJsonlRequests(string $jsonl): array
    {
        $requests = [];
        foreach (explode("\n", trim($jsonl)) as $line) {
            if ($line === '') continue;
            $data     = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $body     = $data['body'];
            $messages = $body['messages'];

            $system = '';
            $user   = '';
            $image  = null;
            foreach ($messages as $m) {
                if ($m['role'] === 'system') {
                    $system = $m['content'];
                } elseif ($m['role'] === 'user') {
                    if (is_array($m['content'])) {
                        foreach ($m['content'] as $part) {
                            if ($part['type'] === 'text')      $user  = $part['text'];
                            if ($part['type'] === 'image_url') $image = $part['image_url']['url'];
                        }
                    } else {
                        $user = $m['content'];
                    }
                }
            }

            $requests[] = new BatchRequest(
                customId:     $data['custom_id'],
                systemPrompt: $system,
                userPrompt:   $user,
                model:        $body['model'],
                imageUrl:     $image,
            );
        }
        return $requests;
    }
}
