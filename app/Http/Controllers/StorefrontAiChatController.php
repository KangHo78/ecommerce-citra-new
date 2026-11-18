<?php

namespace App\Http\Controllers;

use App\Services\StorefrontAiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StorefrontAiChatController extends Controller
{
    public function __invoke(Request $request, StorefrontAiChatService $chat): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['sometimes', 'array', 'max:6'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:1000'],
        ]);

        try {
            $result = $chat->chat(
                trim($validated['message']),
                $validated['history'] ?? [],
                $request->user()
            );

            if (isset($result['message']) && is_string($result['message'])) {
                $result['message'] = preg_replace('/\bBOQI\b/iu', 'IBOQ', $result['message']) ?? $result['message'];
            }

            return response()->json($result);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'IBOQ sedang tidak tersedia. Silakan coba lagi beberapa saat.',
            ], 503);
        }
    }
}
