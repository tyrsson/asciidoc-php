<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Substitutor;

/**
 * All compiled regular expressions used throughout the substitution pipeline
 * and parser, collected here to avoid magic strings scattered across the codebase.
 *
 * Every constant is a PCRE pattern string (without delimiters) unless noted.
 * Patterns that are used as full preg_match/preg_replace arguments include
 * their delimiters and flags.
 */
final class Rx
{
    /** Not instantiable — constants only. */
    private function __construct() {}

    // ── Document header ───────────────────────────────────────────────────────

    /** Document title line:  = Title */
    public const string DOCUMENT_TITLE = '/^= +(.+)$/';

    /** Author info line (optional, after title):  Firstname [Middle] Lastname [<email>] */
    public const string AUTHOR_INFO = '/^([^<\n]+?)(?:\s+<([^>\n]+)>)?\s*$/';

    /** Revision info line:  [vNUMBER[,] [DATE[: REMARK]]] */
    public const string REVISION_INFO = '/^(?:v?(\S+?),?\s*)?(?:(\d{4}-\d{2}-\d{2}|\w[^:]*?)\s*)?(?::\s*(.+))?$/';

    // ── Section titles ────────────────────────────────────────────────────────

    /** ATX-style section title:  == Title */
    public const string SECTION_TITLE = '/^(={1,6})\s+(\S.*?)(?:\s+\[\[\S+\]\])?\s*$/';

    /** Setext-style section title underline (= or -) */
    public const string SETEXT_UNDERLINE = '/^[=\-]{2,}\s*$/';

    // ── Block delimiters ──────────────────────────────────────────────────────

    public const string BLOCK_DELIMITER       = '/^(-{4,}|\.{4,}|={4,}|\*{4,}|_{4,}|\+{4,}|\/{4,}|-{2})\s*$/';
    public const string TABLE_DELIMITER       = '/^\|={3,}\s*$/';
    public const string LISTING_DELIMITER     = '/^-{4,}\s*$/';
    public const string LITERAL_DELIMITER     = '/^\.{4,}\s*$/';
    public const string EXAMPLE_DELIMITER     = '/^={4,}\s*$/';
    public const string SIDEBAR_DELIMITER     = '/^\*{4,}\s*$/';
    public const string QUOTE_DELIMITER       = '/^_{4,}\s*$/';
    public const string PASS_DELIMITER        = '/^\+{4,}\s*$/';
    public const string COMMENT_DELIMITER     = '/^\/{4,}\s*$/';
    public const string OPEN_BLOCK_DELIMITER  = '/^--\s*$/';

    // ── Block macros ──────────────────────────────────────────────────────────

    /** image::, video::, audio::, toc:: block macros */
    public const string BLOCK_MACRO = '/^(\w[\w\-]*)::(\S*?)\[(.*?)\]$/';

    // ── List markers ─────────────────────────────────────────────────────────

    public const string UNORDERED_LIST   = '/^(\s*)([-*]{1,5})\s+(\S[\s\S]*)$/';
    public const string ORDERED_LIST     = '/^(\s*)(\.{1,5}|\d+\.)\s+(\S[\s\S]*)$/';
    public const string DESCRIPTION_LIST = '/^(\s*)(.*?)(:{2,4}|;;)\s*$/';
    public const string CALLOUT_LIST     = '/^(<\d+>)\s+(\S[\s\S]*)$/';

    // ── Block metadata ────────────────────────────────────────────────────────

    /** Inline anchor:  [[id]] or [[id,reftext]] */
    public const string ANCHOR       = '/^\[\[(\S+?)(?:,\s*(.+?))?\]\]\s*$/';

    /** Block attribute list:  [attrlist] */
    public const string ATTR_LIST    = '/^\[([^\[\]]*)\]\s*$/';

    /** Block title:  .Title text */
    public const string BLOCK_TITLE  = '/^\.([^\s.].*)$/';

    // ── Thematic / page breaks ────────────────────────────────────────────────

    public const string THEMATIC_BREAK = "/^'''\s*$/";
    public const string PAGE_BREAK     = '/^<<<\s*$/';

    // ── Substitution pipeline ─────────────────────────────────────────────────

    /** Attribute reference: {name} */
    public const string ATTR_REF = '/\{([a-zA-Z0-9_][a-zA-Z0-9_-]*)\}/';

    /** Escaped attribute reference: \{name} */
    public const string ESCAPED_ATTR_REF = '/\\\\\{([a-zA-Z0-9_][a-zA-Z0-9_-]*)\}/';

    /** Inline passthrough markers */
    public const string PASSTHROUGH_TRIPLE = '/\+{3}(.*?)\+{3}/s';
    public const string PASSTHROUGH_DOUBLE = '/\${2}(.*?)\${2}/s';
    public const string PASSTHROUGH_SINGLE = '/(?<![a-zA-Z0-9])\+([^\s+][^+]*)\+(?![a-zA-Z0-9])/';

