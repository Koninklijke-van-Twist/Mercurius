<?php
/**
 * Dependency-free CSS inliner for HTML strings containing <style> blocks.
 * - Supports: element, .class, #id, element.class, descendant selectors, comma lists.
 * - Basic specificity and cascading. Inline style already on element is preserved and wins.
 * - Ignores @media blocks (you can choose to keep them) and most advanced CSS.
 *
 * Usage:
 *   $inlined = inline_css_from_style_tags($html, [
 *       'remove_style_tags' => true,
 *       'keep_media' => true, // if true: leaves @media blocks untouched (not inlined)
 *   ]);
 */

function inline_css_from_style_tags(string $html, array $options = []): string
{
    $options = array_merge([
        'remove_style_tags' => true,
        'keep_media' => true,
    ], $options);

    // DOMDocument needs a wrapper to reliably parse fragments with UTF-8.
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);

    // Add a wrapper element so we can return innerHTML later.
    $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="__wrap__">' . $html . '</div></body></html>';
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Collect CSS from all <style> tags.
    $styleNodes = $xpath->query('//style');
    $css = '';
    foreach ($styleNodes as $styleNode) {
        $css .= "\n" . $styleNode->textContent;
    }

    // Optionally strip @media blocks from what we try to inline (we can't evaluate media).
    $mediaBlocks = [];
    if ($options['keep_media']) {
        // Extract @media blocks so we can keep them and not inline them.
        $css = extract_media_blocks($css, $mediaBlocks);
    } else {
        // Remove @media blocks entirely
        $css = preg_replace('/@media[^{]+\{(?:[^{}]|\{[^{}]*\})*\}\s*/is', '', $css) ?? $css;
    }

    // Parse CSS into rules: selector => declarations
    $rules = parse_css_rules($css);

    // Apply rules to DOM
    $ruleIndex = 0;
    foreach ($rules as $rule) {
        $ruleIndex++;
        $selectors = $rule['selectors'];
        $decls = $rule['declarations'];

        foreach ($selectors as $sel) {
            $sel = trim($sel);
            if ($sel === '' || strpos($sel, '@') === 0)
                continue;

            $xp = css_to_xpath($sel);
            if ($xp === null)
                continue;

            $nodes = $xpath->query($xp);
            if (!$nodes)
                continue;

            $spec = selector_specificity($sel);

            foreach ($nodes as $node) {
                if (!($node instanceof DOMElement))
                    continue;

                // Build per-node "computed" map stored in a temp attribute
                $bucket = $node->getAttribute('__css_bucket__');
                $bucketArr = $bucket !== '' ? json_decode($bucket, true) : [];
                if (!is_array($bucketArr))
                    $bucketArr = [];

                foreach ($decls as $prop => $val) {
                    $prop = strtolower(trim($prop));
                    if ($prop === '')
                        continue;

                    // Store as [specificity, order, value]
                    $existing = $bucketArr[$prop] ?? null;
                    $new = [$spec, $ruleIndex, $val];

                    if ($existing === null) {
                        $bucketArr[$prop] = $new;
                    } else {
                        // Compare specificity then order
                        if (compare_specificity($new[0], $existing[0]) > 0) {
                            $bucketArr[$prop] = $new;
                        } elseif (compare_specificity($new[0], $existing[0]) === 0 && $new[1] >= $existing[1]) {
                            $bucketArr[$prop] = $new;
                        }
                    }
                }

                $node->setAttribute('__css_bucket__', json_encode($bucketArr, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    // Commit buckets into inline style, respecting existing inline style (inline wins)
    $all = $xpath->query('//*[@__css_bucket__]');
    foreach ($all as $node) {
        if (!($node instanceof DOMElement))
            continue;

        $bucketArr = json_decode($node->getAttribute('__css_bucket__'), true);
        if (!is_array($bucketArr)) {
            $node->removeAttribute('__css_bucket__');
            continue;
        }

        // Existing inline style should win; parse it first.
        $inlineExisting = parse_style_attribute($node->getAttribute('style'));
        $computed = [];

        foreach ($bucketArr as $prop => $triple) {
            $computed[$prop] = $triple[2]; // value
        }

        // Merge: computed first, then existing inline overwrites
        $merged = array_merge($computed, $inlineExisting);

        $node->setAttribute('style', style_array_to_string($merged));
        $node->removeAttribute('__css_bucket__');
    }

    // Remove <style> tags if desired
    if ($options['remove_style_tags']) {
        foreach ($styleNodes as $styleNode) {
            $styleNode->parentNode?->removeChild($styleNode);
        }
        // If we kept media blocks, we should re-insert them (optional).
        // Simplest: append them into a new <style> at the start of wrapper.
        if ($options['keep_media'] && !empty($mediaBlocks)) {
            $wrap = $xpath->query('//*[@id="__wrap__"]')->item(0);
            if ($wrap && $wrap->parentNode) {
                $style = $dom->createElement('style', "\n" . implode("\n", $mediaBlocks) . "\n");
                // Insert style just before wrap (so it stays near top of body fragment)
                $wrap->parentNode->insertBefore($style, $wrap);
            }
        }
    }

    // Return innerHTML of wrapper
    $wrap = $xpath->query('//*[@id="__wrap__"]')->item(0);
    if (!$wrap)
        return $html;

    $out = '';
    foreach ($wrap->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    // If we inserted a style node before wrap, include it too:
    if ($options['remove_style_tags'] && $options['keep_media'] && !empty($mediaBlocks)) {
        // That style is sibling before wrap, not inside wrap. So not included above.
        // You can choose whether you want it; usually for "baked" HTML you don't need it.
        // If you do want it inside the fragment, set remove_style_tags=false.
    }

    return $out;
}

/** ---------------- CSS parsing helpers ---------------- */

function extract_media_blocks(string $css, array &$mediaBlocks): string
{
    // Extract @media ... { ... } blocks (very rough, but works for typical cases)
    // We'll remove them from inlining CSS and store separately.
    $pattern = '/@media[^{]+\{(?:[^{}]|\{[^{}]*\})*\}\s*/is';
    $css2 = preg_replace_callback($pattern, function ($m) use (&$mediaBlocks) {
        $mediaBlocks[] = trim($m[0]);
        return '';
    }, $css);

    return $css2 ?? $css;
}

function parse_css_rules(string $css): array
{
    // Remove comments
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;

    // Remove @import lines
    $css = preg_replace('/@import[^;]+;\s*/i', '', $css) ?? $css;

    $rules = [];

    // Match selector { declarations }
    // This won't handle nested blocks beyond what we already removed.
    preg_match_all('/([^{}]+)\{([^}]*)\}/s', $css, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $selectorText = trim($m[1]);
        $declText = trim($m[2]);
        if ($selectorText === '' || $declText === '')
            continue;

        $selectors = array_map('trim', explode(',', $selectorText));
        $decls = parse_declarations($declText);
        if (empty($decls))
            continue;

        $rules[] = [
            'selectors' => $selectors,
            'declarations' => $decls,
        ];
    }

    return $rules;
}

function parse_declarations(string $declText): array
{
    $out = [];
    $parts = preg_split('/;(?![^(]*\))/', $declText) ?: [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '')
            continue;
        $kv = explode(':', $p, 2);
        if (count($kv) !== 2)
            continue;
        $prop = trim($kv[0]);
        $val = trim($kv[1]);
        if ($prop === '' || $val === '')
            continue;

        // Very light sanitisation: collapse whitespace
        $val = preg_replace('/\s+/', ' ', $val) ?? $val;

        $out[$prop] = $val;
    }
    return $out;
}

/** --------------- Selector -> XPath (basic) --------------- */

function css_to_xpath(string $selector): ?string
{
    // Drop pseudo-classes/elements we can't map reliably (e.g. :hover, ::before)
    $selector = preg_replace('/::?[a-zA-Z0-9\-\_]+(\([^\)]*\))?/', '', $selector) ?? $selector;
    $selector = trim($selector);
    if ($selector === '')
        return null;

    // Split by whitespace for descendant combinator
    $parts = preg_split('/\s+/', $selector) ?: [];
    $xpathParts = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '')
            continue;

        // Handle element#id.class1.class2 and just .class / #id
        $tag = '*';
        $conds = [];

        // Extract tag at start if present (letters or *)
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*|\*)/', $part, $m)) {
            $tag = $m[1];
            $part = substr($part, strlen($m[1]));
        }

        // Find #id
        if (preg_match('/#([a-zA-Z0-9\-_]+)/', $part, $m)) {
            $id = $m[1];
            $conds[] = "@id=" . xpath_literal($id);
            $part = preg_replace('/#([a-zA-Z0-9\-_]+)/', '', $part) ?? $part;
        }

        // Find .classes
        if (preg_match_all('/\.([a-zA-Z0-9\-_]+)/', $part, $m)) {
            foreach ($m[1] as $cls) {
                // class contains token
                $conds[] = "contains(concat(' ', normalize-space(@class), ' '), " . xpath_literal(' ' . $cls . ' ') . ")";
            }
        }

        $xp = $tag;
        if (!empty($conds)) {
            $xp .= '[' . implode(' and ', $conds) . ']';
        }
        $xpathParts[] = $xp;
    }

    if (empty($xpathParts))
        return null;

    // Build descendant chain: //a//b//c
    return '//' . implode('//', $xpathParts);
}

