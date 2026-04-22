<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Reader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Reader\Cursor;
use Webware\AsciidocPhp\Reader\Reader;

#[CoversClass(Reader::class)]
final class ReaderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function cursor(): Cursor
    {
        return new Cursor(null, null, null, 1);
    }

    private function reader(string $source): Reader
    {
        return new Reader($source, $this->cursor());
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testConstructFromString(): void
    {
        $r = $this->reader("line1\nline2\nline3");
        $this->assertTrue($r->hasMoreLines());
    }

    public function testConstructFromArray(): void
    {
        $r = new Reader(['line1', 'line2'], $this->cursor());
        $this->assertTrue($r->hasMoreLines());
    }

    public function testConstructFromEmptyStringProducesNoLines(): void
    {
        $r = $this->reader('');
        $this->assertFalse($r->hasMoreLines());
    }

    public function testConstructFromEmptyArrayProducesNoLines(): void
    {
        $r = new Reader([], $this->cursor());
        $this->assertFalse($r->hasMoreLines());
    }

    public function testTrailingNewlineDoesNotProduceExtraBlankLine(): void
    {
        $r = $this->reader("line1\nline2\n");
        $this->assertSame('line1', $r->readLine());
        $this->assertSame('line2', $r->readLine());
        $this->assertNull($r->readLine());
    }

    public function testCrLfNormalised(): void
    {
        $r = $this->reader("line1\r\nline2\r\n");
        $this->assertSame('line1', $r->readLine());
        $this->assertSame('line2', $r->readLine());
        $this->assertNull($r->readLine());
    }

    // -------------------------------------------------------------------------
    // peekLine
    // -------------------------------------------------------------------------

    public function testPeekLineDoesNotConsumeTheLine(): void
    {
        $r = $this->reader("line1\nline2");
        $this->assertSame('line1', $r->peekLine());
        $this->assertSame('line1', $r->peekLine());
    }

    public function testPeekLineReturnsNullAtEof(): void
    {
        $r = $this->reader('');
        $this->assertNull($r->peekLine());
    }

    public function testPeekLineSkipBlankSkipsLeadingBlanks(): void
    {
        $r = $this->reader("\n\nfirst");
        $this->assertSame('first', $r->peekLine(skipBlank: true));
    }

    public function testPeekLineSkipBlankReturnsNullWhenOnlyBlanks(): void
    {
        $r = $this->reader("\n\n");
        $this->assertNull($r->peekLine(skipBlank: true));
    }

    // -------------------------------------------------------------------------
    // readLine
    // -------------------------------------------------------------------------

    public function testReadLineConsumesLines(): void
    {
        $r = $this->reader("line1\nline2");
        $this->assertSame('line1', $r->readLine());
        $this->assertSame('line2', $r->readLine());
        $this->assertNull($r->readLine());
    }

    public function testReadLineAdvancesCursor(): void
    {
        $r = $this->reader("a\nb\nc");
        $r->readLine();
        $this->assertSame(2, $r->getLineno());
        $r->readLine();
        $this->assertSame(3, $r->getLineno());
    }

    public function testReadLineStripsTrailingLineEnding(): void
    {
        $r = new Reader(["line1\r\n", "line2\r\n"], $this->cursor());
        $this->assertSame('line1', $r->readLine());
        $this->assertSame('line2', $r->readLine());
    }

    public function testReadLineReturnsNullAtEof(): void
    {
        $r = $this->reader('');
        $this->assertNull($r->readLine());
    }

    // -------------------------------------------------------------------------
    // readLines
    // -------------------------------------------------------------------------

    public function testReadLinesReturnsExactCount(): void
    {
        $r = $this->reader("a\nb\nc\nd");
        $this->assertSame(['a', 'b'], $r->readLines(2));
    }

    public function testReadLinesStopsAtEof(): void
    {
        $r = $this->reader("a\nb");
        $this->assertSame(['a', 'b'], $r->readLines(10));
    }

    // -------------------------------------------------------------------------
    // readLinesUntil
    // -------------------------------------------------------------------------

    public function testReadLinesUntilBreakOnBlank(): void
    {
        $r = $this->reader("a\nb\n\nc");
        $lines = $r->readLinesUntil(['break_on_blank' => true]);
        $this->assertSame(['a', 'b'], $lines);
    }

    public function testReadLinesUntilTerminator(): void
    {
        $r = $this->reader("a\nb\n----\nc");
        $lines = $r->readLinesUntil(['terminator' => '/^----$/']);
        $this->assertSame(['a', 'b'], $lines);
        // Terminator consumed — next line is 'c'
        $this->assertSame('c', $r->readLine());
    }

    public function testReadLinesUntilPreserveLastLine(): void
    {
        $r = $this->reader("a\n----\nb");
        $lines = $r->readLinesUntil([
            'terminator'        => '/^----$/',
            'preserve_last_line' => true,
        ]);
        $this->assertSame(['a'], $lines);
        // Terminator pushed back — peekLine should see it
        $this->assertSame('----', $r->peekLine());
    }

    public function testReadLinesUntilSkipLineComments(): void
    {
        $r = $this->reader("a\n// comment\nb");
        $lines = $r->readLinesUntil(['skip_line_comments' => true]);
        $this->assertSame(['a', 'b'], $lines);
    }

    public function testReadLinesUntilChompLastLine(): void
    {
        $r = $this->reader("a\nb\n\n\n----");
        $lines = $r->readLinesUntil([
            'terminator'      => '/^----$/',
            'chomp_last_line' => true,
        ]);
        $this->assertSame(['a', 'b'], $lines);
    }

    public function testReadLinesUntilEof(): void
    {
        $r = $this->reader("a\nb");
        $this->assertSame(['a', 'b'], $r->readLinesUntil());
    }

    // -------------------------------------------------------------------------
    // hasMoreLines / isNextLineEmpty
    // -------------------------------------------------------------------------

    public function testHasMoreLinesReturnsTrueWhenLinesRemain(): void
    {
        $r = $this->reader('hello');
        $this->assertTrue($r->hasMoreLines());
    }

    public function testHasMoreLinesReturnsFalseAtEof(): void
    {
        $r = $this->reader('');
        $this->assertFalse($r->hasMoreLines());
    }

    public function testIsNextLineEmptyReturnsTrueForBlank(): void
    {
        $r = $this->reader("\nsomething");
        $this->assertTrue($r->isNextLineEmpty());
    }

    public function testIsNextLineEmptyReturnsFalseForNonBlank(): void
    {
        $r = $this->reader('hello');
        $this->assertFalse($r->isNextLineEmpty());
    }

    public function testIsNextLineEmptyReturnsFalseAtEof(): void
    {
        $r = $this->reader('');
        $this->assertFalse($r->isNextLineEmpty());
    }

    // -------------------------------------------------------------------------
    // unshiftLine / unshiftLines
    // -------------------------------------------------------------------------

    public function testUnshiftLinePushesLineToFront(): void
    {
        $r = $this->reader('second');
        $r->unshiftLine('first');
        $this->assertSame('first', $r->readLine());
        $this->assertSame('second', $r->readLine());
    }

    public function testUnshiftLinesRestoresOrder(): void
    {
        $r = $this->reader('c');
        $r->unshiftLines(['a', 'b']);
        $this->assertSame('a', $r->readLine());
        $this->assertSame('b', $r->readLine());
        $this->assertSame('c', $r->readLine());
    }

    public function testUnshiftLineInvalidatesLookaheadBuffer(): void
    {
        $r = $this->reader("original");
        $r->peekLine(); // buffers 'original'
        $r->unshiftLine('inserted');
        // After unshift, next read should be 'inserted', not the buffered 'original'
        $this->assertSame('inserted', $r->readLine());
        $this->assertSame('original', $r->readLine());
    }

    // -------------------------------------------------------------------------
    // skipBlankLines
    // -------------------------------------------------------------------------

    public function testSkipBlankLinesConsumesLeadingBlanks(): void
    {
        $r = $this->reader("\n\nfirst");
        $skipped = $r->skipBlankLines();
        $this->assertSame(2, $skipped);
        $this->assertSame('first', $r->readLine());
    }

    public function testSkipBlankLinesReturnsZeroWhenNoBlanks(): void
    {
        $r = $this->reader("first\nsecond");
        $this->assertSame(0, $r->skipBlankLines());
    }

    public function testSkipBlankLinesAdvancesCursor(): void
    {
        $r = $this->reader("\n\ncontent");
        $r->skipBlankLines();
        // Cursor should have advanced past the two blank lines
        $this->assertSame(3, $r->getLineno());
    }

    // -------------------------------------------------------------------------
    // skipCommentLines
    // -------------------------------------------------------------------------

    public function testSkipCommentLinesConsumesDoubleSlashLines(): void
    {
        $r = $this->reader("// one\n// two\ncontent");
        $this->assertSame(2, $r->skipCommentLines());
        $this->assertSame('content', $r->readLine());
    }

    public function testSkipCommentLinesStopsAtNonComment(): void
    {
        $r = $this->reader("content");
        $this->assertSame(0, $r->skipCommentLines());
    }

    // -------------------------------------------------------------------------
    // advance
    // -------------------------------------------------------------------------

    public function testAdvanceReturnsTrueWhenLineConsumed(): void
    {
        $r = $this->reader('hello');
        $this->assertTrue($r->advance());
        $this->assertFalse($r->hasMoreLines());
    }

    public function testAdvanceReturnsFalseAtEof(): void
    {
        $r = $this->reader('');
        $this->assertFalse($r->advance());
    }

    // -------------------------------------------------------------------------
    // getLines / getSource / getLineno / getCursor
    // -------------------------------------------------------------------------

    public function testGetLinesReturnsRemainingInOrder(): void
    {
        $r = $this->reader("a\nb\nc");
        $r->readLine(); // consume 'a'
        $this->assertSame(['b', 'c'], $r->getLines());
    }

    public function testGetLinesDoesNotConsumeLines(): void
    {
        $r = $this->reader("a\nb");
        $r->getLines();
        $this->assertSame('a', $r->readLine());
    }

    public function testGetSourceJoinsRemainingLinesWithNewline(): void
    {
        $r = $this->reader("a\nb\nc");
        $r->readLine();
        $this->assertSame("b\nc", $r->getSource());
    }

    public function testGetLinenoStartsAtOne(): void
    {
        $r = $this->reader('hello');
        $this->assertSame(1, $r->getLineno());
    }

    public function testGetCursorReturnsCurrentCursor(): void
    {
        $r = $this->reader("a\nb");
        $r->readLine();
        $this->assertSame(2, $r->getCursor()->lineno);
    }

    // -------------------------------------------------------------------------
    // peekLines
    // -------------------------------------------------------------------------

    public function testPeekLinesReturnsLinesWithoutConsuming(): void
    {
        $r = $this->reader("a\nb\nc");
        $peeked = $r->peekLines(2);
        $this->assertSame(['a', 'b'], $peeked);
        $this->assertSame('a', $r->readLine());
    }

    public function testPeekLinesWithNormalise(): void
    {
        $r = new Reader(["  a  ", "  b  "], $this->cursor());
        $peeked = $r->peekLines(2, normalise: true);
        $this->assertSame(['  a', '  b'], $peeked);
    }
}
