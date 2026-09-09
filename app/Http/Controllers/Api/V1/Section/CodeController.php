<?php

namespace App\Http\Controllers\Api\V1\Section;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\OrderingChannel;
use App\Models\Section\Code;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CodeController extends BaseController
{
    public function generateQrCode(Request $request, $code)
    {
        // Check if the code exists
        $code = Code::query()->where('code', $code)->first();
        if (!$code) {
            return $this->sendError('Invalid code', [], HTTP_NOT_FOUND);
        }

        return $this->qrResponse($request, $code);
    }

    public function getQrCode(Request $request, $code)
    {
        // Check if the code exists
        $code = Code::query()->where('code', $code)->first();
        if (!$code) {
            return $this->sendError('Invalid code', [], HTTP_NOT_FOUND);
        }

        return $this->qrResponse($request, $code);
    }

    private function qrResponse(Request $request, Code $code)
    {
        $channel = (string) $request->query('channel', 'dine_in');
        abort_unless(in_array($channel, OrderingChannel::activeSlugs(), true), HTTP_UNPROCESSABLE_ENTITY, 'The ordering channel is invalid.');
        $servicePoint = $code->codable;
        if ($servicePoint instanceof \App\Models\Section\ServicePoint) {
            abort_unless($servicePoint->orderingChannels()->where('slug', $channel)->exists(), HTTP_UNPROCESSABLE_ENTITY, 'This service point is not configured for that ordering channel.');
        }

        $url = rtrim(config('paperstick.client_url'), '/') . '/menu?' . http_build_query([
            'c' => $code->code,
            'channel' => $channel,
        ]);
        // The printed card places the Paperstick mark over the centre of the QR.
        // Use the highest correction level so the branded mark does not reduce scan reliability.
        $qrCode = QrCode::errorCorrection('H')->size(300)->generate($url);

        return response($qrCode, 200, ['Content-Type' => 'image/svg+xml']);
    }

}
