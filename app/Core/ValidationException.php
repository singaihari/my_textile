<?php
declare(strict_types=1);

namespace App\Core;

/** Business-rule or input validation failure. Message is safe to show to the user. */
class ValidationException extends HttpException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct(422, $message, $errors);
    }
}
