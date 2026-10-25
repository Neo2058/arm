<?php

namespace App\Enums;

enum ChatMemberRole: string
{
    case MEMBER = 'member';

    case MODERATOR = 'moderator';
}
