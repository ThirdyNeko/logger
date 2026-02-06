<?php

$devMap = [
    "Third" => ["192.168.40.14", "::1"],
    "Karl"  => ["192.168.40.239"],
    "Reil"  => ["192.168.40.21"],
    "April" => ["192.168.40.13"],
];

function getUserByIp(string $ip, array $map): string
{
    foreach ($map as $user => $ips) {
        if (in_array($ip, $ips, true)) {
            return $user;
        }
    }
    return 'UNKNOWN';
}

?>
