<?php
// Template de configuracao de banco de dados (100% Local / Self-Hosted)
if (basename($_SERVER['PHP_SELF'] ?? '') === 'db_config.example.php') {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'db_host' => 'localhost',
    'db_fallback_host' => null,
    'db_name' => 'rna_mesh',
    'db_user' => 'root',
    'db_pass' => '',
    'default_hf_token' => ''
];

