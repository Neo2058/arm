<?php

namespace App\Enums;

enum ChatMessageType: string
{
    case TEXT = 'text';

    case IMAGE = 'image';

    case PDF = 'pdf';

    case SYSTEM = 'system';

    case DELETED = 'deleted';
}
