<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Model;

enum AiExecutionMode: string
{
    case Sync = 'sync';
    case Batch = 'batch';
}
