<?php
namespace App\Http;

function getBearerToken(): ?string
{
    // Compatible Apache / Nginx / CLI server
    $header = $_SERVER['HTTP_AUTHORIZATION'] 
        ?? $_SERVER['Authorization'] 
        ?? null;

    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    }

    if (!$header || !str_starts_with($header, 'Bearer ')) {
        return null;
    }

    return substr($header, 7);
}