<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * Fetch products from dummyjson.com and either:
 *   - run synchronously via direct OpenAI calls (default)
 *   - generate a JSONL file and submit as a batch (--submit)
 *
 * Usage:
 *   bin/console app:load --limit=2
 *   bin/console app:load --limit=2 --prompt="Write haiku about this product"
 *   bin/console app:load --submit
 *   bin/console app:load --submit --limit=10 --output=var/batch/products.jsonl
 */
#[AsCommand('app:load', 'Generate ad copy for dummyjson products (sync or batch)')]
final class LoadCommand
{
    private const PRODUCTS_API = 'https://dummyjson.com/products';
    private const DEFAULT_PROMPT = 'Write punchy advertising copy for this product targeted at software developers. Reference programming culture. Max 2 sentences.';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly OpenAiBatchClient   $batchClient,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Number of products (0 = all ~194)')] int $limit = 0,
        #[Option('Prompt sent with each product image')] string $prompt = self::DEFAULT_PROMPT,
        #[Option('Model to use')] string $model = 'gpt-4o-mini',
        #[Option('Submit as OpenAI batch (async, 50% cheaper)')] bool $submit = false,
        #[Option('JSONL output path (for --submit or just to inspect)')] string $output = 'var/batch/products.jsonl',
    ): int {
        // Fetch products
        $params   = ['limit' => $limit > 0 ? $limit : 200, 'select' => 'id,title,description,category,price,thumbnail'];
        $products = $this->http->request('GET', self::PRODUCTS_API, ['query' => $params])
            ->toArray()['products'];

        $io->text(sprintf('Fetched <info>%d products</info> from dummyjson.com', count($products)));

        if ($submit) {
            return $this->submitBatch($io, $products, $prompt, $model, $output);
        }

        return $this->runSync($io, $products, $prompt, $model);
    }

    // ── Sync: call OpenAI directly, print results immediately ────────────────

    private function runSync(SymfonyStyle $io, array $products, string $prompt, string $model): int
    {
        $io->section(sprintf('Synchronous mode — %d API calls', count($products)));
        $io->note('Tip: use --submit for 50% cost reduction on large batches.');
        $io->newLine();

        foreach ($products as $product) {
            $response = $this->http->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'auth_bearer' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'),
                'json' => [
                    'model'      => $model,
                    'max_tokens' => 150,
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text',      'text' => $this->buildPrompt($product, $prompt)],
                            ['type' => 'image_url', 'image_url' => ['url' => $product['thumbnail'], 'detail' => 'low']],
                        ],
                    ]],
                ],
            ]);

            $copy = $response->toArray()['choices'][0]['message']['content'] ?? '(no response)';

            $io->writeln(sprintf('<info>#%d %s</info> — $%.2f', $product['id'], $product['title'], $product['price']));
            $io->writeln("  {$copy}");
            $io->newLine();
        }

        return 0;
    }

    // ── Batch: write JSONL, upload, submit ───────────────────────────────────

    private function submitBatch(SymfonyStyle $io, array $products, string $prompt, string $model, string $output): int
    {
        // Build JSONL
        @mkdir(dirname($output), 0775, true);
        $lines = [];
        foreach ($products as $product) {
            $lines[] = json_encode([
                'custom_id' => 'product-' . $product['id'],
                'method'    => 'POST',
                'url'       => '/v1/chat/completions',
                'body'      => [
                    'model'      => $model,
                    'max_tokens' => 150,
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text',      'text' => $this->buildPrompt($product, $prompt)],
                            ['type' => 'image_url', 'image_url' => ['url' => $product['thumbnail'], 'detail' => 'low']],
                        ],
                    ]],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $jsonl = implode("\n", $lines) . "\n";
        file_put_contents($output, $jsonl);
        $io->text(sprintf('Generated <info>%s</info> (%d requests)', $output, count($lines)));

        // Upload + submit
        $io->text('Uploading to OpenAI...');
        $fileId = $this->batchClient->uploadInputFile($jsonl);
        $io->text("Input file: <info>{$fileId}</info>");

        $job = $this->batchClient->submitFromFileId($fileId, [
            'metadata' => ['source' => 'dummyjson-demo', 'prompt' => substr($prompt, 0, 64)],
        ]);

        $io->success(sprintf('%d products submitted to OpenAI Batch API.', count($products)));
        $io->table(
            ['', ''],
            [
                ['Batch ID',    $job->id],
                ['Status',      $job->status],
                ['Requests',    count($products)],
                ['Est. cost',   sprintf('$%.4f (vs $%.4f sync = 50%% off)', count($products) * 0.0004, count($products) * 0.0008)],
            ]
        );

        $io->note([
            'Check status:',
            "  bin/console ai:batch:status {$job->id}",
            '',
            'Wait and auto-download:',
            "  bin/console ai:batch:wait {$job->id} --download=var/batch/results.jsonl",
            '',
            'Or just wait:',
            "  bin/console ai:batch:wait {$job->id}",
            "  bin/console ai:batch:download {$job->id} --output=var/batch/results.jsonl",
            "  cat var/batch/results.jsonl | jq '.response.body.choices[0].message.content'",
        ]);

        return 0;
    }

    private function buildPrompt(array $product, string $prompt): string
    {
        return sprintf(
            "Product: %s (category: %s, \$%.2f)\nDescription: %s\n\n%s",
            $product['title'], $product['category'], $product['price'],
            $product['description'], $prompt
        );
    }
}
