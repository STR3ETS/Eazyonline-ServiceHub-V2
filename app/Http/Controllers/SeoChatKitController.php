<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SeoChatKitController extends Controller
{
    public function session(Request $request)
    {
        $apiKey = env('OPENAI_CHATKIT_SECRET_KEY');
        $workflowId = env('CHATKIT_WORKFLOW_ID');

        if (!$apiKey || !$workflowId) {
            return response()->json(['message' => 'ChatKit env vars ontbreken'], 500);
        }

        // user id mag een deviceId / userId zijn, docs gebruiken "user"
        $user = (string) (auth()->id() ?: $request->ip());

        $res = Http::withToken($apiKey)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'chatkit_beta=v1',
            ])
            ->post('https://api.openai.com/v1/chatkit/sessions', [
                'workflow' => ['id' => $workflowId],
                'user' => $user,
            ])
            ->throw()
            ->json();

        return response()->json([
            'client_secret' => $res['client_secret'] ?? null,
        ]);
    }
}
