<?php
declare(strict_types=1);

/*
Copyright (c) 2018-present Fabien Potencier

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

namespace TanoWAF\WAFCore\Http;

/**
 * A pure PHP alternative to the native "dechunk" stream filter.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class Dechunker
{
    private const STATE_SIZE = 0;
    private const STATE_SIZE_EXT = 1;
    private const STATE_SIZE_LF = 2;
    private const STATE_DATA = 3;
    private const STATE_DATA_CR = 4;
    private const STATE_DATA_LF = 5;
    private const STATE_TRAILER = 6;

    private int $state = self::STATE_SIZE;
    private string $size = '';
    private int $remaining = 0;

    public function dechunk(string $data): string|false
    {
        $out = '';
        $offset = 0;
        $len = \strlen($data);

        while ($offset < $len) {
            switch ($this->state) {
                case self::STATE_SIZE:
                    if (0 < $spn = strspn($data, '0123456789ABCDEFabcdef', $offset)) {
                        $this->size = ltrim($this->size.substr($data, $offset, $spn), '0') ?: '0';
                        $offset += $spn;

                        if (2 * \PHP_INT_SIZE - 1 < \strlen($this->size)) {
                            //throw new TransportException('Malformed chunked body: chunk size is too big.');
                            return false;
                        }
                        break;
                    }

                    if ('' === $this->size) {
                        //throw new TransportException('Malformed chunked body: invalid chunk size.');
                        return false;
                    }

                    $this->state = self::STATE_SIZE_EXT;
                    // no break
                case self::STATE_SIZE_EXT:
                    if ($len === $offset += strcspn($data, "\r\n", $offset)) {
                        break;
                    }

                    if ("\r" === $data[$offset]) {
                        ++$offset;
                    }

                    $this->state = self::STATE_SIZE_LF;
                    break;

                case self::STATE_SIZE_LF:
                    if ("\n" !== $data[$offset]) {
                        //throw new TransportException('Malformed chunked body: invalid line ending after chunk size.');
                        return false;
                    }

                    ++$offset;
                    $this->state = ($this->remaining = (int) hexdec($this->size)) ? self::STATE_DATA : self::STATE_TRAILER;
                    $this->size = '';
                    break;

                case self::STATE_DATA:
                    $out .= $chunk = substr($data, $offset, $this->remaining);
                    $offset += \strlen($chunk);

                    if (0 === $this->remaining -= \strlen($chunk)) {
                        $this->state = self::STATE_DATA_CR;
                    }
                    break;

                case self::STATE_DATA_CR:
                    if ("\r" === $data[$offset]) {
                        ++$offset;
                    }

                    $this->state = self::STATE_DATA_LF;
                    break;

                case self::STATE_DATA_LF:
                    if ("\n" !== $data[$offset]) {
                        //throw new TransportException('Malformed chunked body: invalid line ending after chunk data.');
                        return false;
                    }

                    ++$offset;
                    $this->state = self::STATE_SIZE;
                    break;

                case self::STATE_TRAILER:
                    // Trailer fields and anything else after the terminal chunk are ignored
                    return $out;
            }
        }

        return $out;
    }
}
