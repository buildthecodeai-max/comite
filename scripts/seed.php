<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\DataRepository;
use CommitteeManager\Seeder;

Seeder::run();

$data = (new DataRepository())->loadAll();
echo "Seed complete.\n";
echo 'Members: ' . count($data['members']) . "\n";
echo 'Admin user: ' . $data['adminUser'] . " (password: admin123)\n";
echo "Member PIN defaults to member ID (e.g. member 1 PIN = 1)\n";
