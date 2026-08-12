#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TanoWAF\WAFCore\Http\HeaderFormat as HF;

/**
 * Generates a json file with the spec for all known http headers, starting from the markdown-formatted file in /docs.
 */

$markdownFile = __DIR__ . '/../doc/http_headers_reference.md';
$jsonFile =  __DIR__ . '/../config/HttpHeadersSpec.json';

$converter = new HTTPHeadersReferenceCoDec($markdownFile, $jsonFile);
$converter->markdownToJson();

class HTTPHeadersReferenceCoDec
{
    const HEADER_COL = 0;
    const REQ_COL = 1;
    const RESP_COL = 2;
    const SINGLETON_COL = 3;
    const LIST_COL = 4;
    const STRUCTUREDFIELD_COL = 5;
    const UNSTRUCTUREDFIELD_COL = 6;
    const DQ_COL = 7;
    const REGEXP_COL = 8;
    const CHARS_COL = 9;
    const HTTPSONLY_COL = 10;
    const IANASTATUS_COL = 11;
    const SOURCE_COL = 12;
    const COMMENTS_COL = 13;

    protected string $inputFile;
    protected string $outputFile;

    public function __construct(string $inputFile, string $outputFile)
    {
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
    }

    public function markdownToJson(): void
    {
        $lines = $this->readInput();
        $data = $this->parseMDInput($lines);
        $out = $this->formatOutputString($data);
        $this->saveOutput($out);
    }

    /**
     * @return string[]
     * @throws Exception
     */
    protected function readInput(): array
    {
        $lines = file($this->inputFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \Exception("Failed reading '{$this->inputFile}'");
        }
        return $lines;
    }

