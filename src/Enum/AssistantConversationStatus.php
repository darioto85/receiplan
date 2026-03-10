<?php

namespace App\Enum;

enum AssistantConversationStatus: string
{
    case CONTINUE = 'continue';
    case READY = 'ready';
    case OUT_OF_SCOPE = 'out_of_scope';
}