    /** Named pass macro:  pass:[content] or pass:subs[content] */
    public const string PASS_MACRO = '/\bpass:([a-z,]*)\[([^\]]*)\]/';

    /** Callout marker in verbatim blocks:  <1>, <2>, … */
    public const string CALLOUT_MARKER = '/<(\d+)>/';

    // ── Inline quotes ─────────────────────────────────────────────────────────

    /** Unconstrained (double-marker) quote patterns */
    public const string STRONG_UNCONSTRAINED    = '/\*{2}([\s\S]+?)\*{2}/';
    public const string EMPHASIS_UNCONSTRAINED  = '/_{2}([\s\S]+?)_{2}/';
    public const string MONO_UNCONSTRAINED      = '/`{2}([\s\S]+?)`{2}/';
    public const string MARK_UNCONSTRAINED      = '/#{2}([\s\S]+?)#{2}/';

    /** Constrained (single-marker, word-boundary) quote patterns */
    public const string STRONG_CONSTRAINED      = '/(?<![a-zA-Z0-9\*])\*([^\s*][^*]*[^\s*]|\S)\*(?![a-zA-Z0-9\*])/';
    public const string EMPHASIS_CONSTRAINED    = '/(?<![a-zA-Z0-9_])_([^\s_][^_]*[^\s_]|\S)_(?![a-zA-Z0-9_])/';
    public const string MONO_CONSTRAINED        = '/(?<![a-zA-Z0-9`])`([^\s`][^`]*[^\s`]|\S)`(?![a-zA-Z0-9`])/';
    public const string MARK_CONSTRAINED        = '/(?<![a-zA-Z0-9#])#([^\s#][^#]*[^\s#]|\S)#(?![a-zA-Z0-9#])/';

    /** Superscript and subscript */
    public const string SUPERSCRIPT = '/\^([^\s^]+)\^/';
    public const string SUBSCRIPT   = '/~([^\s~]+)~/';

    /** Typographic (curved) quotes */
    public const string DOUBLE_CURVED_QUOTE = '/"`([\s\S]+?)`"/';
    public const string SINGLE_CURVED_QUOTE = "/\\'`([\\s\\S]+?)`\\'/";

    // ── Replacements ─────────────────────────────────────────────────────────

    public const string REPLACEMENT_COPYRIGHT  = '/\(C\)/';
    public const string REPLACEMENT_REGISTERED = '/\(R\)/';
    public const string REPLACEMENT_TRADEMARK  = '/\(TM\)/';
    public const string REPLACEMENT_EM_DASH    = '/(\w)--(\w)/';
    public const string REPLACEMENT_ELLIPSIS   = '/\.\.\./';
    public const string REPLACEMENT_ARROW_R    = '/->/';
    public const string REPLACEMENT_ARROW_L    = '/<-/';
    public const string REPLACEMENT_ARROW_RD   = '/=>/';
    public const string REPLACEMENT_ARROW_LD   = '/<=/';

    // ── Inline macros ─────────────────────────────────────────────────────────

    public const string LINK_MACRO         = '/\blink:(\S+?)\[([^\]]*)\]/';
    public const string URL_MACRO          = '/\b(https?|ftp|file):\/\/\S+?(?=\[|[.,;:?!]?\s|$)/';
    public const string XREF_SHORTHAND     = '/<<(\S+?)(?:,\s*([^\]]+?))?>>/';
    public const string XREF_MACRO         = '/\bxref:(\S+?)\[([^\]]*)\]/';
    public const string IMAGE_MACRO        = '/\bimage:(\S+?)\[([^\]]*)\]/';
    public const string ANCHOR_MACRO       = '/\banchor:(\S+?)\[([^\]]*)\]/';
    public const string FOOTNOTE_MACRO     = '/\bfootnote(?:ref)?:\[([^\]]*)\]/';
    public const string KBD_MACRO          = '/\bkbd:\[([^\]]+)\]/';
    public const string MENU_MACRO         = '/\bmenu:(\S+?)\[([^\]]*)\]/';
    public const string BTN_MACRO          = '/\bbtn:\[([^\]]+)\]/';

    // ── Explicit line break ───────────────────────────────────────────────────

    public const string EXPLICIT_LINE_BREAK = '/ \+$/m';

    // ── Attribute entry (duplicated from PreprocessorReader for parser use) ──

    public const string ATTRIBUTE_ENTRY = '/^:(!?)([a-zA-Z0-9_][a-zA-Z0-9_-]*)(!?):(?:[ \t]+(.*?))?[ \t]*$/';
}
