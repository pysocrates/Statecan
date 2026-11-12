<?php

namespace App\Agent;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiErrorListener
{
    #[AsEventListener(event: 'kernel.exception', priority: -10)]
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/v1')) {
            return;
        }
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof \App\Service\CoreConflict ? 409 : 500);
        $event->setResponse(new JsonResponse(['error' => [
            'status' => $status,
            'code' => match ($status) { 400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 415 => 'unsupported_media_type', 422 => 'validation_failed', default => 'request_failed' },
            'message' => $status < 500 ? $error->getMessage() : 'The request could not be completed.',
        ]], $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []));
    }
}
