<?php
declare(strict_types=1);

namespace Tacman\AiBatch;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Async batch AI processing for Symfony.
 *
 * Proposed for symfony/ai — see BatchCapablePlatformInterface.
 *
 * Quick start:
 *   1. Register OpenAiBatchClient (inject OPENAI_API_KEY)
 *   2. Run bin/console messenger:consume scheduler_default  ← polls every 2 min
 *   3. Use AiBatchBuilder::build() + submit() to queue work
 *   4. Results arrive in ApplyBatchResultsMessage handler
 */
class TacmanAiBatchBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(\Tacman\AiBatch\Service\SymfonyBatchPlatformClient::class)
            ->arg('$apiKey', '%env(OPENAI_API_KEY)%')
            ->tag('tacman.ai_batch.client');

        $services->set(\Tacman\AiBatch\Service\OpenAiBatchClient::class)
            ->arg('$apiKey', '%env(OPENAI_API_KEY)%')
            ->tag('tacman.ai_batch.client')
            ->deprecate('tacman/ai-batch-bundle', '0.2', 'Use "%service_id%" only for legacy code.');

        $services->set(\Tacman\AiBatch\Service\AnthropicBatchClient::class)
            ->arg('$apiKey', '%env(default::ANTHROPIC_API_KEY)%')
            ->tag('tacman.ai_batch.client')
            ->deprecate('tacman/ai-batch-bundle', '0.2', 'Use "%service_id%" only for legacy code.');

        $services->alias(\Tacman\AiBatch\Contract\BatchCapablePlatformInterface::class, \Tacman\AiBatch\Service\SymfonyBatchPlatformClient::class);

        $services->set(\Tacman\AiBatch\Controller\BatchUiController::class)
            ->tag('controller.service_arguments');

        $services->set(\Tacman\AiBatch\Service\AiBatchBuilder::class);
        $services->set(\Tacman\AiBatch\Service\AiTaskDispatcher::class);

        $services->set(\Tacman\AiBatch\Command\BatchListCommand::class)->tag('console.command');
        $services->set(\Tacman\AiBatch\Command\BatchStatusCommand::class)->tag('console.command');
        $services->set(\Tacman\AiBatch\Command\BatchWaitCommand::class)->tag('console.command');
        $services->set(\Tacman\AiBatch\Command\BatchCreateCommand::class)->tag('console.command');
        $services->set(\Tacman\AiBatch\Command\BatchDownloadCommand::class)->tag('console.command');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register AiBatch entity if Doctrine is available
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TacmanAiBatch' => [
                            'is_bundle' => false,
                            'type'      => 'attribute',
                            'dir'       => \dirname(__DIR__) . '/src/Entity',
                            'prefix'    => 'Tacman\\AiBatch\\Entity',
                            'alias'     => 'TacmanAiBatch',
                        ],
                    ],
                ],
            ]);
        }
    }
}
