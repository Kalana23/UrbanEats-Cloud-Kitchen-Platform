<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Root Router / Entrypoint Redirect
 *
 * PHP version 8.x
 */

declare(strict_types=1);

// Seamlessly forward root traffic to the public landing page
header('Location: public/index.php');
exit;
