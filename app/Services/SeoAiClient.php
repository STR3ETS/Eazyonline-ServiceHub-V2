<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SeoAiClient
{
    public function reply(array $messages, array $options = []): string
    {
        $apiKey = (string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY ontbreekt in je .env');
        }

        $model = (string) ($options['model'] ?? env('OPENAI_MODEL', 'gpt-5.2'));

        $payload = [
            'model' => $model,
            'reasoning' => ['effort' => $options['reasoning_effort'] ?? 'low'],
            'input' => $messages, // array met role/content
        ];

        // optioneel: losse instructions veld (mag ook via developer message)
        if (!empty($options['instructions'])) {
            $payload['instructions'] = (string) $options['instructions'];
        }

        $res = Http::withToken($apiKey) // Authorization: Bearer ...
            ->acceptJson()
            ->asJson()
            ->timeout((int) (env('OPENAI_REQUEST_TIMEOUT', 60)))
            ->post('https://api.openai.com/v1/responses', $payload)
            ->throw()
            ->json();

        return $this->extractText($res);
    }

    private function extractText(array $res): string
    {
        // Sommige SDK's geven output_text. Als die er is, pak die.
        if (!empty($res['output_text']) && is_string($res['output_text'])) {
            return trim($res['output_text']);
        }

        $textParts = [];

        $output = $res['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $item) {
                if (!is_array($item)) continue;

                if (($item['type'] ?? null) === 'message') {
                    $content = $item['content'] ?? null;

                    if (is_array($content)) {
                        foreach ($content as $c) {
                            if (!is_array($c)) continue;

                            // meestal: { type: "output_text", text: "..." }
                            if (($c['type'] ?? null) === 'output_text' && isset($c['text']) && is_string($c['text'])) {
                                $textParts[] = $c['text'];
                            }

                            // fallback varianten
                            if (isset($c['text']) && is_string($c['text'])) {
                                $textParts[] = $c['text'];
                            }
                            if (isset($c['content']) && is_string($c['content'])) {
                                $textParts[] = $c['content'];
                            }
                        }
                    }
                }
            }
        }

        $final = trim(implode("\n", array_filter(array_map('trim', $textParts))));
        return $final !== '' ? $final : '(Geen tekst output ontvangen van de AI)';
    }
}
