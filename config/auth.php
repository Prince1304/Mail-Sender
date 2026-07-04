<?php
declare(strict_types=1);

const APP_LOGIN_PASSCODE = '1221';
const APP_SESSION_KEY = 'bulk_mail_sender_logged_in';
const APP_SESSION_NAME = 'bulk_mail_sender_sid';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(APP_SESSION_NAME);
    session_start();
}

function isAppAuthenticated(): bool
{
    return !empty($_SESSION[APP_SESSION_KEY]);
}

function loginAppUser(): void
{
    session_regenerate_id(true);
    $_SESSION[APP_SESSION_KEY] = true;
}

function logoutAppUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}
