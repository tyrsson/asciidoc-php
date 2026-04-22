<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp;

enum SafeMode: int
{
    case UNSAFE = 0;
    case SAFE   = 1;
    case SERVER = 10;
    case SECURE = 20;
}
