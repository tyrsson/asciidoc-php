<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Reader;

readonly class Cursor
{
    public function __construct(
        public string|null $file,
        public string|null $dir,
        public string|null $path,
        public int $lineno = 1,
    ) {}

    public function advance(int $n = 1): self
    {
        return new self($this->file, $this->dir, $this->path, $this->lineno + $n);
    }

    public function __toString(): string
    {
        return ($this->path ?? 'string') . ':' . $this->lineno;
    }
}
