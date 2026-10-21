<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

enum PublicationState: string
{
    case Published = 'published';
    case Draft = 'draft';
    case Scheduled = 'scheduled';
}
