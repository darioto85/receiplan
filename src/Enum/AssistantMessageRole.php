<?php

namespace App\Enum;

enum AssistantMessageRole: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case SYSTEM = 'system';
}