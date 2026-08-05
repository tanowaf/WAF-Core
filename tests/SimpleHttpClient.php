<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

/// @todo feature creep: turn this into a Psr-18 client implementing UpstreamClientInterface
class SimpleHttpClient
{
    protected array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options + [
            'bindto' => null,
            'connect_timeout' => 0,
            'timeout' => 0,
            'extrasockopts' => [],
        ];
    }

    /**
     * @todo... use a better API to separate/handle $targetAddress vs $method vs. $transport
     * @param string $targetAddress what to connect to, eg. 'https://
     * @param string $payload the full request payload, including the 1st line
     * @param array $options supported: 'timeout' (int), 'extrasockopts' (array), 'connect_timeout' (int), 'bindto' (string)
     * @return string the full response payload, including the 1st line. No decoding done of any http header
     * @throws \RuntimeException
     */
    public function sendPayload(string $targetAddress, string $payload, array $options = []): string
    {
        $options = $options + $this->options;

        $contextOptions = array();

        /// @todo add support for setting up ssl context options
        //if ($method == 'https') {
        //    ...
        //}

        foreach ($options['extrasockopts'] as $proto => $protoOpts) {
            foreach ($protoOpts as $key => $val) {
                $contextOptions[$proto][$key] = $val;
            }
        }

        $context = stream_context_create($contextOptions);

        if ($options['connect_timeout'] > 0) {
            $connectTimeout = $options['connect_timeout'];
        } elseif ($options['timeout'] > 0) {
            $connectTimeout = $options['timeout'];
        } else {
            $connectTimeout = ini_get('default_socket_timeout');
        }

        if ($options['bindto'] !== null) {
            $targetAddress = 'unix://' . $options['bindto'];
        }

        $fp = @stream_socket_client($targetAddress, $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            /// @todo use a more specific exception
            throw new \RuntimeException("Error $errno connecting to '$targetAddress': $errstr");
        }

        if ($options['timeout'] > 0) {
            /// @todo if $options['timeout'] is float, use its fractional part as 3rd arg
            stream_set_timeout($fp, (int)$options['timeout'], 0);
        }

        if (!fputs($fp, $payload, strlen($payload))) {
            throw new \RuntimeException("Error while writing the request");
        }

        $info = stream_get_meta_data($fp);
        if ($info['timed_out']) {
            fclose($fp);
            /// @todo use a more specific exception
            throw new \RuntimeException("Timeout while writing the request");
        }

        $response = '';
        do {
            // shall we check for $data === FALSE? as per the manual, it signals an error
            $response .= fread($fp, 32768);

            $info = stream_get_meta_data($fp);
            /// @todo check the elapsed time, as fread will only signal a single-packet timeout, and reverse-slowloris
            ///       servers will defeat this
            if ($info['timed_out']) {
                fclose($fp);
                /// @todo use a more specific exception
                throw new \RuntimeException("Timeout while reading the response");
            }

        } while (!feof($fp));
        fclose($fp);

        return $response;
    }
}
