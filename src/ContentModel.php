<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp;

enum ContentModel
{
    case COMPOUND;
    case SIMPLE;
    case VERBATIM;
    case RAW;
    case EMPTY;
}