    /**
     * @param string[] $lines
     * @return array[] key: lowercase header name, value: see HeaderSpecFactory::fromConfiguration
     * @throws Exception
     */
    protected function parseMDInput(array $lines): array
    {
        // extract the 1st table from the MD file

        $firstHeader = $lastHeader = 0;
        foreach ($lines as $i => $line) {
            if ($firstHeader > 0) {
                if ($line !== '' && ltrim($line)[0] !== '|') {
                    $lastHeader = $i - 1;
                    break;
                } elseif ($i == count($lines) - 1) {
                    $lastHeader = $i;
                }
            } else {
                // NB: this will bug if there is not at least one line before the 1st table
                if ($line !== '' && ltrim($line)[0] === '|') {
                    // NB: assumes that the table header uses 2 lines
                    $firstHeader = $i +2;
                }
            }
        }
        if ($firstHeader === 0 || $lastHeader === 0) {
            throw new \Exception("Could not find the first markdown table in the " . count($lines) . " lines of the file");
        }
        $lines = array_slice($lines, $firstHeader, $lastHeader - $firstHeader);

        $this->info("Found " . count($lines) . " header definition lines");

        $errors = 0;
        $headers = [];
        foreach ($lines as $i => $line) {

            // de-escape markdown
            $line = str_replace(
                ['\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\<', '\\>', '\\(', '\\)', '\\#', '\\+', '\\-', '\\.', '\\!', '\\|', '\\\\'],
                ['`', '*', '_', '{', '}', '[', ']', '<', '>', '(', ')', '#', '+', '-', '.', '!', '|', '\\'], $line);

            $line = explode('|', trim($line, '|'));

            try {
                $header = strtolower(trim($line[self::HEADER_COL]));

                if ($header === '*') {
                    continue;
                }

                if (!preg_match('/^[a-z0-9-]+$/', $header)) {
                    throw new \Exception("Found invalid header: '{$line[self::HEADER_COL]}' for header nr. $i");
                }
                if (isset($headers[$header])) {
                    throw new \Exception("Found duplicate header: '{$line[self::HEADER_COL]}' on header line nr. $i");
                }

                $spec = [];

                //

                $structuredType = strtolower(trim($line[self::STRUCTUREDFIELD_COL]));
                switch ($structuredType) {
                    case '-':
                        break;
                    case 'dictionary':
                        $spec['format'] = HF::SFDictionary->value;
                        break;
                    case 'item':
                        $spec['format'] = HF::SFItem->value;
                        break;
                    case 'list':
                        $spec['format'] = HF::SFList->value;
                        break;

                    case 'boolean':
                    case 'byte_sequence':
                    case 'date':
                    case 'decimal':
                    case 'display_string':
                    case 'integer':
                    case 'string':
                    case 'token':
                        $spec['format'] = implode('', array_map('ucfirst', explode('_', $structuredType))) . 'Item';
                        break;

                    default:
                        $this->warning("Found invalid structured type: '$structuredType' for header $header");
                        $errors++;
                }

                $format = strtolower(trim($line[self::UNSTRUCTUREDFIELD_COL]));
                switch ($format) {
                    case '-':
                        $format = '';
                        break;
                    case '':
                        $format = HF::Generic->value;
                        break;
                    case '0-9':
                    case 'digit':
                    case 'integer':
                        $format = HF::Integer->value;
                        break;
                    case 'cookie':
                        $format = HF::Cookie->value;
                        break;
                    case 'date':
                        $format = HF::Date->value;
                        break;
                    case 'json':
                    //case 'json-string':
                    //case 'json-string (utf8?)':
                        $format = HF::Json->value;
                        break;
                    case 'token':
                        $format = HF::Token->value;
                        break;
                    default:
                        $this->info("Found non-parsed format definition: '$format' for header $header");
                }

                if ($format !== '') {
                    if (isset($spec['format'])) {
                        $this->warning("Found double format specification for header $header");
                        $errors++;
                    } else {
                        $spec['format'] = $format;
                    }
                }

                //

                $regExp = str_replace('&#124;', '|', trim(trim($line[self::REGEXP_COL]), '`'));
                if ($regExp !== '') {
                    $spec['regex'] = '/' . str_replace('/', '\\/', $regExp) . '/';
                }

                //

                $dqsType = strtolower(trim($line[self::DQ_COL]));
                switch ($dqsType) {
                    case '':
                    case '-':
                        break;
                    case 'none':
/// @todo... do these headers need specific parsing/splitting rules?
                        break;
                    case 'qs':
                    case 'sf':
                        $spec['quoted_string_format'] = $dqsType;
                        break;
                    default:
                        $this->warning("Found invalid double-quoted string type: '$dqsType' for header $header");
                        $errors++;
                }

/// @todo... give warnings if the format is a Structured Field one and $dqsType !== 'sf'

                //

                $isSingleton = strtolower(trim($line[self::SINGLETON_COL]));
                if (!preg_match('/^[yn-]+$/', $isSingleton)) {
                    $this->warning("Found invalid isSingleton: '{$line[self::SINGLETON_COL]}' for header $header");
                    $errors++;
                }
                $isSingleton = ($isSingleton === 'y');
                if ($isSingleton) $spec['singleton'] = true;

                //

/// @todo... can we use the 'is list' column to help the parser in any way?

                //

                $isReq = strtolower(trim($line[self::REQ_COL]));
                if (!preg_match('/^[yn-]+$/', $isReq)) {
                    $this->warning("Found invalid isReq: '{$line[self::REQ_COL]}' for header $header");
                    $errors++;
                }
                $isReq = ($isReq === 'y');
                $isResp = strtolower(trim($line[self::RESP_COL]));
                if (!preg_match('/^[yn-]+$/', $isResp)) {
                    $this->warning("Found invalid isResp: '{$line[self::RESP_COL]}' for header $header");
                    $errors++;
                }
                $isResp = ($isResp === 'y');
                if (!$isReq && !$isResp) {
                    $this->warning("Found neither isReq nor isResp for header $header");
                    $errors++;
                } else {
                    if (!$isReq) $spec['in_request'] = false;
                    if ($isResp) $spec['in_response'] = false;
                }

                $source = trim($line[self::SOURCE_COL]);
                if ($source !== '') {
                    $spec['source'] = $source;
                }

                $comments = trim($line[self::COMMENTS_COL]);
                if ($comments !== '') {
                    $spec['comments'] = $comments;
                }

                //if (!$opts) $opts = ['0'];
                //$headers[$header] = ['opts' => $opts, 'comments' => $comments];

                $headers[$header] = $spec;

            } catch (\Exception $e) {
                $this->warning("Header def error: " . $e->getMessage());
                $errors++;
            }
        }

        if ($errors) {
/// @todo... enable this once we have completed the headers defs markdown table!
//            throw new \Exception("Not saving header definitions to file: found $errors errors");
        }

        return $headers;
    }

    /**
     * @param array[] $lines
     */
    protected function formatOutputString(array $lines): string
    {
        return json_encode($lines, JSON_PRETTY_PRINT);
    }

    protected function saveOutput(string $contents): void
    {
        file_put_contents($this->outputFile, $contents) || throw new \Exception("Failed saving results to '{$this->outputFile}'");
        $this->info("Saved header definitions to '{$this->outputFile}'");
    }

    protected function info(string $message): void
    {
        echo "$message\n";
    }

    protected function warning(string $message): void
    {
        /// @todo move to stderr
        echo "WARNING: $message\n";
    }
}
