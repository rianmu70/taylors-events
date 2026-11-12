<?php

declare(strict_types=1);

use App\Core\Container;
use App\Security\TicketSigner;

/**
 * Dependency wiring.
 *
 * Most classes need no entry here: the container reads their constructor and
 * builds what they ask for. Only classes that need a value from configuration
 * are listed.
 */
return static function (Container $container): void {
    $container->bind(TicketSigner::class, static fn (): TicketSigner => TicketSigner::fromConfig());
};
