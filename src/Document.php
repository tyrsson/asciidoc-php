<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp;

use Webware\AsciidocPhp\Converter\ConverterInterface;
use Webware\AsciidocPhp\Node\AbstractBlock;
use Webware\AsciidocPhp\Node\AbstractNode;
use Webware\AsciidocPhp\Reader\Cursor;

/**
 * The root AST node and orchestrator for a single AsciiDoc conversion.
 *
 * Document holds the attribute store, the catalog of resolved IDs / refs /
 * footnotes / images, the active converter, and the safe mode. It also
 * implements DocumentInterface so PreprocessorReader can work with it without
 * a circular dependency on the concrete class.
 */
class Document extends AbstractBlock implements DocumentInterface
{
    // ── Document attribute store ──────────────────────────────────────────────

    /**
     * @var array<string, string|false>
     *   string  → attribute is set with this value
     *   false   → attribute was explicitly unset (sentinel)
     */
    private array $docAttributes = [];

    /** @var list<string> Attribute names locked by CLI / API (cannot be overridden). */
    private array $lockedAttributes = [];

    // ── Catalog ───────────────────────────────────────────────────────────────

    /**
     * @var array{
     *   ids:       array<string, AbstractNode>,
     *   refs:      array<string, AbstractNode>,
     *   footnotes: list<array<string, mixed>>,
     *   images:    list<string>,
     * }
     */
    private array $catalog = [
        'ids'       => [],
        'refs'      => [],
        'footnotes' => [],
        'images'    => [],
    ];

    // ── Infrastructure ────────────────────────────────────────────────────────

    private SafeMode $safe;

    private string $backend;

    private string $doctype;

    private string|null $baseDir;

    private bool $sourcemap = false;

    private ConverterInterface $converter;

    /**
     * @param array<string, mixed> $options Recognised keys:
     *   'safe'      => SafeMode (default SAFE)
     *   'backend'   => string   (default 'html5')
     *   'doctype'   => string   (default 'article')
     *   'attributes'=> array<string, string|false>
     *   'base_dir'  => string|null
     *   'sourcemap' => bool
     *   'converter' => ConverterInterface
     */
    public function __construct(array $options = [])
    {
        parent::__construct('document', 'document', $this, null);

        $safe = $options['safe'] ?? SafeMode::SAFE;
        $this->safe = $safe instanceof SafeMode ? $safe : SafeMode::SAFE;

        $backend = $options['backend'] ?? 'html5';
        $this->backend = is_string($backend) ? $backend : 'html5';

        $doctype = $options['doctype'] ?? 'article';
        $this->doctype = is_string($doctype) ? $doctype : 'article';

        $baseDir = $options['base_dir'] ?? null;
        $this->baseDir = is_string($baseDir) ? $baseDir : null;

        $this->sourcemap = (bool) ($options['sourcemap'] ?? false);

        // Converter must be injected; fall back to a NullConverter for tests.
        $converter = $options['converter'] ?? null;
        $this->converter = $converter instanceof ConverterInterface ? $converter : new NullConverter();

        // Load built-in defaults.
        $this->initBuiltinAttributes();

        // Overlay caller-supplied attributes.
        /** @var array<string, string|false> $initialAttrs */
        $initialAttrs = is_array($options['attributes'] ?? null) ? $options['attributes'] : [];
        foreach ($initialAttrs as $name => $value) {
            if ($value === false) {
                $this->unsetAttribute($name);
            } else {
                $this->setAttribute($name, $value);
            }
        }
    }

    // ── DocumentInterface ─────────────────────────────────────────────────────

    public function setAttribute(string $name, mixed $value, bool $overridable = true): void
    {
        if (!$overridable) {
            $this->lockedAttributes[] = $name;
        } elseif (in_array($name, $this->lockedAttributes, true)) {
            return;
        }
        $this->docAttributes[$name] = is_string($value) ? $value : '';
    }

