<?php

declare(strict_types=1);

namespace Astro\Verse\Routing;

/**
 * Who is allowed to write into reserved output areas.
 */
enum ClaimSource
{
    /** Pages, listings, attachments and public files. */
    case Content;

    /** The bundled theme's assets (`assets/theme/`). */
    case Theme;

    /** Files Verse itself writes (`404.html`, `.verse-output`). */
    case System;
}
