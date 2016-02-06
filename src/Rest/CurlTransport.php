<?php

/**
 * Copyright (c) 2016 NSONE, Inc
 * Licensed under The MIT License (MIT). See LICENSE in project root
 *
 */

// attemt to find a solution for the error suppression

namespace NSONE\Rest;

use NSONE\Rest\Transport;
use NSONE\Rest\TransportException;

/**
 * an implementation of a transport using CURL
 */
class CurlTransport extends Transport
{

    /**
     * read buffer
     * @var string
     */
    protected $readBuf;

    /**
     * receive callback function used by curl
     * @param resource $cH curl handle
     * @param string $data data received
     * @return int length of data received
     */
    protected function recv($cH, $data)
    {
        $this->readBuf .= $data;
        return strlen($data);
    }

    public function send($verb, $url, $body, $options)
    {

        $this->readBuf = '';
        $curl = curl_init($url);
        if (empty($curl)) {
            throw new TransportException("unable to initialize cURL");
        }

        // XXX leaks curl handle on exception?

        curl_setopt($curl, CURLOPT_WRITEFUNCTION, array($this, 'recv'));

        if (isset($options['timeout'])) {
            curl_setopt($curl, CURLOPT_TIMEOUT, $options['timeout']);
        }

        if (@$options['ignore-ssl-errors']) { //check for alternatives
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }

        if (!empty($body) && is_array($body)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
            $options['headers'][] = 'Content-Type: application/json';
        }

        if (!empty($options['headers'])) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $options['headers']);
        }


        if (@$this->config['verbosity'] > 2) {
            curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        }

        switch ($verb) {
            case 'GET':
            case 'PUT':
            case 'POST':
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $verb);
                break;
            default:
                throw new TransportException("unhandled cURL verb: $verb");
        }

        curl_exec($curl);
        $out = $this->readBuf;

        $this->resultCode = $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (@$this->config['verbosity'] > 2) { 
            $data = curl_getinfo($curl, CURLINFO_HEADER_OUT);
            $data = preg_replace('/X-NSONE-Key: (.+)$/m', 'X-NSONE-Key: <redacted>', $data); // not ideal
            echo "---------------------------request start-------------------------\n";
            echo "WRITE: [$data]\n";
            if ($body) {
                echo "BODY: [".json_encode($body)."]\n";
            }
            echo "READ : [$this->readBuf]\n";
            echo "-------------------------request end-----------------------\n";
        }

        $error = curl_error($curl);
        curl_close($curl);

        if (empty($out)) {
            throw new TransportException("unable to connect, no response, or timeout: ".
                                         $fullURL." CURL Error: " . $error, $code);
        }

        $jsonOut = json_decode(trim($out), true);
        if ($jsonOut === NULL) {
            $e = new TransportException("invalid JSON response: ".$out, $code);
            $e->rawResult = $out;
            throw $e;
        }

        if ($this->resultCode != 200) {
            if (isset($jsonOut['message'])) {
                $out = $jsonOut['message'];
            }
            $e = new TransportException("request failed: ".$out, $this->resultCode);
            $e->rawResult = $out;
            throw $e;
        }

        return $jsonOut;

    }

}

?>
