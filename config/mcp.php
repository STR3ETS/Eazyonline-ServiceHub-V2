<?php

return [
    // Jouw MCP endpoint (bijv. jouw eigen service)
    'base_url' => env('MCP_BASE_URL'),
    'api_key'  => env('MCP_API_KEY'),

    // endpoint pad (pas aan als jouw MCP anders werkt)
    'chat_path' => env('MCP_CHAT_PATH', '/chat'),
];
