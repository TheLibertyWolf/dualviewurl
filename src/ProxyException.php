<?php
declare(strict_types=1);

namespace Duoviewurl;

final class ProxyException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}
