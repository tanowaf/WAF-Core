<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\RequestBodyCantBeCompressed;
use TanoWAF\WAFCore\Exception\RequestBodyCantBeDecompressed;
use TanoWAF\WAFCore\Exception\ResponseBodyCantBeCompressed;
use TanoWAF\WAFCore\Exception\ResponseBodyCantBeDecompressed;
use TanoWAF\WAFCore\Exception\UnsupportedMediaType;

/**
 * @todo according to https://en.wikipedia.org/wiki/HTTP_compression, there are many unofficial compression schemes
 *       in use in the wild: bzip2,lzip, lzma, peerdist, rsync, xpress and xz. Should we support those?
 */
trait BodyCompressorTrait
{
    /**
     * NB: checks header 'Content-Encoding' but not 'Transfer-Encoding'
     */
    protected function messageBodyIsCompressed(MessageInterface $message): bool
    {
        if ($message->hasHeader('Content-Encoding')) {
            foreach ($message->getHeader('Content-Encoding') as $encoding) {
                if (strtolower($encoding) !== 'identity') {
                    return true;
                }
            }
        }


        return false;
    }

    protected function messageBodyIsTransferEncoded(MessageInterface $message): bool
    {
        // @see https://www.iana.org/assignments/http-parameters#transfer-coding
        // we support 'identity', even if it is withdrawn
        if ($message->hasHeader('Transfer-Encoding')) {
            foreach ($message->getHeader('Transfer-Encoding') as $encoding) {
                if (strtolower($encoding) !== 'identity') {
                    return true;
                }
            }
        }
        return false;
    }

    protected function compressMessageBody(MessageInterface $message, array $contentEncodings, string &$actualEncoding): string
    {
        /// @todo implement streaming compression - see f.e. Guzzle's Psr7\InflateStream
        $stream = $message->getBody();
        $stream->rewind();
        $body = $stream->getContents();

        $out = $this->compressPayload($body, $contentEncodings, $actualEncoding);

        if ($out === false) {
            if ($message instanceof RequestInterface) {
                throw new RequestBodyCantBeCompressed("Unsupported content-encoding(s): '" . implode("', '", $contentEncodings) . "'");
            } else {
                throw new ResponseBodyCantBeCompressed("Unsupported content-encoding(s): '" . implode("', '", $contentEncodings) . "'");
            }
        }

        return $out;
    }

