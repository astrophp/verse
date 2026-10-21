<?php

declare(strict_types=1);

namespace Astro\Verse\Error;

enum ErrorKind: string
{
    case Config = 'config';
    case Content = 'content';
    case Theme = 'theme';
    case Filesystem = 'filesystem';
}
