<?php

declare(strict_types=1);

namespace App\Services\Tables;

use App\Models\DiningTable;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Encoder\QrCode;

/**
 * Renders table QR codes as SVG.
 *
 * SVG rather than PNG because these get printed on table tents at whatever
 * size the venue chooses — vector stays sharp at A4 and the payload is small
 * enough to inline straight into the admin page.
 */
class QrCodeService
{
    /** The URL a guest lands on after scanning. */
    public function urlFor(DiningTable $table): string
    {
        return rtrim((string) config('viking.frontend_url'), '/').'/t/'.$table->qr_token;
    }

    /**
     * @param  int  $size  rendered edge length in pixels
     * @param  int  $margin  quiet-zone width in modules (4 is the spec minimum)
     */
    public function svgFor(DiningTable $table, int $size = 512, int $margin = 4): string
    {
        return $this->svg($this->urlFor($table), $size, $margin);
    }

    public function svg(string $payload, int $size = 512, int $margin = 4): string
    {
        // Medium error correction: survives a scuffed table tent without
        // inflating the module count the way High would.
        $qrCode = Encoder::encode($payload, ErrorCorrectionLevel::M());

        return $this->render($qrCode, $size, $margin);
    }

    /** Data URI form, for inlining into an <img> or a print stylesheet. */
    public function dataUriFor(DiningTable $table, int $size = 512): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svgFor($table, $size));
    }

    private function render(QrCode $qrCode, int $size, int $margin): string
    {
        $matrix = $qrCode->getMatrix();
        $modules = $matrix->getWidth();
        $total = $modules + ($margin * 2);

        $paths = [];

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $paths[] = 'M'.($x + $margin).','.($y + $margin).'h1v1h-1z';
                }
            }
        }

        $path = implode('', $paths);

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$total} {$total}" shape-rendering="crispEdges" role="img" aria-label="QR code">
            <rect width="{$total}" height="{$total}" fill="#ffffff"/>
            <path d="{$path}" fill="#0b0b0d"/>
            </svg>
            SVG;
    }
}