    /**
     * Compresses a string with the first possible encoding from a given list. Does not modify the message headers.
     * Does not check if the message was already compressed.
     * @param string[] $contentEncodings
     * @param string|null $actualEncoding Encoding used. Will be set to an empty string when 'identity' is passed in
     * @todo allow streams for $body
     */
    protected function compressPayload(string $body, array $contentEncodings, string|null &$actualEncoding): string|false
    {
        foreach ($contentEncodings as $contentEncoding) {

            $contentEncoding = strtolower($contentEncoding);

            switch ($contentEncoding) {
                /// @todo add support for aes128gcm, dcb, dcz, exi, pack200-gzip
                case 'br':
                //case 'dcb':
                //case 'dcz':
                    if (function_exists('brotli_compress')) {
                        $compressed = @brotli_compress($body);
                        if ($compressed !== false) {
                            $actualEncoding = $contentEncoding;
                            return $compressed;
                        } else {
                            if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
                                $this->logger->warning("Failed compressing message body with brotli_compress");
                            }
                        }
                    }
                    break;
                /// @todo... uncomment this after finishing the UnixCompressor
                /*case 'compress':
                case 'x-compress':
                        $compressed = UnixCompressor::compress($body);
                        if ($compressed !== false) {
                            $actualEncoding = $contentEncoding;
                            return $compressed;
                        } else {
                            if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
                                $this->logger->warning("Failed compressing message body with compress");
                            }
                        }
                    }
                    break;*/
                case 'deflate':
                    if (function_exists('gzcompress')) {
                        $compressed = @gzcompress($body);
                        if ($compressed !== false) {
                            $actualEncoding = $contentEncoding;
                            return $compressed;
                        } else {
                            if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
                                $this->logger->warning("Failed compressing message body with gzcompress");
                            }
                        }
                    }
                    break;
                case 'gzip':
                case 'x-gzip':
                    if (function_exists('gzencode')) {
                        $compressed = @gzencode($body);
                        if ($compressed !== false) {
                            $actualEncoding = $contentEncoding;
                            return $compressed;
                        } else {
                            if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
                                $this->logger->warning("Failed compressing message body with gzencode");
                            }
                        }
                    }
                    break;
                case 'identity':
                    $actualEncoding = ''; //$contentEncoding;
                    return $body;
                case 'zstd':
                    if (function_exists('zstd_compress')) {
                        /** @phpstan-ignore function.notFound */
                        $compressed = @zstd_compress($body);
                        if ($compressed !== false) {
                            $actualEncoding = $contentEncoding;
                            return $compressed;
                        } else {
                            if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
                                $this->logger->warning("Failed compressing message body with zstd_compress");
                            }
                        }
                    }
                    break;
                default:
                    // do nothing
                    if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
                        $this->logger->warning("Unsupported compression scheme for message body: '$contentEncoding'");
                    }
            }
        }

        return false;
    }

    /**
     * @throws RequestBodyCantBeDecompressed
     * @throws ResponseBodyCantBeDecompressed
     * @todo do we need the 2nd arg?
     */
    protected function decompressMessageBody(MessageInterface $message, null|array $contentEncodings = null): string|false
    {
        if ($contentEncodings === null) {
            $contentEncodings = $message->getHeader('Content-encoding');
        }

        /// @todo... verify - is it ever necessary to remove transfer-encoding on our own?
        //$transferEncodings = $message->getHeader('Transfer-Encoding');
        $transferEncodings = [];

        /// @todo implement streaming decompression - see f.e. Guzzle's Psr7\InflateStream
        $stream = $message->getBody();
        $stream->rewind();
        $body = $stream->getContents();

        $body = $this->decompressPayload($body, $contentEncodings, $transferEncodings, $errorMessage);
        if ($body === false) {
            if ($message instanceof RequestInterface) {
                throw new RequestBodyCantBeDecompressed($errorMessage);
            } else {
                throw new ResponseBodyCantBeDecompressed($errorMessage);
            }
        }
        return $body;
    }

    /**
     * @param string[] $contentEncodings
     * @param string[] $transferEncodings you generally should pass in an empty array, as transfer-encoding is taken
     *                                    care of automatically
     * @todo allow streams for $body
     */
    protected function decompressPayload(string $body, array $contentEncodings, array $transferEncodings, string|null &$errorMessage): string|false
    {
        $encodings = array_reverse(array_merge($contentEncodings, $transferEncodings));
        $teCount = count($transferEncodings);

        foreach ($encodings as $i => $encoding) {
            $encoding = strtolower($encoding);
            $errorMessage = null;
            if ($i < $teCount) {
                $type = 'transfer';
            } else {
                $type = 'content';
            }
            switch ($encoding) {
                /// @todo add support for dcb, dcz
                case 'br':
                //case 'dcb':
                //case 'dcz':
                    if (function_exists('brotli_uncompress')) {
                        /** @phpstan-ignore function.notFound */
                        $body = @brotli_uncompress($body);
                        if ($body === false) {
                            $errorMessage = "Failed decompressing " . $encoding . " body: " . (error_get_last()['message']);
                        }
                    } else {
                        $errorMessage = "Unsupported $type-encoding: '$encoding' (missing php function: brotli_uncompress)";
                    }
                    break;
                /// @todo enable this after we finish the UnixCompressor
                /*case 'compress':
                    $body = UnixCompressor::uncompress($body);
                    if ($body === false) {
                        $errorMessage = "Failed decompressing " . $encoding . " body";
                    }
                    break;*/
                /// @todo enable this in case there is need to de-chunk (remove transfer-encoding) on our own
                /*
                case 'chunked':
                    if ($type == 'transfer') {
                        $dechunker = new Dechunker();
                        $body = $dechunker->dechunk($body);
                        if ($body === false) {
                            $errorMessage = "Failed decompressing " . $encoding . " body";
                        }
                    } else {
                        $errorMessage = "Unsupported $type-encoding: '$encoding'";
                    }
                    break;
                */
                case 'deflate':
                    if (function_exists('gzuncompress')) {
                        /** @phpstan-ignore function.notFound */
                        $orig = $body;
                        $body = @gzuncompress($body);
                        if ($body === false) {
/// @todo!!! remove debug code
                            $errorMessage = "Failed decompressing " . $encoding . " body: " . (error_get_last()['message']) .
                                ' length: ' . strlen($orig) . ' bytes: ' . substr($orig, 0, 10) . '...';
                        }
                    } else {
                        $errorMessage = "Unsupported $type-encoding: '$encoding' (missing php function: gzuncompress)";
                    }
                    break;
                case 'gzip':
                    if (function_exists('gzinflate')) {
                        /** @phpstan-ignore function.notFound */
                        $body = @gzinflate(substr($body, 10, -8));
                        if ($body === false) {
                            $errorMessage = "Failed decompressing " . $encoding . " body: " . (error_get_last()['message']);
                        }
                    } else {
                        $errorMessage = "Unsupported $type-encoding: '$encoding' (missing php function: gzinflate)";
                    }
                    break;
                case 'identity':
                    break;
                case 'zstd':
                    if (function_exists('zstd_uncompress')) {
                        /** @phpstan-ignore function.notFound */
                        $body = @zstd_uncompress($body);
                        if ($body === false) {
                            $errorMessage = "Failed decompressing " . $encoding . " body: " . (error_get_last()['message']);
                        }
                    } else {
                        $errorMessage = "Unsupported $type-encoding: '$encoding' (missing php function: zstd_uncompress)";
                    }
                    break;
                default:
                    $errorMessage = "Unsupported $type-encoding: '$encoding'";
            }
            if ($errorMessage !== null) {
                return false;
            }
        }

        return $body;
    }

    protected function supportedCompressionEncodings(): array
    {
        $encodings = ['identity'];
        if (function_exists('brotli_uncompress')) {
            $encodings[] = 'br';
        }
        if (function_exists('gzuncompress')) {
            $encodings[] = 'deflate';
        }
        if (function_exists('gzinflate')) {
            $encodings[] = 'gzip';
        }
        if (function_exists('zstd_uncompress')) {
            $encodings[] = 'zstd';
        }
        return $encodings;
    }

    /**
     * @param string[] $acceptedEncodings
     * @param string[]|null $contentEncodings
     * @throws ResponseBodyCantBeDecompressed
     * @throws ResponseBodyCantBeCompressed
     * @throws UnsupportedMediaType
     * @todo do we need the 3rd argument?
     */
    protected function transcodeResponseBody(ResponseInterface $response, array $acceptedEncodings, null|array $contentEncodings = null): ResponseInterface
    {
        if ($contentEncodings === null) {
            $contentEncodings = $response->getHeader('Content-Encoding');
        }

        if ($acceptedEncodings) {
            $noIdentityEncoding = null;
            $acceptedEncodings = $this->normalizeAcceptEncodings($acceptedEncodings, $noIdentityEncoding);
        } else {
            $noIdentityEncoding = false;
        }

        $mustInflate = false;
        if ($acceptedEncodings && !in_array('*', $acceptedEncodings)) {
            foreach ($contentEncodings as $contentEncoding) {
                if (!in_array(strtolower($contentEncoding), $acceptedEncodings)) {
                    $mustInflate = true;
                    break;
                }
            }
        }

        if (!$mustInflate) {
            return $response;
        }

        $tryEncodings = $this->tryEncodings($response, $acceptedEncodings);

        /// @todo calling tryEncodings() here allows us to bail out early without decompressing the payload. Otoh it would
        ///       be nice to also be able to evaluate the choice of which encodings to use for the payload after having
        ///       decompressed the body (so that eg. its size is known)
        if (!$tryEncodings && $noIdentityEncoding) {
            // throw in a way that allows us to return a 415 response
            throw new UnsupportedMediaType("None of the client's' accepted encodings can be served, and identity encoding has been explicitly forbidden");
        }

        if ($tryEncodings && !$noIdentityEncoding) {
            $tryEncodings[] = 'identity';
        }

        $body = $this->decompressMessageBody($response, $contentEncodings);

        if ($tryEncodings) {
            $body = $this->compressPayload($body, $tryEncodings, $actualEncoding);
            if ($body === false) {
                // throw in a way that allows us to return a 415 response
                throw new ResponseBodyCantBeCompressed("Failed compressing the response using content-encodings: '" . implode("', '", $tryEncodings) . "'");
            } else {
                $response = $response->withBody(Stream::create($body));
                if ($actualEncoding === '' || $actualEncoding === 'identity') {
                    $response = $response->withoutHeader('Content-Encoding');
                } else {
                    $response = $response->withHeader('Content-Encoding', $actualEncoding);
                }
            }
        } else {
            $response = $response
                ->withBody(Stream::create($body))
                ->withoutHeader('Content-Encoding');
        }

        return $response;
    }

    /**
     * Reimplement in subclasses, to eg. avoid compressing bodies below a certain size, of a given type, or if cpu load
     * is high, etc...
     *
     * @param string[] $acceptedEncodings the encodings declared accepted by the request, normalized and sorted by preference.
     *                 As of 15/7/26, this method is never called with an empty list
     * @return string[] the list of compression encodings to try to compress responses with, ordered by preference.
     *                  It should not include 'identity', as that will be tried last anyway, unless forbidden explicitly by the request
     */
    protected function tryEncodings(ResponseInterface $response, array $acceptedEncodings): array
    {
/// @todo... add a blacklist of mimetypes to never try to encode

        $tryEncodings = [];
        $possibleEncodings = $this->supportedCompressionEncodings();
        foreach ($acceptedEncodings as $acceptedEncoding) {
            if (in_array($acceptedEncoding, $possibleEncodings)) {
                $tryEncodings[] = $acceptedEncoding;
            }
        }
        return $tryEncodings;
    }

