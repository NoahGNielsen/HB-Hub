<?php
// Chat formatting: turns a message's text into HTML, Discord-style. The text is stored just as it was typed,
// the formatting is only worked out here when it's shown, so editing a message gives back exactly what was typed.
// Used by chat/index.php for the messages themselves, and (as plain text) for reply quotes, the chat list
// and notifications (chat/notifications.php), so those don't show the ** and __ around words.
//
// What it understands:
//   **bold**  *italic* or _italic_  __underline__  ~~strikethrough~~  `code`
//   ```a code block```  (over several lines too, a word right after the ``` is the language and isn't shown)
//   # Heading, ## Smaller heading, ### Smallest heading  (at the start of a line, with a space after the #)
//   - a list, * a list or 1. a numbered list  (indent a line to put it in a list inside the one above)
//   [the text shown](https://the.address)  a masked link, and plain http(s):// addresses become links too
//   @Name  a mention of someone in the chat (assets/php/mentions.php), when chatFormatHtml() is told who's in it
//   \* in front of any of these characters shows it as it is instead
//
// Everything else is escaped, and links only ever go to http:// or https:// addresses.
require_once __DIR__ . '/mentions.php';
require_once __DIR__ . '/userAvatar.php'; // hbHubProfileUrl(), where a mention goes

