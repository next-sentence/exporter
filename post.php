<?php

include './vendor/autoload.php';

try {
    $env = parse_ini_file('.env', false, INI_SCANNER_RAW);

    $utils = new \App\Utils(
        new \App\DbConfig($env['DB_NAME'],$env['DB_HOST'], $env['DB_USER'], $env['DB_PASSWORD']),
        new \WPAPI($env['WP_HOST'], $env['WP_USER'], $env['WP_PASSWORD'])
    );

    $stmt = $utils->getDb()->getConnection()->prepare("SELECT * FROM migrations_posts WHERE status != :status order by id asc ");
    $stmt->execute(['status' => 'done']);

    $start = time();

    while ($row = $stmt->fetch(\PDO::FETCH_OBJ)){
        $utils->addPost($row);
    }

    $time = time() - $start;
    echo $time.' seconds.'. PHP_EOL;

} catch (\Exception $e) {
    echo date("Y-m-d H:i:s") . '-> ' . $e->getMessage() . PHP_EOL;
}

