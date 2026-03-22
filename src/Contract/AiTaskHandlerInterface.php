<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Contract;

interface AiTaskHandlerInterface
{
    public static function taskName(): string;

    public function run(object $message): object;
}