    public function unsetAttribute(string $name): void
    {
        if (in_array($name, $this->lockedAttributes, true)) {
            return;
        }
        $this->docAttributes[$name] = false;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->docAttributes[$name])
            && $this->docAttributes[$name] !== false;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, $this->docAttributes)) {
            return $default;
        }
        $value = $this->docAttributes[$name];
        return $value === false ? $default : $value;
    }

    public function getSafeMode(): SafeMode
    {
        return $this->safe;
    }

    public function getBaseDir(): string|null
    {
        return $this->baseDir;
    }

    // ── Converter ─────────────────────────────────────────────────────────────

    public function getConverter(): ConverterInterface
    {
        return $this->converter;
    }

    public function setConverter(ConverterInterface $converter): void
    {
        $this->converter = $converter;
    }

    // ── Catalog ───────────────────────────────────────────────────────────────

    /**
     * Register an item in the document catalog.
     *
     * @param 'ids'|'refs'|'footnotes'|'images' $type
     * @param mixed                              $data
     */
    public function register(string $type, mixed $data): void
    {
        if ($type === 'ids' && $data instanceof AbstractNode) {
            $id = $data->getId();
            if ($id !== null) {
                $this->catalog['ids'][$id] = $data;
            }
        } elseif ($type === 'refs' && $data instanceof AbstractNode) {
            $id = $data->getId();
            if ($id !== null) {
                $this->catalog['refs'][$id] = $data;
            }
        } elseif ($type === 'footnotes' && is_array($data)) {
            /** @var array<string, mixed> $data */
            $this->catalog['footnotes'][] = $data;
        } elseif ($type === 'images' && is_string($data)) {
            $this->catalog['images'][] = $data;
        }
    }

    /**
     * Resolve a reference ID to the node registered under it, or null.
     */
    public function resolveId(string $refid): AbstractNode|null
    {
        return $this->catalog['ids'][$refid] ?? null;
    }

    // ── Title / metadata helpers ──────────────────────────────────────────────

    public function getDocTitle(): string|null
    {
        $v = $this->getAttribute('doctitle');
        return is_string($v) ? $v : null;
    }

    public function getAuthor(): string|null
    {
        $v = $this->getAttribute('author');
        return is_string($v) ? $v : null;
    }

    public function getRevDate(): string|null
    {
        $v = $this->getAttribute('revdate');
        return is_string($v) ? $v : null;
    }

    // ── Conversion ───────────────────────────────────────────────────────────

    /**
     * Convert the document to its output representation.
     */
    public function convert(): string
    {
        return $this->converter->convert($this);
    }

    // ── Misc ─────────────────────────────────────────────────────────────────

    public function isSourcemapEnabled(): bool
    {
        return $this->sourcemap;
    }

    public function getBackend(): string
    {
        return $this->backend;
    }

    public function getDoctype(): string
    {
        return $this->doctype;
    }

    // ── Built-in attributes ───────────────────────────────────────────────────

    private function initBuiltinAttributes(): void
    {
        $date     = date('Y-m-d');
        $time     = date('H:i:s') . ' UTC';
        $datetime = $date . ' ' . $time;

        $defaults = [
            'asciidoc-version'    => '0.1.0',
            'asciidoctor-version' => '0.1.0',
            'backend'             => $this->backend,
            'basebackend'         => $this->resolveBaseBackend($this->backend),
            'doctype'             => $this->doctype,
            'encoding'            => 'UTF-8',
            'filetype'            => 'html',
            'outfilesuffix'       => '.html',
            'toc-placement'       => 'auto',
            'toc-title'           => 'Table of Contents',
            'caution-caption'     => 'Caution',
            'important-caption'   => 'Important',
            'note-caption'        => 'Note',
            'tip-caption'         => 'Tip',
            'warning-caption'     => 'Warning',
            'docdate'             => $date,
            'doctime'             => $time,
            'docdatetime'         => $datetime,
            'localdate'           => $date,
            'localtime'           => $time,
            'localdatetime'       => $datetime,
            'table-caption'       => 'Table',
            'figure-caption'      => 'Figure',
            'example-caption'     => 'Example',
            'last-update-label'   => 'Last updated',
        ];

        foreach ($defaults as $name => $value) {
            $this->docAttributes[$name] = $value;
        }
    }

    private function resolveBaseBackend(string $backend): string
    {
        return match (true) {
            str_starts_with($backend, 'html') => 'html',
            str_starts_with($backend, 'docbook') => 'docbook',
            default => $backend,
        };
    }
}
