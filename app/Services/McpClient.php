<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class McpClient
{
    public function isConfigured(): bool
    {
        // Base URL is genoeg om te werken
        return (string) config('mcp.base_url', '') !== '';
    }

    /**
     * POST {base_url}{chat_path}
     * {
     *   "messages": [{"role":"user","content":"..."}],
     *   "context": {...},
     *   "mode": "chat" | "keyword_plan"
     * }
     *
     * Response verwacht:
     * {
     *   "reply": "tekst...",
     *   "keyword_plan": {...} // optioneel
     * }
     */
    public function chat(array $messages, array $context = [], string $mode = 'chat'): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'reply' => 'MCP is nog niet geconfigureerd. Zet MCP_BASE_URL in je .env.',
            ];
        }

        $baseUrl = rtrim((string) config('mcp.base_url'), '/');
        $path    = (string) config('mcp.chat_path', '/mcp');
        $apiKey  = (string) config('mcp.api_key', '');

        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // Alleen meesturen als er echt een key is
        if (trim($apiKey) !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $url = $baseUrl . $path;

        $res = Http::withHeaders($headers)->post($url, [
            'mode'     => $mode,
            'messages' => $messages,
            'context'  => $context,
        ]);

        if ($res->failed()) {
            return [
                'ok' => false,
                'reply' => 'MCP call faalde: ' . $res->status(),
                'debug' => [
                    'url'  => $url,
                    'body' => $res->body(),
                ],
            ];
        }

        // Soms geeft een endpoint tekst terug i.p.v. JSON
        $json = $res->json();
        if (!is_array($json)) {
            return [
                'ok' => true,
                'reply' => (string) $res->body(),
                'keyword_plan' => null,
                'raw' => ['raw_text' => (string) $res->body()],
            ];
        }

        return [
            'ok' => true,
            'reply' => (string) ($json['reply'] ?? ''),
            'keyword_plan' => $json['keyword_plan'] ?? null,
            'raw' => $json,
        ];
    }
}
