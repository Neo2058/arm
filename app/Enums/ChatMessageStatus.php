<?php

namespace App\Enums;

enum ChatMessageStatus: string
{
    case QUEUED = 'queued';

    case AVAILABLE = 'available';

    case REJECTED = 'rejected';
}
