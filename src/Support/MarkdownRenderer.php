<?php

namespace HalilCosdu\Slower\Support;

/**
 * Minimal, escape-first markdown renderer for AI recommendations.
 *
 * Every byte of input is HTML-escaped before any transform runs, and no raw
 * HTML ever passes through, so the produced fragment is safe to print
 * unescaped. Supported constructs are limited to what AI recommendations
 * actually use: headings, paragraphs, bold/italic, inline code, fenced code
 * blocks and flat lists.
 */
class MarkdownRenderer
{
    /** @var list<string> */
    private array $html = [];

    /** @var list<string> */
    private array $paragraph = [];

    private ?string $list = null;

    public static function render(string $markdown): string
    {
        return (new self)->convert($markdown);
    }

    private function convert(string $markdown): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));

        $inCodeBlock = false;
        $codeLines = [];

        foreach ($lines as $line) {
            if ($inCodeBlock) {
                if (str_starts_with(trim($line), '```')) {
                    $this->html[] = '<pre><code>'.e(implode("\n", $codeLines)).'</code></pre>';
                    $inCodeBlock = false;
                } else {
                    $codeLines[] = $line;
                }

                continue;
            }

            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                $this->closeParagraph();
                $this->closeList();
                $inCodeBlock = true;
                $codeLines = [];

                continue;
            }

            if ($trimmed === '') {
                $this->closeParagraph();
                $this->closeList();

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->closeParagraph();
                $this->closeList();
                $level = min(6, strlen($matches[1]) + 2);
                $this->html[] = "<h{$level}>".$this->inline($matches[2])."</h{$level}>";

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->closeParagraph();
                $this->openList('ul');
                $this->html[] = '<li>'.$this->inline($matches[1]).'</li>';

                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->closeParagraph();
                $this->openList('ol');
                $this->html[] = '<li>'.$this->inline($matches[1]).'</li>';

                continue;
            }

            $this->paragraph[] = $trimmed;
        }

        if ($inCodeBlock && $codeLines !== []) {
            $this->html[] = '<pre><code>'.e(implode("\n", $codeLines)).'</code></pre>';
        }
        $this->closeParagraph();
        $this->closeList();

        return $this->joinBlocks();
    }

    private function closeParagraph(): void
    {
        if ($this->paragraph !== []) {
            $this->html[] = '<p>'.$this->inline(implode(' ', $this->paragraph)).'</p>';
            $this->paragraph = [];
        }
    }

    private function closeList(): void
    {
        if ($this->list !== null) {
            $this->html[] = "</{$this->list}>";
            $this->list = null;
        }
    }

    private function openList(string $tag): void
    {
        if ($this->list !== $tag) {
            $this->closeList();
            $this->html[] = "<{$tag}>";
            $this->list = $tag;
        }
    }

    private function inline(string $text): string
    {
        // Strip the SUB sentinel we use as a placeholder delimiter below so a
        // crafted string cannot collide with the code-span shielding.
        $escaped = e(str_replace("\x1A", '', $text));

        // Shield inline code spans so emphasis transforms cannot reach into them.
        $codeSpans = [];
        $escaped = (string) preg_replace_callback('/`([^`]+)`/', function (array $matches) use (&$codeSpans) {
            $codeSpans[] = '<code>'.$matches[1].'</code>';

            return "\x1A".(count($codeSpans) - 1)."\x1A";
        }, $escaped);

        $escaped = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/(?<![\w*])\*([^*]+)\*(?![\w*])/', '<em>$1</em>', $escaped);

        return (string) preg_replace_callback("/\x1A(\d+)\x1A/", fn (array $matches) => $codeSpans[(int) $matches[1]], $escaped);
    }

    private function joinBlocks(): string
    {
        $joined = '';

        foreach ($this->html as $block) {
            if ($joined === '') {
                $joined = $block;

                continue;
            }

            // List items and closing tags attach directly to their list; every
            // other block starts on its own line.
            $joined .= str_starts_with($block, '<li>') || str_starts_with($block, '</')
                ? $block
                : "\n".$block;
        }

        return $joined;
    }
}
