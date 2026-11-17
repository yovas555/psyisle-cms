<?php
declare(strict_types=1);

namespace app\Core;

/** GD 图形验证码 */
class Captcha
{
    public static function make(): void
    {
        Session::start();
        $code = '';
        $set = 'ACDEFGHJKLMNPQRTUVWXY3479';
        for ($i = 0; $i < 4; $i++) { $code .= $set[random_int(0, strlen($set) - 1)]; }
        $_SESSION['_captcha'] = strtolower($code);

        $w = 132; $h = 44;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, 243, 246, 251);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $c = imagecolorallocate($img, random_int(180, 220), random_int(180, 220), random_int(200, 230));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        $colors = [
            imagecolorallocate($img, 41, 84, 158),
            imagecolorallocate($img, 26, 128, 116),
            imagecolorallocate($img, 158, 68, 41),
        ];
        for ($i = 0; $i < 4; $i++) {
            $size = random_int(16, 20);
            $x = 12 + $i * 28 + random_int(-2, 2);
            $y = 30 + random_int(-3, 3);
            imagestring($img, 5, (int)$x, (int)($h / 2 - 8), strtoupper($code[$i]), $colors[$i % 3]);
        }
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    public static function check(?string $input): bool
    {
        Session::start();
        $expect = (string)($_SESSION['_captcha'] ?? '');
        unset($_SESSION['_captcha']);
        return $input !== null && $expect !== '' && strtolower(trim($input)) === $expect;
    }
}
