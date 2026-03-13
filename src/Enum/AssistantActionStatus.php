<?php

namespace App\Enum;

enum AssistantActionStatus: string
{
    case NEEDS_INPUT = 'needs_input';
    case READY = 'ready';
    case CANCELLED = 'cancelled';
    case BLOCKED = 'blocked';
}