function xpath_literal(string $s): string
{
    // Safely quote a string for XPath
    if (strpos($s, "'") === false) {
        return "'" . $s . "'";
    }
    if (strpos($s, '"') === false) {
        return '"' . $s . '"';
    }
    // Contains both quote types: concat('a',"'",'b')
    $parts = explode("'", $s);
    $out = "concat(";
    for ($i = 0; $i < count($parts); $i++) {
        if ($i > 0)
            $out .= ",\"'\",";
        $out .= "'" . $parts[$i] . "'";
        if ($i < count($parts) - 1)
            $out .= ",";
    }
    $out .= ")";
    return $out;
}

/** --------------- Specificity --------------- */

function selector_specificity(string $sel): array
{
    // Very rough CSS specificity:
    // (a, b, c) where a=id count, b=class/attr/pseudo count, c=element count
    // We stripped pseudo already in css_to_xpath but still count classes/ids here.
    $a = preg_match_all('/#[a-zA-Z0-9\-_]+/', $sel) ?: 0;
    $b = preg_match_all('/\.[a-zA-Z0-9\-_]+/', $sel) ?: 0;
    // element names (exclude * and empty)
    $c = preg_match_all('/(^|[\s>+~])([a-zA-Z][a-zA-Z0-9_-]*)/', $sel) ?: 0;
    return [$a, $b, $c];
}

