<?php

namespace App\Enums;

enum UserNotificationType: string
{
    case ChatMessage = 'chat_message';
    case FormularEntry = 'formular_entry';
    case FormularReview = 'formular_review';
    case InstructorNotice = 'instructor_notice';
}