/// @todo... add protected function transcodeRequestBody(RequestInterface $request, ...): RequestInterface

    /**
     * @param string[] $acceptedEncodings
     * @return string[]
     */
    protected function normalizeAcceptEncodings(array $acceptedEncodings, null|bool &$noIdentityEncoding): array
    {
        $noIdentityEncoding = false;
        $out = [];
        foreach ($acceptedEncodings as $acceptedEncoding) {
            $parts = explode(';', $acceptedEncoding, 2);
            $encoding = strtolower($parts[0]);
            if ($encoding === 'x-gzip' || $encoding === 'x-compress') {
                $encoding = substr($encoding, 2);
            }
            // NB: if the same encoding is listed twice, we use the last weight found. That includes a last weight of 0
            if (isset($parts[1]) && preg_match('/^q=(1(?:\\.0{0,3})?|0(?:\\.[0-9]{0,3})?)$/', $parts[1], $matches)) {
                /// @todo would using a regexp instead of a cast be faster?
                if (($weight = (float)$matches[1]) === 0.0) {
                    if ($encoding === 'identity' || $encoding === '*') {
                        /// @see https://www.rfc-editor.org/info/rfc9110/#section-12.5.3: ...without a more specific entry for "identity"
                        if (array_key_exists('identity', $out)) {
                            continue;
                        }
                        $noIdentityEncoding = true;
                    }
                    if (array_key_exists($encoding, $out)) {
                        unset($out[$encoding]);
                    }
                    continue;
                }
                $out[$encoding] = $weight;
            } else {
                $out[$encoding] = 1;
            }

            if (($encoding === 'identity' || $encoding === '*') && $noIdentityEncoding) {
                $noIdentityEncoding = false;
            }
        }

        if (count($out) > 1) {
            arsort($out, SORT_NUMERIC);
        }
        return array_keys($out);
    }
}
