<?php
function is_localhost(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $host === 'localhost' || str_starts_with($host, 'localhost:');
}

if (!is_localhost()) {
    require __DIR__ . "/../login/lib.php";

    if (
        !array_any($allowedUsers, function ($email) {
            return $email == $_SESSION['user']['email'];
        })
    ) {
        require __DIR__ . "/../login/403.php";
        die();
    }
}