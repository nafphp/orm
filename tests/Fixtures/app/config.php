<?php

declare(strict_types=1);

$driver = getenv('DB_DRIVER') ?: 'sqlite';

if ($driver === 'mysql') {
    return [
        'database' => [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'nixphp_orm_test',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ],
    ];
}

return [
    'database' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ],
];
