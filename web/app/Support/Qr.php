<?php

namespace App\Support;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** A QR code as inline SVG markup (dark modules on white, quiet zone included: scanners need the light border). */
final class Qr
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,
            'markupDark' => '#0b0f0c',
        ]);

        return (new QRCode($options))->render($data);
    }
}
