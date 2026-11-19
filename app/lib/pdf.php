<?php
declare(strict_types=1);

/**
 * Dependency-free PDF writer for reports (no Composer, works offline on XAMPP).
 * Uses the PDF standard fonts Helvetica / Helvetica-Bold with WinAnsi encoding,
 * so the file opens in every PDF reader. Layout: sky-blue title band, period and
 * filter lines, summary tiles, description, then each table with a repeating header
 * row on every page and "Page X of Y" footers.
 *
 * The desktop system (desktop/.../Core/PdfReport.vb) uses the same layout.
 */
final class PdfReport
{
    private const W_REG = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 761, 556, 0, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0, 0, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 0, 500, 667, 278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611, 667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278, 556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500];
    private const W_BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 761, 556, 0, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0, 0, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 0, 500, 667, 278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611, 722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278, 611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556];
    /** Unicode code point -> Windows-1252 byte for the 0x80-0x9F range. */
    private const SPECIAL = [0x20AC => 128, 0x201A => 130, 0x0192 => 131, 0x201E => 132, 0x2026 => 133, 0x2020 => 134, 0x2021 => 135, 0x02C6 => 136, 0x2030 => 137,
        0x0160 => 138, 0x2039 => 139, 0x0152 => 140, 0x017D => 142, 0x2018 => 145, 0x2019 => 146, 0x201C => 147, 0x201D => 148, 0x2022 => 149,
        0x2013 => 150, 0x2014 => 151, 0x02DC => 152, 0x2122 => 153, 0x0161 => 154, 0x203A => 155, 0x0153 => 156, 0x017E => 158, 0x0178 => 159];
    private const NUMERIC = '/^(\x{20B1}|PHP ?)?-?[0-9][0-9,]*(\.[0-9]+)?%?$/u';

    private bool $landscape;
    private float $pw;
    private float $ph;
    private float $m = 36.0;
    /** @var array<int, string[]> */
    private array $pages = [];
    private int $cur = -1;
    private float $y = 0.0;

    /**
     * @param string[] $subs        period / generated-by / filter lines
     * @param array    $summary     [[label, value], ...]
     * @param array    $tables      [[title, columns[], rows[][]], ...]
     */
    public function __construct(private string $title, private array $subs, private array $summary, private string $desc, private array $tables, private string $org = "Agie's Resort")
    {
        $maxCols = 1;
        foreach ($tables as $t) {
            $maxCols = max($maxCols, count($t[1]));
        }
        $this->landscape = $maxCols > 6;
        [$this->pw, $this->ph] = $this->landscape ? [842.0, 595.0] : [595.0, 842.0];
    }

    // ------------------------------------------------------------ text helpers
    /** @return int[] Windows-1252 byte values */
    private static function ansi(string $s): array
    {
        $s = str_replace(["\u{20B1}", "\r", "\n", "\t"], ['PHP ', ' ', ' ', ' '], $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $out = [];
        foreach (mb_str_split($s) as $ch) {
            $c = mb_ord($ch, 'UTF-8');
            if ($c === false) {
                $out[] = 63;
            } elseif (($c >= 32 && $c <= 126) || ($c >= 160 && $c <= 255)) {
                $out[] = $c;
            } elseif (isset(self::SPECIAL[$c])) {
                $out[] = self::SPECIAL[$c];
            } else {
                $out[] = 63;
            }
        }
        return $out;
    }

    private static function width(array $codes, bool $bold, float $size): float
    {
        $t = $bold ? self::W_BOLD : self::W_REG;
        $w = 0;
        foreach ($codes as $c) {
            $w += $t[$c - 32];
        }
        return $w * $size / 1000.0;
    }

    private static function esc(array $codes): string
    {
        $b = '';
        foreach ($codes as $c) {
            if ($c === 40 || $c === 41 || $c === 92) {
                $b .= '\\' . chr($c);
            } elseif ($c < 128) {
                $b .= chr($c);
            } else {
                $b .= sprintf('\\%03o', $c);
            }
        }
        return $b;
    }

    private static function fit(array $codes, bool $bold, float $size, float $maxw): array
    {
        if (self::width($codes, $bold, $size) <= $maxw) {
            return $codes;
        }
        $dots = [46, 46, 46];
        while ($codes && self::width(array_merge($codes, $dots), $bold, $size) > $maxw) {
            array_pop($codes);
        }
        return $codes ? array_merge($codes, $dots) : [];
    }

    /** @return array<int, int[]> lines */
    private static function wrap(string $text, bool $bold, float $size, float $maxw): array
    {
        $tokens = [];
        $word = [];
        foreach (self::ansi($text) as $c) {
            if ($c === 32) {
                if ($word) {
                    $tokens[] = $word;
                }
                $word = [];
            } else {
                $word[] = $c;
            }
        }
        if ($word) {
            $tokens[] = $word;
        }
        $lines = [];
        $cur = [];
        foreach ($tokens as $tk) {
            if (self::width($tk, $bold, $size) > $maxw) {
                $tk = self::fit($tk, $bold, $size, $maxw);
            }
            if (!$cur) {
                $cur = $tk;
            } elseif (self::width(array_merge($cur, [32], $tk), $bold, $size) <= $maxw) {
                $cur = array_merge($cur, [32], $tk);
            } else {
                $lines[] = $cur;
                $cur = $tk;
            }
        }
        if ($cur) {
            $lines[] = $cur;
        }
        return $lines;
    }

    private static function f2(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    private static function rgb(array $c): string
    {
        return self::f2($c[0] / 255) . ' ' . self::f2($c[1] / 255) . ' ' . self::f2($c[2] / 255);
    }

    // ------------------------------------------------------------ drawing (y measured from the top)
    private function rect(float $x, float $ytop, float $w, float $h, array $c): void
    {
        $this->pages[$this->cur][] = self::rgb($c) . ' rg ' . self::f2($x) . ' ' . self::f2($this->ph - $ytop - $h) . ' ' . self::f2($w) . ' ' . self::f2($h) . ' re f';
    }

    private function line(float $x1, float $y1, float $x2, array $c, float $wd = 0.5): void
    {
        $this->pages[$this->cur][] = self::rgb($c) . ' RG ' . self::f2($wd) . ' w ' . self::f2($x1) . ' ' . self::f2($this->ph - $y1) . ' m ' . self::f2($x2) . ' ' . self::f2($this->ph - $y1) . ' l S';
    }

    private function text(float $x, float $ybase, array $codes, bool $bold, float $size, array $c): void
    {
        $this->pages[$this->cur][] = 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::f2($size) . ' Tf ' . self::rgb($c) . ' rg ' . self::f2($x) . ' ' . self::f2($this->ph - $ybase) . ' Td (' . self::esc($codes) . ') Tj ET';
    }

    private function newPage(): void
    {
        $this->pages[] = [];
        $this->cur = count($this->pages) - 1;
        $cw = $this->pw - 2 * $this->m;
        if ($this->cur === 0) {
            $this->rect(0, 0, $this->pw, 74, [0, 150, 199]);
            $this->rect(0, 74, $this->pw, 4, [72, 202, 228]);
            $this->text($this->m, 32, self::fit(self::ansi(mb_strtoupper($this->org)), true, 9, $cw), true, 9, [202, 240, 248]);
            $this->text($this->m, 56, self::fit(self::ansi($this->title), true, 18, $cw), true, 18, [255, 255, 255]);
            $this->y = 96;
        } else {
            $this->text($this->m, $this->m, self::fit(self::ansi($this->org . ' - ' . $this->title), true, 9, $cw), true, 9, [2, 62, 138]);
            $this->line($this->m, $this->m + 6, $this->pw - $this->m, [72, 202, 228], 1);
            $this->y = $this->m + 22;
        }
    }

    private function ensure(float $h): void
    {
        if ($this->y + $h > $this->ph - $this->m - 20) {
            $this->newPage();
        }
    }

    // ------------------------------------------------------------ build
    public function output(): string
    {
        $this->pages = [];
        $this->newPage();
        $cw = $this->pw - 2 * $this->m;
        foreach ($this->subs as $s) {
            foreach (self::wrap((string) $s, false, 9, $cw) as $ln) {
                $this->ensure(13);
                $this->text($this->m, $this->y + 9, $ln, false, 9, [60, 80, 110]);
                $this->y += 13;
            }
        }
        $this->y += 6;
        if ($this->summary) {
            $x = $this->m;
            $this->ensure(30);
            foreach ($this->summary as [$label, $value]) {
                $lc = self::fit(self::ansi((string) $label), false, 7.5, 200);
                $vc = self::fit(self::ansi((string) $value), true, 12, 200);
                $bw = max(self::width($lc, false, 7.5), self::width($vc, true, 12)) + 20;
                if ($x + $bw > $this->pw - $this->m && $x > $this->m) {
                    $x = $this->m;
                    $this->y += 40;
                    $this->ensure(36);
                }
                $this->rect($x, $this->y, $bw, 34, [230, 242, 255]);
                $this->rect($x, $this->y, 3, 34, [0, 150, 199]);
                $this->text($x + 10, $this->y + 12, $lc, false, 7.5, [60, 80, 110]);
                $this->text($x + 10, $this->y + 28, $vc, true, 12, [3, 4, 94]);
                $x += $bw + 8;
            }
            $this->y += 46;
        }
        if ($this->desc !== '') {
            foreach (self::wrap($this->desc, false, 8.5, $cw) as $ln) {
                $this->ensure(12);
                $this->text($this->m, $this->y + 9, $ln, false, 8.5, [90, 110, 140]);
                $this->y += 12;
            }
            $this->y += 8;
        }
        foreach ($this->tables as $t) {
            $this->table((string) $t[0], array_values($t[1]), $t[2], $cw);
        }
        $total = count($this->pages);
        foreach (array_keys($this->pages) as $i) {
            $this->cur = $i;
            $this->line($this->m, $this->ph - $this->m + 2, $this->pw - $this->m, [200, 220, 235], 0.5);
            $this->text($this->m, $this->ph - $this->m + 14, self::ansi("Agie's Resort Management System"), false, 7.5, [120, 135, 160]);
            $pg = self::ansi('Page ' . ($i + 1) . ' of ' . $total);
            $this->text($this->pw - $this->m - self::width($pg, false, 7.5), $this->ph - $this->m + 14, $pg, false, 7.5, [120, 135, 160]);
        }
        return $this->write();
    }

    private function table(string $title, array $cols, array $rows, float $cw): void
    {
        $n = max(1, count($cols));
        $size = $n <= 8 ? 8.0 : ($n <= 11 ? 7.0 : 6.2);
        $pad = 4.0;
        $rowh = $size + 8;
        $heads = array_map(fn($c) => self::ansi((string) $c), $cols);
        $cells = [];
        $raw = [];
        foreach ($rows as $r) {
            $r = array_values((array) $r);
            $raw[] = array_map(fn($v) => (string) ($v ?? ''), $r);
            $cells[] = array_map(fn($v) => self::ansi((string) ($v ?? '')), $r);
        }
        $nat = [];
        $right = [];
        for ($i = 0; $i < $n; $i++) {
            $w = isset($heads[$i]) ? self::width($heads[$i], true, $size) : 20;
            foreach (array_slice($cells, 0, 400) as $r) {
                if (isset($r[$i])) {
                    $w = max($w, self::width($r[$i], false, $size));
                }
            }
            $nat[] = min($w, $cw * 0.45) + 2 * $pad;
            $vals = [];
            foreach ($raw as $r) {
                if (isset($r[$i]) && trim($r[$i]) !== '') {
                    $vals[] = trim($r[$i]);
                }
            }
            $isNum = (bool) $vals;
            foreach ($vals as $v) {
                if (!preg_match(self::NUMERIC, $v)) {
                    $isNum = false;
                    break;
                }
            }
            $right[] = $isNum;
        }
        $tot = array_sum($nat);
        $ws = [];
        if ($tot > $cw) {
            $share = $cw / $n;
            $fixed = 0.0;
            $flex = 0.0;
            foreach ($nat as $w) {
                if ($w <= $share) {
                    $fixed += $w;
                } else {
                    $flex += $w;
                }
            }
            $rest = $cw - $fixed;
            foreach ($nat as $w) {
                $ws[] = $w <= $share ? $w : $rest * $w / $flex;
            }
        } else {
            $extra = ($cw - $tot) / $n;
            foreach ($nat as $w) {
                $ws[] = $w + $extra;
            }
        }
        $header = function (bool $cont) use ($title, $cw, $rowh, $n, $heads, $ws, $right, $pad, $size): void {
            $this->ensure($rowh * 3 + 20);
            $this->text($this->m, $this->y + 11, self::fit(self::ansi($title . ($cont ? ' (continued)' : '')), true, 11, $cw), true, 11, [2, 62, 138]);
            $this->y += 18;
            $this->rect($this->m, $this->y, $cw, $rowh, [0, 150, 199]);
            $x = $this->m;
            for ($i = 0; $i < $n; $i++) {
                $h = self::fit($heads[$i] ?? [], true, $size, $ws[$i] - 2 * $pad);
                $tx = $right[$i] ? $x + $ws[$i] - $pad - self::width($h, true, $size) : $x + $pad;
                $this->text($tx, $this->y + $rowh - 5, $h, true, $size, [255, 255, 255]);
                $x += $ws[$i];
            }
            $this->y += $rowh;
        };
        $this->ensure($rowh * 3 + 20);
        $header(false);
        if (!$cells) {
            $this->text($this->m + $pad, $this->y + $rowh - 5, self::ansi('No records for this period.'), false, $size, [120, 135, 160]);
            $this->y += $rowh;
        }
        foreach ($cells as $ri => $r) {
            if ($this->y + $rowh > $this->ph - $this->m - 20) {
                $this->newPage();
                $header(true);
            }
            if ($ri % 2 === 1) {
                $this->rect($this->m, $this->y, $cw, $rowh, [240, 248, 255]);
            }
            $x = $this->m;
            for ($i = 0; $i < $n; $i++) {
                $v = self::fit($r[$i] ?? [], false, $size, $ws[$i] - 2 * $pad);
                $tx = $right[$i] ? $x + $ws[$i] - $pad - self::width($v, false, $size) : $x + $pad;
                $this->text($tx, $this->y + $rowh - 5, $v, false, $size, [30, 41, 59]);
                $x += $ws[$i];
            }
            $this->line($this->m, $this->y + $rowh, $this->m + $cw, [214, 230, 245], 0.4);
            $this->y += $rowh;
        }
        $this->y += 16;
    }

    private function write(): string
    {
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($this->pages as $ops) {
            $content = implode("\n", $ops);
            $objs[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $cid = count($objs);
            $objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::f2($this->pw) . ' ' . self::f2($this->ph) . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $cid . ' 0 R >>';
            $kids[] = count($objs) . ' 0 R';
        }
        $objs[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf('%010d', $o) . " 00000 n \n";
        }
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R /Info << /Producer (Agie's Resort Management System) >> >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    /** Sends the PDF as a download and stops the script. */
    public function send(string $filename): never
    {
        $pdf = $this->output();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }
}
