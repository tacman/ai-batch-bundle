<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Contract;

interface AiTaskMessageInterface
{
    public static function taskName(): string;

    public function subjectId(): string|int;

    public function dedupeKey(): ?string;
}
