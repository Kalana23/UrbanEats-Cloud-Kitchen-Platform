<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Legacy menu redirect to Component B menu list
 */

declare(strict_types=1);

$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: menu/list.php' . $queryString, true, 301);
exit();
