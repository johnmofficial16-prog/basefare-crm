<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    /**
     * Display the login page
     */
    public function showLogin(Request $request, Response $response): Response
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302); // Found
        }

        // Render the Tailwind PHP view we got from Stitch MCP
        ob_start();
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);
        
        require __DIR__ . '/../Views/login.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Process the login form submission
     */
    public function processLogin(Request $request, Response $response): Response
    {
        $parsedBody = $request->getParsedBody();
        $email = $parsedBody['email'] ?? '';
        $password = $parsedBody['password'] ?? '';

        $user = \App\Models\User::where('email', $email)->where('status', 'active')->first();

        // Check against secure Bcrypt password hash
        if ($user && password_verify($password, $user->password_hash)) {
            // Regenerate session ID to prevent fixation payload injections
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user->id;
            $_SESSION['role'] = $user->role;
            $_SESSION['user_name'] = $user->name;
            
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        // Failure
        $_SESSION['login_error'] = 'Invalid email or password.';
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    /**
     * Logout and destroy session
     */
    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
