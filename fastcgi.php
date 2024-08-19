<?php

function readFully($socket, $len) {
    $data = '';
    while ($len > 0 && !feof($socket)) {
        $chunk = fread($socket, min(8192, $len));
        if ($chunk === false) break;
        $data .= $chunk;
        $len -= strlen($chunk);
    }
    return $data;
}

function parseRequest($socket) {
    // Read the header
    $header = readFully($socket, 8);
    if (strlen($header) != 8) return false;

    list($version, $type, $requestIdB1, $requestIdB0, $contentLengthB1, $contentLengthB0, $paddingLength, $reserved) = unpack("C8", $header);

    // Read the content length and padding
    $contentLength = ($contentLengthB1 << 8) + $contentLengthB0;
    $contentData = readFully($socket, $contentLength);
    $paddingLength = ord(readFully($socket, $paddingLength));

    // Process the request
    switch ($type) {
        case 3: // Begin Request
            // Handle begin request
            break;
        case 6: // Params
            // Handle params
            break;
        case 7: // Stdin
            // Handle stdin
            break;
        case 8: // End Request
            // Handle end request
            break;
        default:
            // Unknown type
            break;
    }

    return true;
}

function handleRequest($socket) {
    $output = "Status: 200 OK\r\n";
    $output .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $output .= "<html><body>Hello, World!</body></html>";

    $responseHeader = pack("C8", 1, 3, 0, 0, 0, 0, 0, 0); // Version 1, Type 3 (End Request), no padding
    $responseBody = pack("N2", 0, 0); // App status and protocol status
    $response = $responseHeader . $responseBody;

    fwrite($socket, $output);
    fwrite($socket, $response);
}

$socket = stream_socket_server('tcp://127.0.0.1:9000', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if (!$socket) {
    echo "$errstr ($errno)\n";
    exit(1);
}

while (true) {
    $read = [$socket];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, 0) == 1) {
        foreach ($read as $ready_socket) {
            if ($ready_socket === $socket) {
                $client = stream_socket_accept($socket);
                parseRequest($client);
                handleRequest($client);
                fclose($client);
            }
        }
    }
}
fclose($socket);
?>
