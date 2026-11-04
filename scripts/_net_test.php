<?php
// Temporary connectivity probe (deleted after use).
function probe(string $remote, int $timeout = 10): string {
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout);
    if (!$fp) return "FAIL ({$errno}) {$errstr}";
    stream_set_timeout($fp, 5);
    $banner = trim((string)fgets($fp, 512));
    fclose($fp);
    return "OK banner=\"{$banner}\"";
}
foreach ([
    'smtp.gmail.com:587' => 'tcp://smtp.gmail.com:587',
    'smtp.gmail.com:465' => 'ssl://smtp.gmail.com:465',
    'smtp-relay.brevo.com:587' => 'tcp://smtp-relay.brevo.com:587',
    'mailpit:1025' => 'tcp://mailpit:1025',
] as $label => $remote) {
    printf("%-28s %s\n", $label, probe($remote));
}
