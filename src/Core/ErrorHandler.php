<?php
namespace App\Core;

use Throwable;

class ErrorHandler
{
    public function __construct(private bool $debug = false) {}

    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
    }

    public function handleException(Throwable $e): void
    {
        $code = $this->resolveStatusCode($e);

        http_response_code($code);

        echo json_encode($this->formatError($e, $code));

        exit; // 🔥 obligatoire
    }

    public function handleError(int $severity, string $message, string $file, int $line): void
    {
        // transforme les erreurs PHP en exceptions
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    private function resolveStatusCode(Throwable $e): int
    {
        return match (true) {
            $e instanceof \App\Exception\NotFoundException => 404,
            $e instanceof \App\Exception\UnauthorizedException => 401,
            default => 500,
        };
    }

    private function formatError(Throwable $e, int $code): array
    {
        if (!$this->debug) {
            return [
                'error' => $this->getMessageByCode($code),
            ];
        }

        return [
            'error' => $e->getMessage(),
            'type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    private function getMessageByCode(int $code): string
    {
        return match ($code) {
            404 => 'Not found',
            401 => 'Unauthorized',
            default => 'Server error',
        };
    }
}