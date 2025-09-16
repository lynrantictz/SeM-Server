<?php

namespace App\Services\Request;

use Illuminate\Http\JsonResponse;

trait RequestResponse
{
    /**
     * Send a success response.
     */
    public function sendResponse($data, $message = '', $code = HTTP_OK): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Send an error response.
     */
    public function sendError($error, $errorMessages = [], $code = HTTP_BAD_REQUEST): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }

        return response()->json($response, $code);
    }
}
