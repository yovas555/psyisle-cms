<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 富文本白名单净化器（入库前使用）
 * 仅保留安全标签/属性，剥离 script/iframe(白名单外)/事件属性/危险协议
 */
class Purifier
{
    private const ALLOWED_TAGS = [
        'p','br','b','strong','i','em','u','s','del','blockquote','pre','code',
        'h1','h2','h3','h4','h5','h6','ul','ol','li','table','thead','tbody','tr','td','th',
        'img','a','span','div','figure','figcaption','hr','sup','sub','small','video','source','audio'
    ];
    private const VOID_TAGS = ['br','hr','img','source'];
    private const URL_ATTRS = ['href','src'];

    public static function clean(string $html): string
    {
        // 先去 script/style/noscript/注释
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);

        // DOM 方式净化
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        // documentElement=<html> → body → 注入的 <div>
        $body = $dom->getElementsByTagName('body')->item(0);
        $root = $body ? $body->firstChild : $dom->documentElement;

        self::walk($root, $dom);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $node) {
            $out .= $dom->saveHTML($node);
        }
        return trim($out);
    }

    private static function walk(\DOMNode $node, \DOMDocument $dom): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    $node->removeChild($child);
                    continue;
                }
                // 清理属性
                for ($j = $child->attributes->length - 1; $j >= 0; $j--) {
                    $attr = $child->attributes->item($j);
                    $name = strtolower($attr->name);
                    $val = (string)$attr->value;
                    $drop = true;
                    if ($name === 'class' || ($tag === 'img' && in_array($name, ['src','alt','width','height','style'], true))) {
                        $drop = false;
                    } elseif ($tag === 'a' && $name === 'href') {
                        $drop = false;
                        if (preg_match('#^\s*(javascript|data|vbscript)\s*:#i', $val)) { $drop = true; }
                    } elseif ($tag === 'video' && $name === 'src') { $drop = false; }
                    elseif ($tag === 'source' && $name === 'src') { $drop = false; }
                    elseif ($tag === 'td' || $tag === 'th') { if ($name === 'colspan' || $name === 'rowspan') $drop = false; }
                    if ($drop) { $child->removeAttribute($attr->name); }
                }
                // src 相对路径保留；禁止外站脚本图片风险有限，保留（图床需求）
                if ($tag === 'a') {
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                }
                self::walk($child, $dom);
            }
        }
    }
}
