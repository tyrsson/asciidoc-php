<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\NullConverter;
use Webware\AsciidocPhp\SafeMode;

#[CoversClass(Document::class)]
final class DocumentTest extends TestCase
{
    // ── Construction ─────────────────────────────────────────────────────────

    public function testDefaultConstruction(): void
    {
        $doc = new Document();
        self::assertSame('html5', $doc->getBackend());
        self::assertSame('article', $doc->getDoctype());
        self::assertSame(SafeMode::SAFE, $doc->getSafeMode());
        self::assertNull($doc->getBaseDir());
    }

    public function testOptionsSafe(): void
    {
        $doc = new Document(['safe' => SafeMode::SECURE]);
        self::assertSame(SafeMode::SECURE, $doc->getSafeMode());
    }

    public function testOptionsBackend(): void
    {
        $doc = new Document(['backend' => 'docbook5']);
        self::assertSame('docbook5', $doc->getBackend());
    }

    public function testOptionsDoctype(): void
    {
        $doc = new Document(['doctype' => 'book']);
        self::assertSame('book', $doc->getDoctype());
    }

    public function testOptionsBaseDir(): void
    {
        $doc = new Document(['base_dir' => '/tmp']);
        self::assertSame('/tmp', $doc->getBaseDir());
    }

    public function testOptionsConverter(): void
    {
        $conv = new NullConverter();
        $doc  = new Document(['converter' => $conv]);
        self::assertSame($conv, $doc->getConverter());
    }

    public function testOptionsAttributes(): void
    {
        $doc = new Document(['attributes' => ['toc' => '']]);
        self::assertTrue($doc->hasAttribute('toc'));
    }

    // ── Builtin attributes ────────────────────────────────────────────────────

    public function testBuiltinAttributesPresent(): void
    {
        $doc = new Document();
        self::assertSame('html5', $doc->getAttribute('backend'));
        self::assertSame('article', $doc->getAttribute('doctype'));
        self::assertSame('UTF-8', $doc->getAttribute('encoding'));
        self::assertSame('.html', $doc->getAttribute('outfilesuffix'));
        // Date-related keys exist
        self::assertNotNull($doc->getAttribute('docdate'));
        self::assertNotNull($doc->getAttribute('localdate'));
    }

    // ── setAttribute ─────────────────────────────────────────────────────────

    public function testSetAttributeOverridable(): void
    {
        $doc = new Document();
        $doc->setAttribute('foo', 'bar');
        self::assertTrue($doc->hasAttribute('foo'));
        self::assertSame('bar', $doc->getAttribute('foo'));
    }

    public function testSetAttributeLockedCannotBeOverridden(): void
    {
        $doc = new Document();
        $doc->setAttribute('foo', 'original', false);  // lock it
        $doc->setAttribute('foo', 'new');               // attempt override — must be ignored
        self::assertSame('original', $doc->getAttribute('foo'));
    }

    public function testSetAttributeLockedCannotBeUnset(): void
    {
        $doc = new Document();
        $doc->setAttribute('foo', 'locked', false);
        $doc->unsetAttribute('foo');                    // must be ignored
        self::assertTrue($doc->hasAttribute('foo'));
    }

    // ── unsetAttribute ────────────────────────────────────────────────────────

    public function testUnsetAttribute(): void
    {
        $doc = new Document();
        $doc->setAttribute('foo', 'bar');
        $doc->unsetAttribute('foo');
        self::assertFalse($doc->hasAttribute('foo'));
        // getAttribute returns default after unset
        self::assertSame('default', $doc->getAttribute('foo', 'default'));
    }

    // ── hasAttribute ─────────────────────────────────────────────────────────

    public function testHasAttributeReturnsFalseForMissing(): void
    {
        $doc = new Document();
        self::assertFalse($doc->hasAttribute('no-such-key'));
    }

    // ── getAttribute ─────────────────────────────────────────────────────────

    public function testGetAttributeReturnsDefault(): void
    {
        $doc = new Document();
        self::assertNull($doc->getAttribute('missing'));
        self::assertSame(42, $doc->getAttribute('missing', 42));
    }

    // ── register / resolveId ─────────────────────────────────────────────────

    public function testRegisterAndResolveId(): void
    {
        $doc   = new Document();
        $block = new \Webware\AsciidocPhp\Node\Block($doc, null, 'paragraph');
        $block->setAttribute('id', 'intro');
        $doc->register('ids', $block);
        self::assertSame($block, $doc->resolveId('intro'));
    }

    public function testResolveIdReturnsNullForUnknown(): void
    {
        $doc = new Document();
        self::assertNull($doc->resolveId('no-such'));
    }

    public function testRegisterNodeWithoutIdIsIgnored(): void
    {
        $doc   = new Document();
        $block = new \Webware\AsciidocPhp\Node\Block($doc, null, 'paragraph');
        // No 'id' attribute set — register should silently skip
        $doc->register('ids', $block);
        self::assertNull($doc->resolveId(''));
    }

    // ── Metadata helpers ─────────────────────────────────────────────────────

    public function testGetDocTitleNullWhenNotSet(): void
    {
        $doc = new Document();
        self::assertNull($doc->getDocTitle());
    }

    public function testGetDocTitleWhenSet(): void
    {
        $doc = new Document(['attributes' => ['doctitle' => 'My Doc']]);
        self::assertSame('My Doc', $doc->getDocTitle());
    }

    public function testGetAuthorNullWhenNotSet(): void
    {
        $doc = new Document();
        self::assertNull($doc->getAuthor());
    }

    // ── Node identity ─────────────────────────────────────────────────────────

    public function testDocumentNodeName(): void
    {
        $doc = new Document();
        self::assertSame('document', $doc->getNodeName());
        self::assertSame('document', $doc->getContext());
    }

    public function testDocumentIsOwnDocumentReference(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->getDocument());
    }

    public function testDocumentParentIsNull(): void
    {
        $doc = new Document();
        self::assertNull($doc->getParent());
    }

    // ── Sourcemap ─────────────────────────────────────────────────────────────

    public function testSourcemapDisabledByDefault(): void
    {
        $doc = new Document();
        self::assertFalse($doc->isSourcemapEnabled());
    }

    public function testSourcemapEnabled(): void
    {
        $doc = new Document(['sourcemap' => true]);
        self::assertTrue($doc->isSourcemapEnabled());
    }

    // ── convert() ────────────────────────────────────────────────────────────

    public function testConvertDelegatesToConverter(): void
    {
        $doc = new Document(); // uses NullConverter
        self::assertSame('', $doc->convert());
    }
}
