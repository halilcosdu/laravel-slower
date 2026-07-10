<?php

use HalilCosdu\Slower\Support\MarkdownRenderer;

describe('MarkdownRenderer', function () {
    it('renders headings mapped below the page heading levels', function () {
        expect(MarkdownRenderer::render('# Indexing'))->toBe('<h3>Indexing</h3>');
        expect(MarkdownRenderer::render('## Indexing'))->toBe('<h4>Indexing</h4>');
        expect(MarkdownRenderer::render('### Indexing'))->toBe('<h5>Indexing</h5>');
        expect(MarkdownRenderer::render('###### Indexing'))->toBe('<h6>Indexing</h6>');
    });

    it('renders paragraphs and joins wrapped lines', function () {
        expect(MarkdownRenderer::render("First line\nsecond line.\n\nNew paragraph."))
            ->toBe('<p>First line second line.</p>'."\n".'<p>New paragraph.</p>');
    });

    it('renders bold, italic and inline code', function () {
        expect(MarkdownRenderer::render('Add a **composite** index on *both* columns using `CREATE INDEX`.'))
            ->toBe('<p>Add a <strong>composite</strong> index on <em>both</em> columns using <code>CREATE INDEX</code>.</p>');
    });

    it('does not apply emphasis inside inline code', function () {
        expect(MarkdownRenderer::render('Run `a ** b ** c` now.'))
            ->toBe('<p>Run <code>a ** b ** c</code> now.</p>');
    });

    it('renders fenced code blocks verbatim without inline transforms', function () {
        $markdown = "```sql\nSELECT * FROM users WHERE name = '**bob**';\n```";

        expect(MarkdownRenderer::render($markdown))
            ->toBe('<pre><code>SELECT * FROM users WHERE name = &#039;**bob**&#039;;</code></pre>');
    });

    it('closes an unterminated fenced code block at the end of input', function () {
        expect(MarkdownRenderer::render("```\nSELECT 1;"))
            ->toBe('<pre><code>SELECT 1;</code></pre>');
    });

    it('renders unordered lists from dash and asterisk markers', function () {
        expect(MarkdownRenderer::render("- first\n- second"))
            ->toBe('<ul><li>first</li><li>second</li></ul>');
        expect(MarkdownRenderer::render("* first\n* second"))
            ->toBe('<ul><li>first</li><li>second</li></ul>');
    });

    it('renders ordered lists', function () {
        expect(MarkdownRenderer::render("1. first\n2. second"))
            ->toBe('<ol><li>first</li><li>second</li></ol>');
    });

    it('escapes html everywhere — no raw passthrough', function () {
        expect(MarkdownRenderer::render('<script>alert(1)</script>'))
            ->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');

        expect(MarkdownRenderer::render('# <img src=x onerror=alert(1)>'))
            ->toBe('<h3>&lt;img src=x onerror=alert(1)&gt;</h3>');

        expect(MarkdownRenderer::render("- <b>bold</b>\n- `<i>code</i>`"))
            ->toBe('<ul><li>&lt;b&gt;bold&lt;/b&gt;</li><li><code>&lt;i&gt;code&lt;/i&gt;</code></li></ul>');

        expect(MarkdownRenderer::render("```\n</code></pre><script>alert(1)</script>\n```"))
            ->toBe('<pre><code>&lt;/code&gt;&lt;/pre&gt;&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>');
    });

    it('renders a full mixed document', function () {
        $markdown = <<<'MD'
## Recommendations

To improve performance:

1. Add a **composite** index:

```sql
CREATE INDEX idx_products ON products (price, discount_total);
```

- Avoid `SELECT *`
- Compare *numeric* columns without quotes
MD;

        $html = MarkdownRenderer::render($markdown);

        expect($html)
            ->toContain('<h4>Recommendations</h4>')
            ->toContain('<p>To improve performance:</p>')
            ->toContain('<ol><li>Add a <strong>composite</strong> index:</li></ol>')
            ->toContain('<pre><code>CREATE INDEX idx_products ON products (price, discount_total);</code></pre>')
            ->toContain('<li>Avoid <code>SELECT *</code></li>')
            ->toContain('<li>Compare <em>numeric</em> columns without quotes</li>');
    });

    it('is immune to literal placeholder control characters in the input', function () {
        expect(MarkdownRenderer::render("\x1A0\x1A and `code`"))
            ->toBe('<p>0 and <code>code</code></p>');
    });

    it('returns an empty string for blank input', function () {
        expect(MarkdownRenderer::render("  \n\n  "))->toBe('');
    });
});
