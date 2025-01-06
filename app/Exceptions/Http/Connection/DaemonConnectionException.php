<?php

namespace App\Exceptions\Http\Connection;

use Exception;
use Illuminate\Http\Response;
use App\Exceptions\DisplayException;
use Illuminate\Support\Facades\Context;

class DaemonConnectionException extends DisplayException
{
    private int $statusCode = Response::HTTP_GATEWAY_TIMEOUT;

    /**
     * Every request to the daemon instance will return a unique X-Request-Id header
     * which allows for all errors to be efficiently tied to a specific request that
     * triggered them, and gives users a more direct method of informing hosts when
     * something goes wrong.
     */
    private ?string $requestId;

    /**
     * Throw a displayable exception caused by a daemon connection error.
     */
    public function __construct(?Exception $previous, bool $useStatusCode = true)
    {
        /** @var \GuzzleHttp\Psr7\Response|null $response */
        $response = method_exists($previous, 'getResponse') ? $previous->getResponse() : null;
        $this->requestId = $response?->getHeaderLine('X-Request-Id');

        Context::add('request_id', $this->requestId);

        if ($useStatusCode) {
            $this->statusCode = is_null($response) ? $this->statusCode : $response->getStatusCode();
            // There are rare conditions where daemon encounters a panic condition and crashes the
            // request being made after content has already been sent over the wire. In these cases
            // you can end up with a "successful" response code that is actual an error.
            //
            // Handle those better here since we shouldn't ever end up in this exception state and
            // be returning a 2XX level response.
            if ($this->statusCode < 400) {
                $this->statusCode = Response::HTTP_BAD_GATEWAY;
            }
        }

        if (is_null($response)) {
            $message = 'Could not establish a connection to the machine running this server. Please try again.';
        } else {
            $message = sprintf('There was an error while communicating with the machine running this server. This error has been logged, please try again. (code: %s) (request_id: %s)', $response->getStatusCode(), $this->requestId ?? '<nil>');
        }

        // Attempt to pull the actual error message off the response and return that if it is not
        // a 500 level error.
        if ($this->statusCode < 500 && !is_null($response)) {
            $body = json_decode($response->getBody()->__toString(), true);
            $message = sprintf('An error occurred on the remote host: %s. (request id: %s)', $body['error'] ?? $message, $this->requestId ?? '<nil>');
        }

        $level = $this->statusCode >= 500 && $this->statusCode !== 504
            ? DisplayException::LEVEL_ERROR
            : DisplayException::LEVEL_WARNING;

        parent::__construct($message, $previous, $level);
    }

    /**
     * Return the HTTP status code for this exception.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