function compare_specificity(array $s1, array $s2): int
{
    for ($i = 0; $i < 3; $i++) {
        if ($s1[$i] > $s2[$i])
            return 1;
        if ($s1[$i] < $s2[$i])
            return -1;
    }
    return 0;
}

/** --------------- Style attribute helpers --------------- */

function parse_style_attribute(string $style): array
{
    $out = [];
    $style = trim($style);
    if ($style === '')
        return $out;

    $parts = preg_split('/;(?![^(]*\))/', $style) ?: [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '')
            continue;
        $kv = explode(':', $p, 2);
        if (count($kv) !== 2)
            continue;
        $prop = strtolower(trim($kv[0]));
        $val = trim($kv[1]);
        if ($prop === '' || $val === '')
            continue;
        $out[$prop] = $val;
    }
    return $out;
}

function style_array_to_string(array $styles): string
{
    // Keep stable order for readability
    ksort($styles);
    $chunks = [];
    foreach ($styles as $prop => $val) {
        $prop = strtolower(trim($prop));
        $val = trim($val);
        if ($prop === '' || $val === '')
            continue;
        $chunks[] = $prop . ': ' . $val;
    }
    return implode('; ', $chunks) . (empty($chunks) ? '' : ';');
}

/** ---------------- Example ---------------- */

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--demo') {
    $html = <<<HTML
<style>
  .card { border: 1px solid #ccc; padding: 10px; }
  #title { font-weight: bold; }
  div.card .label { color: red; }
</style>

<div class="card">
  <div id="title">Hallo</div>
  <span class="label" style="color: blue;">Dit blijft blauw (inline wint)</span>
</div>
HTML;

    echo inline_css_from_style_tags($html, ['remove_style_tags' => true, 'keep_media' => true]);
}
