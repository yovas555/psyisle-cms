<?php
declare(strict_types=1);

namespace app\Core;

class Str
{
    /** slug 化：中文转拼音不可用时用 pinyin-free 方案（保留字母数字，中文转 a{n}） */
    public static function slugify(string $s, int $maxLen = 60): string
    {
        $s = strtolower(trim($s));
        // 保留英文数字
        preg_match_all('/[a-z0-9]+/u', $s, $m);
        $latin = implode('-', $m[0] ?? []);
        if (mb_strlen($latin) >= 3) {
            $s = $latin;
        } else {
            // 中文标题：用日期+短码（可读性优于 hex，SEO 主要由标题承担）
            $set = 'abcdefghjkmnpqrstuvwxyz23456789';
            $rand = '';
            for ($i = 0; $i < 5; $i++) { $rand .= $set[random_int(0, strlen($set) - 1)]; }
            $s = date('ymd') . '-' . $rand;
        }
        $s = preg_replace('/[^a-z0-9\-]/', '-', $s) ?: '';
        $s = trim(preg_replace('/\-+/', '-', $s) ?? '', '-');
        if ($s === '') $s = 'post-' . date('YmdHis');
        return substr($s, 0, $maxLen);
    }
}
