<?php

namespace App\Http;

use function App\Http\getBearerToken;

class AuthMiddleware
{
    public function __construct(private string $validToken) {}

    public function handle(callable $next): callable
    {
        return function () use ($next) {

            $token = getBearerToken();

            if (!$token || $token !== $this->validToken) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }

            return $next();
        };
    }
}