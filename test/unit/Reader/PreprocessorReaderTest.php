<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Reader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Reader\Cursor;
use Webware\AsciidocPhp\Reader\PreprocessorReader;
use Webware\AsciidocPhp\SafeMode;
use Webware\AsciidocPhp\TestAsset\Reader\DocumentStub;

#[CoversClass(PreprocessorReader::class)]
final class PreprocessorReaderTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function cursor(): Cursor
    {
        return new Cursor(null, null, null, 1);
    }

    private function reader(string $source, DocumentStub|null $doc = null): PreprocessorReader
    {
        return new PreprocessorReader(
            $doc ?? new DocumentStub(),
            $source,
            $this->cursor(),
        );
    }

    // ── Passthrough ───────────────────────────────────────────────────────────

    public function testPlainContentPassesThrough(): void
    {
        $r = $this->reader("hello\nworld");

        $this->assertSame('hello', $r->readLine());
        $this->assertSame('world', $r->readLine());
        $this->assertNull($r->readLine());
    }

    // ── Attribute entries ─────────────────────────────────────────────────────

    public function testAttributeEntryIsConsumedAndSetOnDocument(): void
    {
        $doc = new DocumentStub();
        $r   = $this->reader(":my-attr: hello world\ncontent", $doc);

        $this->assertSame('content', $r->readLine());
        $this->assertSame('hello world', $doc->getAttribute('my-attr'));
    }

    public function testAttributeEntryBooleanFlag(): void
    {
        $doc = new DocumentStub();
        $r   = $this->reader(":toc:\ncontent", $doc);

        $r->readLine();
        $this->assertSame('', $doc->getAttribute('toc'));
    }

    public function testAttributeEntryTrailingBangUnsetsAttribute(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('toc', '');
        $r = $this->reader(":toc!:\ncontent", $doc);

        $r->readLine();
        $this->assertFalse($doc->hasAttribute('toc'));
    }

    public function testAttributeEntryLeadingBangUnsetsAttribute(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('toc', '');
        $r = $this->reader(":!toc:\ncontent", $doc);

        $r->readLine();
        $this->assertFalse($doc->hasAttribute('toc'));
    }

    public function testMultipleAttributeEntriesProcessedInOrder(): void
    {
        $doc = new DocumentStub();
        $r   = $this->reader(":a: one\n:b: two\nend", $doc);

        $this->assertSame('end', $r->readLine());
        $this->assertSame('one', $doc->getAttribute('a'));
        $this->assertSame('two', $doc->getAttribute('b'));
    }

    // ── Single-line comments ──────────────────────────────────────────────────

    public function testSingleLineCommentIsConsumed(): void
    {
        $r = $this->reader("// this is a comment\ncontent");

        $this->assertSame('content', $r->readLine());
    }

    public function testCommentBlockDelimiterIsNotConsumed(): void
    {
        // //// is a comment block delimiter — NOT a single-line comment
        $r = $this->reader("////\ncontent");

        $this->assertSame("////\ncontent", implode("\n", $r->getLines()));
    }

    public function testTripleSlashIsTreatedAsComment(): void
    {
        // /// foo — treated as single-line comment (starts with // and third char is not /)
        $r = $this->reader("/// nope\ncontent");

        $this->assertSame('content', $r->readLine());
    }

    // ── ifdef / endif ─────────────────────────────────────────────────────────

    public function testIfdefBlockSkippedWhenAttrNotDefined(): void
    {
        $r = $this->reader("ifdef::myattr[]\nskipped\nendif::[]\ncontent");

        $this->assertSame('content', $r->readLine());
    }

    public function testIfdefBlockIncludedWhenAttrDefined(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('myattr', '');
        $r = $this->reader("ifdef::myattr[]\nincluded\nendif::[]\ncontent", $doc);

        $this->assertSame('included', $r->readLine());
        $this->assertSame('content',  $r->readLine());
    }

    public function testIfndefBlockIncludedWhenAttrNotDefined(): void
    {
        $r = $this->reader("ifndef::myattr[]\nincluded\nendif::[]\ncontent");

        $this->assertSame('included', $r->readLine());
        $this->assertSame('content',  $r->readLine());
    }

    public function testIfndefBlockSkippedWhenAttrDefined(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('myattr', '');
        $r = $this->reader("ifndef::myattr[]\nskipped\nendif::[]\ncontent", $doc);

        $this->assertSame('content', $r->readLine());
    }

    public function testIfdefInlineBodyInjectedWhenTrue(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('flag', '');
        $r = $this->reader("ifdef::flag[injected line]\ncontent", $doc);

        $this->assertSame('injected line', $r->readLine());
        $this->assertSame('content',       $r->readLine());
    }

    public function testIfdefInlineBodyNotInjectedWhenFalse(): void
    {
        $r = $this->reader("ifdef::flag[injected line]\ncontent");

        $this->assertSame('content', $r->readLine());
    }

    // ── else:: ────────────────────────────────────────────────────────────────

    public function testElseFlipsConditionWhenFalse(): void
    {
        // myattr not defined → ifdef is false → lines before else are skipped
        $r = $this->reader(
            "ifdef::myattr[]\nskipped\nelse::[]\nkept\nendif::[]\nend"
        );

        $this->assertSame('kept', $r->readLine());
        $this->assertSame('end',  $r->readLine());
    }

    public function testElseFlipsConditionWhenTrue(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('myattr', '');
        $r = $this->reader(
            "ifdef::myattr[]\nkept\nelse::[]\nskipped\nendif::[]\nend",
            $doc
        );

        $this->assertSame('kept', $r->readLine());
        $this->assertSame('end',  $r->readLine());
    }

    public function testSecondElseIsNoop(): void
    {
        // myattr not defined → ifdef is false.
        // First else:: flips to true → "kept" passes through.
        // Second else:: is a no-op (sawElse already true) → satisfying stays true
        // → "also-skipped" also passes through (it is in the same branch as "kept").
        $r = $this->reader(
            "ifdef::myattr[]\nskipped\nelse::[]\nkept\nelse::[]\nalso-not-skipped\nendif::[]\nend"
        );

        $this->assertSame('kept',             $r->readLine());
        $this->assertSame('also-not-skipped', $r->readLine());
        $this->assertSame('end',              $r->readLine());
    }

    // ── AND / OR conditions ───────────────────────────────────────────────────

    public function testIfdefAndConditionTrueWhenAllDefined(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('a', '');
        $doc->setAttribute('b', '');
        $r = $this->reader("ifdef::a+b[]\nyes\nendif::[]\nend", $doc);

        $this->assertSame('yes', $r->readLine());
        $this->assertSame('end', $r->readLine());
    }

    public function testIfdefAndConditionFalseWhenOneMissing(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('a', '');
        // 'b' not defined
        $r = $this->reader("ifdef::a+b[]\nno\nendif::[]\nend", $doc);

        $this->assertSame('end', $r->readLine());
    }

    public function testIfdefOrConditionTrueWhenOneIsSet(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('b', '');
        // 'a' not defined
        $r = $this->reader("ifdef::a,b[]\nyes\nendif::[]\nend", $doc);

        $this->assertSame('yes', $r->readLine());
        $this->assertSame('end', $r->readLine());
    }

    public function testIfdefOrConditionFalseWhenNoneDefined(): void
    {
        $r = $this->reader("ifdef::a,b[]\nno\nendif::[]\nend");

        $this->assertSame('end', $r->readLine());
    }

    // ── Nested conditionals ───────────────────────────────────────────────────

    public function testAttributeEntryInsideSkippedBlockIsNotProcessed(): void
    {
        $doc = new DocumentStub();
        // myattr not defined → block is skipped
        $r = $this->reader(
            "ifdef::myattr[]\n:hidden-attr: value\nendif::[]\ncontent",
            $doc
        );

        $r->readLine(); // consume 'content'
        // hidden-attr should NOT have been set
        $this->assertNull($doc->getRawAttribute('hidden-attr'));
    }

    // ── include:: ─────────────────────────────────────────────────────────────

    public function testIncludeInjectsFileContent(): void
    {
        $tmpFile = sys_get_temp_dir() . '/ppr_test_' . uniqid() . '.adoc';
        file_put_contents($tmpFile, "included line 1\nincluded line 2\n");

        $doc = new DocumentStub(SafeMode::UNSAFE, dirname($tmpFile));
        $r   = new PreprocessorReader(
            $doc,
            "before\ninclude::{$tmpFile}[]\nafter",
            $this->cursor(),
        );

        try {
            $this->assertSame('before',          $r->readLine());
            $this->assertSame('included line 1', $r->readLine());
            $this->assertSame('included line 2', $r->readLine());
            $this->assertSame('after',           $r->readLine());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testIncludeInSecureModeIsSuppressed(): void
    {
        $tmpFile = sys_get_temp_dir() . '/ppr_secure_' . uniqid() . '.adoc';
        file_put_contents($tmpFile, "should not appear\n");

        $doc = new DocumentStub(SafeMode::SECURE);
        $r   = new PreprocessorReader(
            $doc,
            "before\ninclude::{$tmpFile}[]\nafter",
            $this->cursor(),
        );

        try {
            $this->assertSame('before', $r->readLine());
            $this->assertSame('after',  $r->readLine());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testIncludeMissingFileIsSkippedGracefully(): void
    {
        $nonExistent = sys_get_temp_dir() . '/ppr_missing_' . uniqid() . '.adoc';

        $doc = new DocumentStub(SafeMode::UNSAFE);
        $r   = new PreprocessorReader(
            $doc,
            "before\ninclude::{$nonExistent}[]\nafter",
            $this->cursor(),
        );

        $this->assertSame('before', $r->readLine());
        $this->assertSame('after',  $r->readLine());
    }

    public function testIncludeWithTagFilter(): void
    {
        $tmpFile = sys_get_temp_dir() . '/ppr_tag_' . uniqid() . '.adoc';
        file_put_contents(
            $tmpFile,
            "preamble\n// tag::example[]\ntagged content\n// end::example[]\npostamble\n",
        );

        $doc = new DocumentStub(SafeMode::UNSAFE, dirname($tmpFile));
        $r   = new PreprocessorReader(
            $doc,
            "include::{$tmpFile}[tag=example]",
            $this->cursor(),
        );

        try {
            $this->assertSame('tagged content', $r->readLine());
            $this->assertNull($r->readLine());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testIncludeWithLineRangeFilter(): void
    {
        $tmpFile = sys_get_temp_dir() . '/ppr_lines_' . uniqid() . '.adoc';
        file_put_contents($tmpFile, "line1\nline2\nline3\nline4\nline5\n");

        $doc = new DocumentStub(SafeMode::UNSAFE, dirname($tmpFile));
        $r   = new PreprocessorReader(
            $doc,
            "include::{$tmpFile}[lines=2..4]",
            $this->cursor(),
        );

        try {
            $this->assertSame('line2', $r->readLine());
            $this->assertSame('line3', $r->readLine());
            $this->assertSame('line4', $r->readLine());
            $this->assertNull($r->readLine());
        } finally {
            unlink($tmpFile);
        }
    }

    // ── Cursor updates through includes ──────────────────────────────────────

    public function testCursorReflectsIncludedFile(): void
    {
        $tmpFile = sys_get_temp_dir() . '/ppr_cursor_' . uniqid() . '.adoc';
        file_put_contents($tmpFile, "inc line\n");

        $doc = new DocumentStub(SafeMode::UNSAFE, dirname($tmpFile));
        $r   = new PreprocessorReader(
            $doc,
            "before\ninclude::{$tmpFile}[]",
            $this->cursor(),
        );

        try {
            $r->readLine(); // 'before'
            $r->readLine(); // 'inc line'
            // After reading from included file, cursor should reference that file
            $this->assertSame($tmpFile, $r->getCursor()->file);
        } finally {
            unlink($tmpFile);
        }
    }

    // ── Inheritance of Reader behaviour ──────────────────────────────────────

    public function testPeekLineDoesNotConsumeDirectives(): void
    {
        $doc = new DocumentStub();
        $r   = $this->reader(":attr: value\ncontent", $doc);

        // Even with lookahead, attribute entry is processed exactly once
        $r->peekLine();
        $first = $r->readLine();

        $this->assertSame('content', $first);
        $this->assertSame('value',   $doc->getAttribute('attr'));
    }

    public function testHasMoreLinesIsFalseAfterAllConsumed(): void
    {
        $r = $this->reader(":attr: x\n// comment");

        // Both lines are directives — nothing content-wise to read
        $this->assertFalse($r->hasMoreLines());
    }

    // ── YAML front matter (skip-front-matter) ─────────────────────────────────

    public function testFrontMatterIsStrippedWhenAttributeSet(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('skip-front-matter', '');
        $r = new PreprocessorReader(
            $doc,
            "---\nlayout: default\n---\n= Title\n\ncontent",
            $this->cursor(),
        );

        $this->assertSame('= Title', $r->readLine());
        $this->assertSame('', $r->readLine());
        $this->assertSame('content', $r->readLine());
    }

    public function testFrontMatterStoredInDocumentAttribute(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('skip-front-matter', '');
        new PreprocessorReader(
            $doc,
            "---\nlayout: default\ntitle: My Page\n---\ncontent",
            $this->cursor(),
        );

        $this->assertSame("layout: default\ntitle: My Page", $doc->getAttribute('front-matter'));
    }

    public function testFrontMatterKeysPromotedAsDocumentAttributes(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('skip-front-matter', '');
        new PreprocessorReader(
            $doc,
            "---\nlayout: default\nauthor: Alice\n---\ncontent",
            $this->cursor(),
        );

        $this->assertSame('default', $doc->getAttribute('layout'));
        $this->assertSame('Alice',   $doc->getAttribute('author'));
    }

    public function testFrontMatterQuotedStringsAreUnquoted(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('skip-front-matter', '');
        new PreprocessorReader(
            $doc,
            "---\ntitle: \"My Document\"\nauthor: 'Bob'\n---\ncontent",
            $this->cursor(),
        );

        $this->assertSame('My Document', $doc->getAttribute('title'));
        $this->assertSame('Bob',         $doc->getAttribute('author'));
    }

    public function testFrontMatterDoesNotOverwriteExistingDocumentAttribute(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('skip-front-matter', '');
        $doc->setAttribute('author', 'Pre-set Author');
        new PreprocessorReader(
            $doc,
            "---\nauthor: Front Matter Author\n---\ncontent",
            $this->cursor(),
        );

        $this->assertSame('Pre-set Author', $doc->getAttribute('author'));
    }

    public function testFrontMatterIgnoredWhenAttributeNotSet(): void
    {
        $doc = new DocumentStub();
        $r   = new PreprocessorReader(
            $doc,
            "---\nlayout: default\n---\n= Title",
            $this->cursor(),
        );

        // Without skip-front-matter the --- lines pass through as-is
        $this->assertSame('---', $r->readLine());
        $this->assertFalse($doc->hasAttribute('front-matter'));
    }

    public function testFrontMatterWithNoClosingDelimiterIsNotStripped(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('skip-front-matter', '');
        $r = new PreprocessorReader(
            $doc,
            "---\nlayout: default\n= Title\n\ncontent",
            $this->cursor(),
        );

        // Opening --- without closing --- must not strip anything
        $this->assertSame('---', $r->readLine());
        $this->assertFalse($doc->hasAttribute('front-matter'));
    }

    public function testFrontMatterEmptyBlockIsHandled(): void
    {
        $doc = new DocumentStub();
        $doc->setAttribute('skip-front-matter', '');
        $r = new PreprocessorReader(
            $doc,
            "---\n---\n= Title",
            $this->cursor(),
        );

        $this->assertSame('', $doc->getAttribute('front-matter'));
        $this->assertSame('= Title', $r->readLine());
    }
}
