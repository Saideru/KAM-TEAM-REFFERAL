<?php
// Tiny one-page PDF writer for receipts — no library, no extra Vercel
// function. Helvetica / Helvetica-Bold (WinAnsi), text, lines and filled
// rectangles. Coordinates are in points from the TOP-left of an A4 page.

final class MiniPdf
{
    private array $ops = [];
    public const W = 595.28;
    public const H = 841.89;

    private static function txt(string $s): string
    {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($s === false) $s = '?';
        return strtr($s, ["\\" => "\\\\", '(' => "\\(", ')' => "\\)", "\r" => '', "\n" => ' ']);
    }

    private static function rgb(array $c): string
    {
        return sprintf('%.3F %.3F %.3F', $c[0] / 255, $c[1] / 255, $c[2] / 255);
    }

    /** Approximate Helvetica width (points) — good enough for right-aligning. */
    public static function width(string $s, float $size, bool $bold = false): float
    {
        $w = 0.0;
        foreach (str_split($s) as $ch) {
            $w += match (true) {
                str_contains('il.,:;|!\'', $ch)  => 0.28,
                str_contains('fjtrI()[] -', $ch) => 0.33,
                ctype_upper($ch), str_contains('mwMW#%&@', $ch) => 0.72,
                ctype_digit($ch), $ch === '$'   => 0.556,
                default                          => 0.52,
            };
        }
        return $w * $size * ($bold ? 1.06 : 1.0);
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $color = [26, 25, 23], string $align = 'left'): void
    {
        if ($align === 'right')  $x -= self::width($s, $size, $bold);
        if ($align === 'center') $x -= self::width($s, $size, $bold) / 2;
        $this->ops[] = sprintf('BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET',
            self::rgb($color), $bold ? 'F2' : 'F1', $size, $x, self::H - $y, self::txt($s));
    }

    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->ops[] = sprintf('%s rg %.2F %.2F %.2F %.2F re f', self::rgb($fill), $x, self::H - $y - $h, $w, $h);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [216, 208, 194], float $w = 0.8): void
    {
        $this->ops[] = sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::rgb($color), $w, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function output(string $title = 'Receipt'): string
    {
        $stream = implode("\n", $this->ops);
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>', self::W, self::H),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            '<< /Title (' . self::txt($title) . ') /Producer (KAM Team) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>',
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) $pdf .= sprintf("%010d 00000 n \n", $off);
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R /Info 7 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}
