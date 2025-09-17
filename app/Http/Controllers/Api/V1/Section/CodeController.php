<?php

namespace App\Http\Controllers\Api\V1\Section;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Section\Code;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CodeController extends BaseController
{
    public function generateQrCode($code)
    {
        // Check if the code exists
        $code = Code::query()->where('code', $code)->first();
        if (!$code) {
            return $this->sendError('Invalid code', [], HTTP_NOT_FOUND);
        }

        // Generate the URL that the QR code will point to
        $url = url('/menu/' . $code->code);

        // Generate the QR code
        $qrCode = QrCode::size(300)->generate($url);

        // Return the QR code as an image
        return response($qrCode, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function getQrCode($code)
    {
        // Check if the code exists
        $code = Code::query()->where('code', $code)->first();
        if (!$code) {
            return $this->sendError('Invalid code', [], HTTP_NOT_FOUND);
        }

        // Generate the URL that the QR code will point to
        $url = url('/menu/' . $code->code);

        // Generate the QR code
        $qrCode = QrCode::size(300)->generate($url);

        // Return the QR code as an image
        return response($qrCode, 200, ['Content-Type' => 'image/svg+xml']);
    }

}
