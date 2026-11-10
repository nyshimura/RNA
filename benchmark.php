<?php
try {
    $pdo = new PDO('mysql:host=srv1063.hstgr.io;dbname=u289919376_rna;charset=utf8mb4', 'u289919376_rna', 'querocomeraJoanJet!26', [PDO::ATTR_TIMEOUT => 4]);
    echo "SUCESSO REMOTO A PARTIR DO XAMPP LOCAL!\n";
} catch (Throwable $e) {
    echo "AVISO REMOTO: " . $e->getMessage() . "\n";
}

