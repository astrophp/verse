<?php

declare(strict_types=1);

namespace Astro\Verse\Serve;

/**
 * What the preview router decided to send: a status, headers and either an in-memory body or a file to stream.
 */
final readonly class PreviewResponse
{
    /**
     * @param  array<string, string>  $headers
     * @param  resource|null  $stream  an open file streamed instead of `$body`; its length is in `Content-Length`.
     *                                 Holding the handle keeps the bytes consistent if the file is replaced meanwhile.
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
        public mixed $stream = null,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The same response for a HEAD request: headers (Content-Length included), no body.
     */
    public function withoutBody(): self
    {
        $this->close();

        return new self($this->status, $this->headers);
    }

    /**
     * Writes the body and closes the stream, if any.
     */
    public function emit(): void
    {
        if (is_resource($this->stream)) {
            fpassthru($this->stream);
            $this->close();

            return;
        }

        echo $this->body;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
