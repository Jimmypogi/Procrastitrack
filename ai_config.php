<?php
// Store secrets in the web server environment, never in the repository or browser.
// For XAMPP, use the ignored config/gemini.local.php file. Keep the earlier filename compatible.
$readAiSetting = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') return trim($value);
    // Some Apache/PHP-FPM hosting panels expose variables through these superglobals.
    foreach ([$_SERVER, $_ENV] as $variables) {
        if (isset($variables[$name]) && is_string($variables[$name]) && trim($variables[$name]) !== '') {
            return trim($variables[$name]);
        }
    }
    return $default;
};
$localKeyFile = __DIR__ . '/config/gemini.local.php';
if (is_file($localKeyFile)) require_once $localKeyFile;
if (!defined('GEMINI_API_KEY') && is_file(__DIR__ . '/config/openai.local.php')) require_once __DIR__ . '/config/openai.local.php';
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', $readAiSetting('GEMINI_API_KEY'));
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', $readAiSetting('GEMINI_MODEL', 'gemini-3.5-flash-lite'));
unset($readAiSetting);
