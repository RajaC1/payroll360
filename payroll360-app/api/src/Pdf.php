<?php
declare(strict_types=1);

namespace P360;

/** Minimal PDF 1.4 writer: text, rectangles, lines and JPEG / RGB-PNG images, using the built-in Helvetica fonts. */
final class PdfDoc
{
    public const W = 595.28;
    public const H = 841.89;

    private const HELV = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const HELV_BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    /** @var string[] */
    private array $pages = [];
    private int $cur = -1;
    /** @var array<string,array> */
    private array $images = [];
    private array $imageUse = [];

    public function addPage(): int
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
        $this->imageUse[$this->cur] = [];
        return $this->cur;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setPage(int $i): void
    {
        $this->cur = $i;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function color(array $rgb, bool $stroke = false): string
    {
        return self::num((float) $rgb[0]) . ' ' . self::num((float) $rgb[1]) . ' ' . self::num((float) $rgb[2]) . ($stroke ? ' RG' : ' rg') . "\n";
    }

    private static function enc(string $s): string
    {
        $s = (string) @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $w = $bold ? self::HELV_BOLD : self::HELV;
        $sum = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $sum += ($c >= 32 && $c <= 126) ? $w[$c - 32] : 556;
        }
        // multi-byte UTF-8 characters count once
        $sum -= 556 * 0;
        return $sum * $size / 1000;
    }

    /** Text with a top-left origin. Returns the width drawn. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0], string $align = 'left'): float
    {
        $enc = self::enc($s);
        $w = $this->textWidth($enc, $size, $bold);
        if ($align === 'right') {
            $x -= $w;
        } elseif ($align === 'center') {
            $x -= $w / 2;
        }
        $this->pages[$this->cur] .= 'BT ' . self::color($rgb) . '/' . ($bold ? 'F2' : 'F1') . ' ' . self::num($size) . ' Tf ' . self::num($x) . ' ' . self::num(self::H - $y) . ' Td (' . $enc . ") Tj ET\n";
        return $w;
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $s = '';
        if ($fill) {
            $s .= self::color($fill);
        }
        if ($stroke) {
            $s .= self::color($stroke, true) . self::num($lw) . " w\n";
        }
        $this->pages[$this->cur] .= $s . self::num($x) . ' ' . self::num(self::H - $y - $h) . ' ' . self::num($w) . ' ' . self::num($h) . " re {$op}\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [0.8, 0.8, 0.8], float $lw = 0.5): void
    {
        $this->pages[$this->cur] .= self::color($rgb, true) . self::num($lw) . " w\n" . self::num($x1) . ' ' . self::num(self::H - $y1) . ' m ' . self::num($x2) . ' ' . self::num(self::H - $y2) . " l S\n";
    }

    /** Shorten text with an ellipsis so it fits in $maxW. */
    public function fit(string $s, float $maxW, float $size, bool $bold = false): string
    {
        if ($this->textWidth(self::enc($s), $size, $bold) <= $maxW) {
            return $s;
        }
        while ($s !== '' && $this->textWidth(self::enc($s . '...'), $size, $bold) > $maxW) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '...';
    }

    /** Draw a JPEG or (8-bit, non-interlaced, opaque) PNG. Returns false when the format is not supported. */
    public function image(string $bytes, float $x, float $y, float $w, float $h): bool
    {
        $key = 'Im' . (count($this->images) + 1);
        $info = @getimagesizefromstring($bytes);
        if (!$info) {
            return false;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $channels = (int) ($info['channels'] ?? 3);
            if (!in_array($channels, [1, 3], true)) {
                return false;
            }
            $img = ['w' => $info[0], 'h' => $info[1], 'cs' => $channels === 1 ? '/DeviceGray' : '/DeviceRGB', 'bpc' => 8, 'filter' => '/DCTDecode', 'data' => $bytes, 'parms' => ''];
        } elseif ($info[2] === IMAGETYPE_PNG) {
            $img = self::parsePng($bytes);
            if (!$img) {
                return false;
            }
        } else {
            return false;
        }
        $this->images[$key] = $img;
        $this->imageUse[$this->cur][$key] = true;
        $this->pages[$this->cur] .= "q\n" . self::num($w) . ' 0 0 ' . self::num($h) . ' ' . self::num($x) . ' ' . self::num(self::H - $y - $h) . " cm\n/{$key} Do\nQ\n";
        return true;
    }

