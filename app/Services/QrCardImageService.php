<?php

namespace App\Services;

use App\Models\PatrolPoint;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class QrCardImageService
{
    private const WIDTH = 520;
    private const HEIGHT = 860;
    // Bump versi saat layout card berubah agar file lama tidak dipakai lagi.
    private const VERSION = 3;

    private const FONT_REGULAR = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    private const FONT_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    public function ensurePublicWebpForPatrolPoint(PatrolPoint $patrolPoint): ?string
    {
        $patrolPoint->loadMissing(['qrCode', 'post.project.organization']);

        if (! $patrolPoint->qrCode) {
            return null;
        }

        $path = $this->relativeWebpPath($patrolPoint);
        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        // Pastikan folder target ada (dan bisa ditulis oleh user webserver).
        $dir = dirname($path);
        if (! Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->makeDirectory($dir);
        }

        $manager = new ImageManager(new Driver());
        $img = $manager->create(self::WIDTH, self::HEIGHT)->fill('ffffff');

        // Logo
        $logoPath = $patrolPoint->post?->project?->organization?->logo;
        $logo = $this->loadLogoImage($manager, $logoPath);
        if ($logo) {
            $logo->coverDown(110, 110);
            $img->place($logo, 'top-center', 0, 30);
        }

        // Organization name (wrap max 2 lines)
        $orgName = strtoupper((string) ($patrolPoint->post?->project?->organization?->name ?? 'ORGANIZATION'));
        $this->drawCenteredWrappedText($img, $orgName, 0, 170, 2, 42, true);

        // QR frame
        $frameX = 90;
        $frameY = 270;
        $frameSize = 340;
        $img->drawRectangle($frameX, $frameY, function ($r) use ($frameSize) {
            $r->size($frameSize, $frameSize);
            $r->background('ffffff');
            $r->border('111111', 4);
        });

        // QR PNG (no SVG)
        $qrPng = $this->qrPngBytesGd($patrolPoint->qrCode->code, 300);
        $qr = $manager->read($qrPng);
        $img->place($qr, 'top-left', 110, 290);

        // Text blocks
        $postName = strtoupper((string) ($patrolPoint->post?->name ?? '-'));
        $projectName = strtoupper((string) ($patrolPoint->post?->project?->name ?? '-'));
        $pointName = strtoupper((string) ($patrolPoint->name ?? '-'));

        // Font post diperkecil sedikit, dan patrol point dinaikkan agar tidak terpotong.
        $this->drawCenteredWrappedText($img, $postName, 0, 640, 2, 28, true);
        $this->drawCenteredWrappedText($img, $projectName, 0, 740, 1, 32, true);
        $this->drawCenteredWrappedText($img, $pointName, 0, 770, 2, 28, false);

        $ok = Storage::disk('public')->put($path, (string) $img->toWebp(85));
        if (! $ok) {
            return null;
        }

        return $path;
    }

    private function relativeWebpPath(PatrolPoint $patrolPoint): string
    {
        $code = $patrolPoint->qrCode?->code ?? 'no-qr';
        $safeCode = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $code);
        $updated = $patrolPoint->updated_at?->timestamp ?? time();

        return "qr-cards/v".self::VERSION."/patrol-point-{$patrolPoint->id}-{$safeCode}-{$updated}.webp";
    }

    private function loadLogoImage(ImageManager $manager, ?string $logoPath)
    {
        if (! $logoPath) {
            return null;
        }

        if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
            // Remote fetch not handled here (keep it simple & deterministic)
            return null;
        }

        if (! Storage::disk('public')->exists($logoPath)) {
            return null;
        }

        return $manager->read(Storage::disk('public')->path($logoPath));
    }

    private function drawCenteredWrappedText($img, string $text, int $x, int $y, int $maxLines, int $size, bool $bold): void
    {
        $lines = $this->splitTextToLines($text, $maxLines, $size);
        $lineHeight = (int) round($size * 1.2);

        foreach ($lines as $i => $line) {
            $img->text($line, self::WIDTH / 2, $y + ($i * $lineHeight), function ($font) use ($size, $bold) {
                $font->filename($bold ? self::FONT_BOLD : self::FONT_REGULAR);
                $font->size($size);
                $font->color('111111');
                $font->align('center');
                $font->valign('top');
            });
        }
    }

    private function splitTextToLines(string $text, int $maxLines, int $fontSize): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($normalized === '') {
            return ['-'];
        }

        // Approximation: bigger font => fewer chars per line
        $maxCharsPerLine = match (true) {
            $fontSize >= 42 => 18,
            $fontSize >= 40 => 20,
            $fontSize >= 38 => 22,
            default => 24,
        };

        $words = explode(' ', $normalized);
        $lines = [];
        $current = '';
        $total = count($words);

        for ($i = 0; $i < $total; $i++) {
            $w = $words[$i];
            $candidate = $current === '' ? $w : $current.' '.$w;
            if (mb_strlen($candidate) <= $maxCharsPerLine) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            } else {
                $lines[] = mb_substr($w, 0, $maxCharsPerLine);
                $w = mb_substr($w, $maxCharsPerLine);
            }

            if (count($lines) >= $maxLines - 1) {
                $remaining = [];
                if ($w !== '') {
                    $remaining[] = $w;
                }
                if ($i + 1 < $total) {
                    $remaining[] = implode(' ', array_slice($words, $i + 1));
                }
                $lines[] = $this->truncateWithEllipsis(trim(implode(' ', $remaining)), $maxCharsPerLine);
                return $lines;
            }

            $current = $w;
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        return array_slice($lines, 0, $maxLines);
    }

    private function truncateWithEllipsis(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxChars - 1))).'…';
    }

    private function qrPngBytesGd(string $code, int $sizePx): string
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($code)
            ->size($sizePx)
            ->margin(1)
            ->build();

        return $result->getString();
    }
}

