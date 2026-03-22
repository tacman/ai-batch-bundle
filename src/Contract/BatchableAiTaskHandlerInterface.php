<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Contract;

use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;

interface BatchableAiTaskHandlerInterface extends AiTaskHandlerInterface
{
    public function toBatchRequest(object $message): BatchRequest;

    public function applyBatchResult(object $message, BatchResult $result): void;
}
