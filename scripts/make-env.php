<?php

// Usage: php make_env.php <path-to-.env.example> <path-to-.env>
[$_, $src, $dst] = $argv;
$env = file_get_contents($src);
$rand = fn (int $n) => bin2hex(random_bytes($n));
$set = function (string $key, string $value) use (&$env) {
    $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $env);
};
$set('APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
$set('REVERB_APP_ID', (string) random_int(100000, 999999));
$set('REVERB_APP_KEY', $rand(10));
$set('REVERB_APP_SECRET', $rand(20));
$set('OTP_HASH_KEY', $rand(32));
$set('PAYMENT_WEBHOOK_SECRET', $rand(24));
file_put_contents($dst, $env);
echo "wrote $dst\n";
