<?php

namespace App\Enums;

enum AnswerType: string
{
    case Boolean = 'boolean';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Text = 'text';
    case Number = 'number';
}