// Every inline style, in the order they're tried where more than one could start at the same place.
// The (?!\*) and (?!_) let ***bold italic*** and ___underlined italic___ find their inner pair.
// _italic_ needs a space or punctuation on both sides, so snake_case_names stay as they are.
const HBHUB_CHAT_FORMAT_INLINE = '%
      \\\\(?<escaped>[\\\\*_~`\[\]()#>|.+@-])
    | `(?<code>[^`\n]+)`
    | \[(?<label>[^\[\]\n]+)\]\((?<href>https?://(?:[^\s<>"()]|\([^\s<>"()]*\))+)\)
    | (?<![\w/])(?<url>https?://[^\s<>"]+)
    | (?<![\p{L}\p{N}_])(?<mention>@)
    | \*\*(?<bold>.+?)\*\*(?!\*)
    | __(?<underline>.+?)__(?!_)
    | ~~(?<strike>.+?)~~
    | \*(?![\s*])(?<italic>.+?)(?<!\s)\*
    | (?<![\p{L}\p{N}_])_(?![\s_])(?<italicUnderscore>.+?)(?<!\s)_(?![\p{L}\p{N}_])
%xu';
const HBHUB_CHAT_FORMAT_LIST_DEPTH = 3; // lists inside lists, deeper indents than this stay at this depth

/**
 * A message as HTML: paragraphs (with the line breaks kept), headings, lists and code blocks, each one on its own line.
 */
function chatFormatHtml(string $content, array $mentions = []): string
{
    if (!mb_check_encoding($content, 'UTF-8')) {
        return '<p>' . nl2br(htmlspecialchars($content)) . '</p>'; // the patterns need valid UTF-8, so no formatting
    }

    // The code blocks come out first, nothing inside them is formatted.
    // Every other part (the even ones) is the text between them.
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $parts = preg_split('%```(?:[\w+#.-]*\n)?(.*?)```%s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $blocks = [];
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            $code = str_ends_with($part, "\n") ? substr($part, 0, -1) : $part;
            $blocks[] = '<pre class="chat-message-codeblock"><code>' . htmlspecialchars($code) . '</code></pre>';
            continue;
        }

        // The line break right before or after a code block just ends the line, the block is on its own line anyway
        if ($i > 0 && str_starts_with($part, "\n")) {
            $part = substr($part, 1);
        }
        if ($i < count($parts) - 1 && str_ends_with($part, "\n")) {
            $part = substr($part, 0, -1);
        }
        if ($part !== '') {
            array_push($blocks, ...chatFormatLines(explode("\n", $part)));
        }
    }

    return implode("\n", $blocks);
}

/**
 * A message as plain text, for the places that show it on one line: the formatting is gone, but the words stay.
 * A masked link shows its text, a code block its code.
 */
function chatFormatPlain(string $content): string
{
    return html_entity_decode(strip_tags(chatFormatHtml($content)), ENT_QUOTES | ENT_HTML5);
}

/**
 * Some lines of text (no code blocks in them) as blocks of HTML.
 * Lines in a row that are list items make one list, lines in a row of normal text one paragraph,
 * and every heading is a block of its own.
 *
 * @param string[] $lines
 * @return string[]
 */
function chatFormatLines(array $lines, array $mentions = []): array
{
    $groups = []; // [kind, [line or list item, ...]]
    foreach ($lines as $line) {
        if (preg_match('~^(#{1,3})[ \t]+(\S.*)$~u', $line, $match) === 1) {
            $groups[] = ['heading', [[strlen($match[1]), $match[2]]]];
            continue;
        }

        if (preg_match('~^([ \t]*)(?:[-*+]|(\d{1,9})[.)])[ \t]+(\S.*)$~u', $line, $match) === 1) {
            $item = [strlen(str_replace("\t", '    ', $match[1])), $match[2] !== '', (int) $match[2], $match[3]];
            $kind = 'list';
        } else {
            $item = $line;
            $kind = 'text';
        }

        if ($groups !== [] && $groups[array_key_last($groups)][0] === $kind) {
            $groups[array_key_last($groups)][1][] = $item;
        } else {
            $groups[] = [$kind, [$item]];
        }
    }

    $blocks = [];
    foreach ($groups as [$kind, $items]) {
        if ($kind === 'heading') {
            [$level, $text] = $items[0];
            $blocks[] = '<p class="chat-message-heading is-level-' . $level . '">' . chatFormatInline($text, true, $mentions) . '</p>';
        } elseif ($kind === 'list') {
            $blocks[] = chatFormatList($items, $mentions);
        } else {
            $blocks[] = '<p>' . implode("<br>\n", array_map(static fn (string $line): string => chatFormatInline($line, true, $mentions), $items)) . '</p>';
        }
    }
    return $blocks;
}

/**
 * List items in a row as one list. An item indented more than the one above it starts a list inside that one,
 * and going back to an earlier indent goes back to that list. Switching between - and 1. starts a new list.
 *
 * @param array<array{int, bool, int, string}> $items [indent in spaces, numbered, its number, text]
 */
function chatFormatList(array $items, array $mentions = []): string
{
    $html = '';
    $open = []; // the lists open right now, outermost first: [indent, 'ul' or 'ol']
    foreach ($items as [$indent, $isNumbered, $number, $text]) {
        $tag = $isNumbered ? 'ol' : 'ul';

        while ($open !== [] && $open[array_key_last($open)][0] > $indent) {
            $html .= "</li></" . array_pop($open)[1] . ">\n";
        }
        if (count($open) >= HBHUB_CHAT_FORMAT_LIST_DEPTH) {
            $indent = $open[array_key_last($open)][0]; // as deep as lists go, so it's one more item in the deepest
        }

        $last = $open !== [] ? $open[array_key_last($open)] : null;
        if ($last !== null && $last[0] === $indent && $last[1] === $tag) {
            $html .= "</li>\n<li>";
        } else {
            if ($last !== null && $last[0] === $indent) {
                $html .= "</li></" . array_pop($open)[1] . ">\n"; // same indent, other kind of list
            } elseif ($last !== null) {
                $html .= "\n"; // a list inside the item above, on a line of its own (for chatFormatPlain)
            }
            $open[] = [$indent, $tag];
            $start = $isNumbered && $number !== 1 ? ' start="' . $number . '"' : '';
            $html .= '<' . $tag . ' class="chat-message-list"' . $start . '><li>';
        }
        $html .= chatFormatInline($text, true, $mentions);
    }

    while ($open !== []) {
        $html .= "</li></" . array_pop($open)[1] . ">";
    }
    return $html;
}

/**
 * One line of text as HTML: bold, italic, underline, strikethrough, code, links and mentions, which can be inside each
 * other (**bold with _italic_ in it**). Inside a masked link's text, addresses aren't made links again ($allowLinks).
 * $mentions is the chat's lookup from hbHubMentionLookup() (assets/php/mentions.php), empty for no mentions.
 */
function chatFormatInline(string $text, bool $allowLinks = true, array $mentions = []): string
{
    $html = '';
    $offset = 0;
    while (preg_match(HBHUB_CHAT_FORMAT_INLINE, $text, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
        [$whole, $start] = $match[0];
        $html .= htmlspecialchars(substr($text, $offset, $start - $offset));
        $offset = $start + strlen($whole);

        if ($match['escaped'][0] !== null) {
            $html .= htmlspecialchars($match['escaped'][0]);
        } elseif ($match['code'][0] !== null) {
            $html .= '<code class="chat-message-code">' . htmlspecialchars($match['code'][0]) . '</code>';
        } elseif ($match['label'][0] !== null) {
            $html .= chatFormatMaskedLink($match['label'][0], $match['href'][0], $allowLinks, $mentions);
        } elseif ($match['url'][0] !== null) {
            // Punctuation at the end belongs to the sentence, not the address ("see https://x.dk."),
            // so it's left for the next round, where it might also close a **bold** the address is in
            $url = preg_replace('%[.,!?;:\'")\]*_~]+$%u', '', $match['url'][0]);
            $offset = $start + strlen($url);
            $html .= $allowLinks && preg_match('~^https?://[^/?#]~i', $url) === 1
                ? chatFormatLink($url, htmlspecialchars($url))
                : htmlspecialchars($url); // just "https://" with no address after it
        } elseif ($match['mention'][0] !== null) {
            $person = hbHubMentionAt($text, $offset, $mentions);
            if ($person !== null) {
                $offset += $person['length'];
                $html .= chatFormatMention($person, $allowLinks);
            } else {
                $html .= '@'; // nobody in the chat by that name
            }
        } elseif ($match['bold'][0] !== null) {
            $html .= '<strong>' . chatFormatInline($match['bold'][0], $allowLinks, $mentions) . '</strong>';
        } elseif ($match['underline'][0] !== null) {
            $html .= '<u>' . chatFormatInline($match['underline'][0], $allowLinks, $mentions) . '</u>';
        } elseif ($match['strike'][0] !== null) {
            $html .= '<s>' . chatFormatInline($match['strike'][0], $allowLinks, $mentions) . '</s>';
        } else {
            $html .= '<em>' . chatFormatInline($match['italic'][0] ?? $match['italicUnderscore'][0], $allowLinks, $mentions) . '</em>';
        }
    }

    return $html . htmlspecialchars(substr($text, $offset));
}

/**
 * [text](https://address): the text as a link. If the text looks like an address itself, the real address is shown
 * instead, so [https://school.dk](https://somewhere-else.dk) can't pretend to go somewhere it doesn't.
 * Hovering shows the address, and chat.js's link warning shows it again before it opens.
 */
function chatFormatMaskedLink(string $label, string $href, bool $allowLinks, array $mentions = []): string
{
    if (!$allowLinks || preg_match('~^https?://[^/?#]~i', $href) !== 1) {
        return htmlspecialchars('[') . chatFormatInline($label, false, $mentions) . htmlspecialchars('](' . $href . ')');
    }
    if (preg_match('~[a-z][\w+.-]*://|\bwww\.|[\p{L}\p{N}-]\.[a-z]{2,}\b~iu', $label) === 1) {
        return chatFormatLink($href, htmlspecialchars($href));
    }
    return chatFormatLink($href, chatFormatInline($label, false, $mentions));
}

/**
 * @Name in a message: the name the chat shows them by, linking to their profile (just the name inside a masked link's
 * text, as a link can't be inside a link). data-mention is who it is, for chatFormatMentionedUserIds(), and
 * data-mention-you marks the user's own, which light up more (chat.css), and so does the message they're in.
 *
 * @param array{userId: int, name: string, isYou: bool} $person from hbHubMentionAt()
 */
function chatFormatMention(array $person, bool $allowLinks): string
{
    $attributes = ' class="chat-mention' . ($person['isYou'] ? ' is-you' : '') . '" data-mention="' . $person['userId'] . '"'
        . ($person['isYou'] ? ' data-mention-you' : '');
    $name = htmlspecialchars('@' . $person['name']);
    return $allowLinks
        ? '<a href="' . htmlspecialchars(hbHubProfileUrl($person['userId'])) . '"' . $attributes . '>' . $name . '</a>'
        : '<span' . $attributes . '>' . $name . '</span>';
}

/**
 * Everyone a message mentions as it's shown (so not an @Name inside `code`), once each, leaving out the user themselves.
 * $mentions is the chat's lookup, like for chatFormatHtml().
 *
 * @return list<int> their userIds
 */
function chatFormatMentionedUserIds(string $content, array $mentions): array
{
    preg_match_all('~<(?:a|span) [^>]*data-mention="(\d+)"(?! data-mention-you)~', chatFormatHtml($content, $mentions), $matches);
    return array_values(array_unique(array_map('intval', $matches[1])));
}

/**
 * A link in a message, opening in a new tab once chat.js has warned about following links from strangers.
 * $inner is HTML already.
 */
function chatFormatLink(string $url, string $inner): string
{
    return '<a class="chat-message-link" href="' . htmlspecialchars($url) . '" title="' . htmlspecialchars($url)
        . '" target="_blank" rel="noopener noreferrer nofollow ugc" data-chat-link>' . $inner . '</a>';
}
