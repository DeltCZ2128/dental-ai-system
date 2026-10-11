<?php

/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
| The session data is cleared, the session identifier is discarded both in
| the session store and in the browser cookie, and the visitor is returned
| to the login page. Clearing the cookie as well means that a copy of the
| identifier kept by the browser cannot be presented again.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_unset();

session_destroy();

header("Location: login.php");

exit;
