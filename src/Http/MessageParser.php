<?php

namespace TanoWAF\WAFCore\Http;

use TanoWAF\WAFCore\Exception\InvalidMessage;

/**
 * WIP
 * @internal
 */
class MessageParser
{
    /// @todo accept single \n as line terminators: "Although the line terminator for the start-line and fields is
    ///        the sequence CRLF, a recipient MAY recognize a single LF as a line terminator and ignore any preceding CR"
    //protected bool $acceptLFAsTerminators = false;

    // "In the interest of robustness, a server that is expecting to receive and parse a request-line SHOULD ignore
    // at least one empty line (CRLF) received prior to the request-line."
    protected bool $acceptInitialCRLF = false;

    /***
     * @todo add support for trailer fields
     * @todo optional support for accepting single \n as line terminators
     * @return array startline (string), headerlines (string[]), body (string) NB: the headerlines are not parsed at all
     * @throws InvalidMessage
     */
    public function splitMessage(string $payload): array
    {
        list($offset, $startLineEnd, $headersEnd) = $this->analyzeMessage($payload);

        $startLine = substr($payload, $offset, $startLineEnd - $offset);
        $fields = substr($payload, $startLineEnd + 2, $headersEnd - $startLineEnd - 2);
        $body = substr($payload, $headersEnd + 4);

        return [$startLine, explode("\r\n", $fields), $body];
    }

    /**
     * @return int[] start of startline, end of startline (not incl. crlf), end of headers (not incl. crlfs)
     * @throws InvalidMessage
     */
    protected function analyzeMessage(string $payload): array
    {
        $offset = 0;
        if ($this->acceptInitialCRLF && str_starts_with($payload, "\r\n")) {
            $offset = 2;
        }

        $startLineEnd = strpos($payload, "\r\n", $offset);
        if  ($startLineEnd === false) {
            throw new InvalidMessage("No CRLF found indicating end of start-line");
        }

        $headersEnd = strpos($payload, "\r\n\r\n", $startLineEnd);
        if  ($headersEnd === false) {
            throw new InvalidMessage("No 2xCRLF found indicating end of headers block");
        }

        return [$offset, $startLineEnd, $headersEnd];
    }
}
