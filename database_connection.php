<?php
/** Shared local/hosted database settings. Never commit production credentials. */
function app_database_connect(): mysqli {
    $production = getenv('APP_ENV') === 'production';
    $localFile = __DIR__.'/database.local.php';
    $local = is_file($localFile) ? require $localFile : [];
    if (!is_array($local)) $local = [];
    $setting = static function(string $name, $fallback) use ($local) {
        $value = getenv($name);
        return $value !== false ? $value : ($local[$name] ?? $fallback);
    };
    $host = $setting('DB_HOST', $production ? '' : 'localhost');
    $user = $setting('DB_USER', $setting('DB_USERNAME', $production ? '' : 'root'));
    $password = $setting('DB_PASSWORD', '');
    $database = $setting('DB_NAME', $setting('DB_DATABASE', $production ? '' : 'procrastitrack'));
    $port = filter_var($setting('DB_PORT', '3306'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
    if (!$port || $host === '' || $user === '' || $database === '' || ($production && $password === '')) {
        http_response_code(503);
        exit('Database settings are incomplete. Please contact the site administrator.');
    }
    try {
        $connection = new mysqli($host, $user, $password, $database, $port);
        $connection->set_charset('utf8mb4');
        $connection->query("SET time_zone = '+08:00'");
        return $connection;
    } catch (mysqli_sql_exception $error) {
        error_log('Database connection failed. Error code: '.$error->getCode());
        http_response_code(503);
        exit('The database is temporarily unavailable. Please try again later.');
    }
}