    private static function parsePng(string $b): ?array
    {
        if (substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $pos = 8;
        $len = strlen($b);
        $idat = '';
        $plte = '';
        $hdr = null;
        while ($pos + 8 <= $len) {
            $n = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            $data = substr($b, $pos + 8, $n);
            if ($type === 'IHDR') {
                $hdr = unpack('Nw/Nh/Cbd/Cct/Ccm/Cfm/Cil', $data);
            } elseif ($type === 'PLTE') {
                $plte = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 12 + $n;
        }
        if (!$hdr || $hdr['il'] !== 0 || $hdr['cm'] !== 0 || !in_array($hdr['ct'], [0, 2, 3], true) || $idat === '') {
            return null;
        }
        if ($hdr['ct'] !== 3 && $hdr['bd'] !== 8) {
            return null;
        }
        $colors = $hdr['ct'] === 2 ? 3 : 1;
        $cs = $hdr['ct'] === 2 ? '/DeviceRGB' : ($hdr['ct'] === 0 ? '/DeviceGray' : '[/Indexed /DeviceRGB ' . (intdiv(strlen($plte), 3) - 1) . ' <' . bin2hex($plte) . '>]');
        return ['w' => $hdr['w'], 'h' => $hdr['h'], 'cs' => $cs, 'bpc' => $hdr['bd'], 'filter' => '/FlateDecode', 'data' => $idat,
            'parms' => '/DecodeParms << /Predictor 15 /Colors ' . $colors . ' /BitsPerComponent ' . $hdr['bd'] . ' /Columns ' . $hdr['w'] . ' >>'];
    }

    public function output(string $title = 'Document'): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5;
        $imgIds = [];
        foreach ($this->images as $name => $im) {
            $imgIds[$name] = $next;
            $objs[$next++] = '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace ' . $im['cs'] . ' /BitsPerComponent ' . $im['bpc'] .
                ' /Filter ' . $im['filter'] . ' ' . $im['parms'] . ' /Length ' . strlen($im['data']) . " >>\nstream\n" . $im['data'] . "\nendstream";
        }
        $kids = [];
        foreach ($this->pages as $i => $content) {
            $contentId = $next++;
            $pageId = $next++;
            $objs[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $xo = '';
            foreach (array_keys($this->imageUse[$i] ?? []) as $name) {
                $xo .= '/' . $name . ' ' . $imgIds[$name] . ' 0 R ';
            }
            $objs[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::num(self::W) . ' ' . self::num(self::H) . '] /Contents ' . $contentId .
                ' 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . ($xo !== '' ? ' /XObject << ' . $xo . '>>' : '') . ' >> >>';
            $kids[] = $pageId . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $infoId = $next++;
        $objs[$infoId] = '<< /Producer (Payroll360) /Title (' . self::enc($title) . ') >>';

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        return $out . 'trailer << /Size ' . ($max + 1) . ' /Root 1 0 R /Info ' . $infoId . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}

/** Renders a frozen payslip document (see Payslips::buildDoc) to PDF. */
final class PayslipPdf
{
    private const M = 40.0;
    private const INK = [0.06, 0.09, 0.16];
    private const MUTED = [0.42, 0.45, 0.5];
    private const ACCENT = [0.078, 0.659, 0.255];
    private const DARK = [0.004, 0.267, 0.176];
    private const BAND = [0.953, 0.957, 0.965];
    private const RULE = [0.86, 0.87, 0.89];

    private static function group(string $dec): string
    {
        $neg = str_starts_with($dec, '-');
        $dec = ltrim($dec, '-');
        [$int, $frac] = array_pad(explode('.', $dec, 2), 2, null);
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);
        return ($neg ? '-' : '') . $int . ($frac !== null ? '.' . $frac : '');
    }

    public static function money(string $dec, string $cur): string
    {
        return $cur . ' ' . self::group($dec);
    }

    private static function wrap(PdfDoc $pdf, string $text, float $width, float $size): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $pdf->textWidth($try, $size) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    public static function render(array $doc): string
    {
        $pdf = new PdfDoc();
        $pdf->addPage();
        $cur = (string) $doc['currency'];
        $left = self::M;
        $right = PdfDoc::W - self::M;
        $width = $right - $left;
        $y = 40.0;

        // ---- header
        $textX = $left;
        $logo = $doc['employer']['logo'] ?? null;
        if ($logo && preg_match('#^data:image/(?:png|jpeg);base64,(.+)$#', (string) $logo, $m)) {
            if ($pdf->image((string) base64_decode($m[1], true), $left, $y, 44, 44)) {
                $textX = $left + 54;
            }
        }
        $pdf->text($textX, $y + 12, $pdf->fit((string) $doc['employer']['name'], 300, 15, true), 15, true, self::DARK);
        $ay = $y + 26;
        foreach (array_slice(self::wrap($pdf, (string) ($doc['employer']['address'] ?? ''), 280, 8.5), 0, 2) as $l) {
            $pdf->text($textX, $ay, $l, 8.5, false, self::MUTED);
            $ay += 11;
        }
        $pdf->text($right, $y + 12, strtoupper((string) $doc['employer']['title']), 17, true, self::INK, 'right');
        $pdf->text($right, $y + 27, 'No. ' . $doc['number'], 8.5, false, self::MUTED, 'right');
        $pdf->text($right, $y + 39, $doc['period']['start'] . ' to ' . $doc['period']['end'], 8.5, false, self::MUTED, 'right');
        $y += 62;
        $pdf->line($left, $y, $right, $y, self::DARK, 1.2);
        $y += 14;

        // ---- employee block
        $emp = $doc['employee'];
        $region = trim(($emp['country'] ?? '') . (!empty($emp['state_region']) ? ' / ' . $emp['state_region'] : ''));
        $cells = [
            ['Employee', $emp['name'] ?? ''], ['Employee ID', $emp['employee_no'] ?? ''], ['Job title', $emp['job_title'] ?? '-'], ['Department', $emp['department'] ?? '-'],
            ['Pay date', $doc['period']['pay_date']], ['Pay frequency', ucfirst((string) $doc['period']['frequency'])], ['Country / region', $region ?: '-'], ['Tax ID', $emp['tax_id'] ?? '-'],
        ];
        $colW = $width / 4;
        $pdf->rect($left, $y - 4, $width, 74, self::BAND);
        foreach ($cells as $i => [$label, $value]) {
            $cx = $left + 8 + ($i % 4) * $colW;
            $cy = $y + 8 + intdiv($i, 4) * 32;
            $pdf->text($cx, $cy, strtoupper($label), 6.8, true, self::MUTED);
            $pdf->text($cx, $cy + 12, $pdf->fit((string) $value, $colW - 12, 9.5, true), 9.5, true, self::INK);
        }
        $y += 84;
        if (!empty($emp['bank_name']) || !empty($emp['bank_account'])) {
            $pdf->text($left, $y, 'Paid to: ' . trim(($emp['bank_name'] ?? '') . ' ' . ($emp['bank_account'] ?? '')), 8.5, false, self::MUTED);
            $y += 16;
        }

        $footerNeed = 70.0;
        $newPage = function () use ($pdf, &$y): void {
            $pdf->addPage();
            $y = 50.0;
        };
        $table = function (string $title, array $rows, array $accent) use ($pdf, $left, $right, $width, $cur, &$y, $footerNeed, $newPage): void {
            if (!$rows) {
                return;
            }
            if ($y + 40 > PdfDoc::H - $footerNeed) {
                $newPage();
            }
            $pdf->rect($left, $y, $width, 20, self::BAND);
            $pdf->text($left + 8, $y + 13.5, strtoupper($title), 8.5, true, $accent);
            $pdf->text($right - 8, $y + 13.5, 'AMOUNT (' . $cur . ')', 7.5, true, self::MUTED, 'right');
            $y += 24;
            foreach ($rows as $r) {
                $h = !empty($r['note']) ? 25.0 : 16.0;
                if ($y + $h > PdfDoc::H - $footerNeed) {
                    $newPage();
                }
                $pdf->text($left + 8, $y + 10.5, $pdf->fit((string) $r['name'], $width - 130, 9.5), 9.5, false, self::INK);
                $pdf->text($right - 8, $y + 10.5, self::group((string) $r['amount']), 9.5, true, self::INK, 'right');
                if (!empty($r['note'])) {
                    $pdf->text($left + 8, $y + 20, $pdf->fit((string) $r['note'], $width - 130, 7.3), 7.3, false, self::MUTED);
                }
                $pdf->line($left, $y + $h - 1.5, $right, $y + $h - 1.5, self::RULE, 0.4);
                $y += $h;
            }
            $y += 8;
        };

        $table('Earnings', $doc['earnings'], self::DARK);
        $table('Taxes', $doc['taxes'], [0.72, 0.2, 0.08]);
        $table('Benefits (employee contributions)', $doc['benefits'], self::DARK);
        $table('Other deductions', $doc['deductions'], [0.72, 0.2, 0.08]);

        // ---- summary
        if ($y + 150 > PdfDoc::H - $footerNeed) {
            $newPage();
        }
        $t = $doc['totals'];
        $sum = [['Gross pay', $t['gross']], ['Total deductions (taxes, benefits and deductions)', $t['total_deductions']]];
        foreach ($sum as [$label, $val]) {
            $pdf->text($left + 8, $y + 10, $label, 9.5, false, self::INK);
            $pdf->text($right - 8, $y + 10, self::money((string) $val, $cur), 9.5, true, self::INK, 'right');
            $y += 17;
        }
        $y += 4;
        $pdf->rect($left, $y, $width, 40, self::DARK);
        $pdf->text($left + 12, $y + 16, 'NET PAY', 9, true, [0.75, 0.9, 0.8]);
        $pdf->text($left + 12, $y + 30, $pdf->fit('Direct deposit', 200, 8), 8, false, [0.75, 0.9, 0.8]);
        $pdf->text($right - 12, $y + 27, self::money((string) $t['net'], $cur), 19, true, [0.42, 0.95, 0.6], 'right');
        $y += 54;

        // ---- year to date
        $ytd = $doc['ytd'];
        $pdf->text($left, $y + 8, 'YEAR TO DATE', 7.5, true, self::MUTED);
        $y += 16;
        $cols = [['Gross', $ytd['gross']], ['Tax', $ytd['taxes']], ['Deductions', $ytd['deductions']], ['Net', $ytd['net']]];
        foreach ($cols as $i => [$label, $val]) {
            $cx = $left + 8 + $i * ($width / 4);
            $pdf->text($cx, $y + 6, strtoupper($label), 6.8, true, self::MUTED);
            $pdf->text($cx, $y + 18, self::money((string) $val, $cur), 9, true, self::INK);
        }
        $y += 34;
        foreach ($doc['notes'] ?? [] as $n) {
            $pdf->text($left, $y, $n, 7.8, false, [0.72, 0.2, 0.08]);
            $y += 11;
        }

        // ---- footers on every page
        $total = $pdf->pageCount();
        for ($i = 0; $i < $total; $i++) {
            $pdf->setPage($i);
            $pdf->line($left, PdfDoc::H - 44, $right, PdfDoc::H - 44, self::RULE, 0.5);
            $pdf->text($left, PdfDoc::H - 30, 'Confidential. Computer-generated by Payroll360. No signature required.', 7.5, false, self::MUTED);
            $pdf->text($right, PdfDoc::H - 30, 'Page ' . ($i + 1) . ' of ' . $total, 7.5, false, self::MUTED, 'right');
        }
        return $pdf->output($doc['number'] . ' ' . $emp['name']);
    }
}
