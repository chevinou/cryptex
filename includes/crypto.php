<?php
// ============================================================
//  CRYPTEX — Chiffrement AES-256-GCM
// ============================================================

function encryptSecret(string $plaintext): array {
    $key   = deriveKey();
    $nonce = random_bytes(12);   // 96 bits pour GCM

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Échec du chiffrement.');
    }

    return [
        'content_encrypted' => base64_encode($ciphertext . $tag),
        'nonce'             => base64_encode($nonce),
    ];
}

function decryptSecret(string $encryptedB64, string $nonceB64): string {
    $key    = deriveKey();
    $raw    = base64_decode($encryptedB64);
    $nonce  = base64_decode($nonceB64);

    // Les 16 derniers bytes sont le tag GCM
    $tag        = substr($raw, -16);
    $ciphertext = substr($raw, 0, -16);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($plaintext === false) {
        throw new RuntimeException('Déchiffrement impossible — données corrompues ou clé invalide.');
    }

    return $plaintext;
}

function deriveKey(): string {
    // HKDF-like dérivation depuis la constante de config
    return hash('sha256', ENCRYPTION_KEY . 'cryptex-v1', true);
}

// ── Chiffrement de fichiers ────────────────────────────────────

function encryptAndStoreFile(string $tmpPath, string $originalName): array {
    $key   = deriveKey();
    $nonce = random_bytes(12);

    $plaintext = file_get_contents($tmpPath);
    if ($plaintext === false) {
        throw new RuntimeException("Impossible de lire le fichier « {$originalName} ».");
    }

    $ciphertext = openssl_encrypt(
        $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16
    );
    if ($ciphertext === false) {
        throw new RuntimeException("Échec du chiffrement du fichier « {$originalName} ».");
    }

    if (!is_dir(FILE_STORAGE_PATH)) {
        mkdir(FILE_STORAGE_PATH, 0750, true);
    }

    $storedName = bin2hex(random_bytes(16)) . '.enc';
    $storedPath = FILE_STORAGE_PATH . '/' . $storedName;
    file_put_contents($storedPath, $ciphertext . $tag);

    return [
        'stored_name' => $storedName,
        'nonce'       => base64_encode($nonce),
        'file_size'   => strlen($plaintext),
    ];
}

function decryptStoredFile(string $storedName, string $nonceB64): string {
    $key        = deriveKey();
    $storedPath = FILE_STORAGE_PATH . '/' . basename($storedName);

    if (!file_exists($storedPath)) {
        throw new RuntimeException('Fichier chiffré introuvable.');
    }

    $raw   = file_get_contents($storedPath);
    $nonce = base64_decode($nonceB64);
    $tag   = substr($raw, -16);
    $ciphertext = substr($raw, 0, -16);

    $plaintext = openssl_decrypt(
        $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag
    );
    if ($plaintext === false) {
        throw new RuntimeException('Déchiffrement du fichier impossible — données corrompues.');
    }

    return $plaintext;
}

function deleteStoredFile(string $storedName): void {
    $path = FILE_STORAGE_PATH . '/' . basename($storedName);
    if (file_exists($path)) {
        unlink($path);
    }
}
