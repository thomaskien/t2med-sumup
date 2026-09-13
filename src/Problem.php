<?php
declare(strict_types=1);
namespace KienzleSumup;

final class Problem extends \RuntimeException {
    public function __construct(string $message, public readonly int $http = 400) { parent::__construct($message); }
}
