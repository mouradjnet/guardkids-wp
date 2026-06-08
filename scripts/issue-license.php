<?php

/**
 * CLI emissor de licenças GuardKids — uso interno do dev.
 *
 * Este script NÃO faz parte do plugin distribuído. Roda fora do WordPress,
 * lê a privkey Ed25519 do disco do dev e imprime chaves de licença assinadas
 * pra colar no painel do cliente.
 *
 * --- Uso ---
 *
 * # 1. Bootstrap (1x): gera o keypair de produção
 * php scripts/issue-license.php --gen-keys
 * # ↑ cria ~/.guardkids/issuer.key e imprime a pubkey pra colar no Verifier.php
 *
 * # 2. Emitir uma chave pra um cliente
 * php scripts/issue-license.php \
 *   --email=cliente@example.com \
 *   --domain=https://cliente.com \
 *   --expires=2027-12-31
 *
 * # Args opcionais: --plan=premium (default), --features=browser,categories,...,
 * # --key-file=/caminho/alternativo/issuer.key, --jti=<id>
 *
 * Stderr: logs/instruções. Stdout: só a chave (ou só a pubkey em --gen-keys).
 * Exit codes: 0=ok, 1=uso/validação, 2=IO.
 */

declare(strict_types=1);

const ALL_FEATURES = [
    'browser',
    'categories',
    'schedule',
    'reports',
    'location',
    'unlimited_kids',
    'full_history',
];

main($argv);

function main(array $argv): never
{
    if (PHP_SAPI !== 'cli') {
        fail(1, 'Este script só roda via CLI.');
    }
    if (! function_exists('sodium_crypto_sign_keypair')) {
        fail(2, 'A extensão sodium do PHP é obrigatória. Habilite com -d extension=sodium.');
    }

    $opts = getopt('', [
        'gen-keys',
        'force',
        'key-file:',
        'email:',
        'domain:',
        'plan::',
        'expires:',
        'features::',
        'jti::',
        'help',
    ]);

    if (isset($opts['help'])) {
        info(usage());
        exit(0);
    }

    $keyFile = (string) ($opts['key-file'] ?? defaultKeyFilePath());

    if (isset($opts['gen-keys'])) {
        generateKeys($keyFile, isset($opts['force']));
        exit(0);
    }

    issueLicense($keyFile, $opts);
    exit(0);
}

function generateKeys(string $keyFile, bool $force): void
{
    if (file_exists($keyFile) && ! $force) {
        fail(2, "Keypair já existe em {$keyFile}. Use --force pra sobrescrever (cuidado: invalida todas as chaves já emitidas).");
    }
    $dir = dirname($keyFile);
    if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        fail(2, "Não consegui criar diretório {$dir}.");
    }

    $keypair = sodium_crypto_sign_keypair();
    $secret  = sodium_crypto_sign_secretkey($keypair);
    $public  = sodium_crypto_sign_publickey($keypair);

    if (file_put_contents($keyFile, base64_encode($secret), LOCK_EX) === false) {
        fail(2, "Falha ao gravar {$keyFile}.");
    }
    @chmod($keyFile, 0600);

    $pubB64 = base64_encode($public);
    info("Keypair gerada com sucesso.");
    info("Privkey salva em: {$keyFile} (0600 — NÃO commite)");
    info("");
    info("Substitua o placeholder em includes/License/Verifier.php:");
    info("  public const DEFAULT_ISSUER_PUBKEY_B64 = '{$pubB64}';");
    info("");
    info("Pubkey:");
    echo $pubB64, "\n";
}

function issueLicense(string $keyFile, array $opts): void
{
    $email   = trim((string) ($opts['email'] ?? ''));
    $domain  = trim((string) ($opts['domain'] ?? ''));
    $plan    = strtolower(trim((string) ($opts['plan'] ?? 'premium')));
    $expires = trim((string) ($opts['expires'] ?? ''));

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(1, '--email obrigatório e precisa ser válido.');
    }
    if ($domain === '' || ! preg_match('#^https?://#i', $domain)) {
        fail(1, '--domain obrigatório, começando com http:// ou https://.');
    }
    if (! in_array($plan, ['free', 'premium'], true)) {
        fail(1, '--plan precisa ser "free" ou "premium".');
    }
    $exp = strtotime($expires);
    if ($expires === '' || $exp === false || $exp <= time()) {
        fail(1, '--expires obrigatório, formato parsável por strtotime, no futuro (ex: 2027-12-31).');
    }

    $features = ALL_FEATURES;
    if (isset($opts['features']) && $opts['features'] !== '') {
        $features = array_values(array_filter(
            array_map('trim', explode(',', (string) $opts['features'])),
            static fn (string $f): bool => $f !== '',
        ));
        $invalid = array_diff($features, ALL_FEATURES);
        if ($invalid !== []) {
            fail(1, '--features desconhecidas: ' . implode(', ', $invalid) . '. Válidas: ' . implode(', ', ALL_FEATURES));
        }
    }

    if (! is_readable($keyFile)) {
        fail(2, "Privkey não encontrada em {$keyFile}. Rode --gen-keys primeiro.");
    }
    $secret = base64_decode((string) file_get_contents($keyFile), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail(2, "Privkey em {$keyFile} corrompida.");
    }

    $payload = [
        'iss'      => 'guardkids',
        'sub'      => rtrim($domain, '/'),
        'jti'      => trim((string) ($opts['jti'] ?? bin2hex(random_bytes(12)))),
        'iat'      => time(),
        'exp'      => $exp,
        'plan'     => $plan,
        'features' => $features,
        'email'    => $email,
    ];

    $json      = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $b64       = b64url($json);
    $signature = sodium_crypto_sign_detached($b64, $secret);
    $key       = $b64 . '.' . b64url($signature);

    info("Licença emitida ({$plan}) — expira em " . gmdate('Y-m-d', $exp) . " UTC.");
    info("Cliente: {$email} @ {$payload['sub']}");
    info("jti: {$payload['jti']}");
    info("");
    echo $key, "\n";
}

function defaultKeyFilePath(): string
{
    $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
    return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.guardkids' . DIRECTORY_SEPARATOR . 'issuer.key';
}

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function info(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

function fail(int $code, string $msg): never
{
    fwrite(STDERR, "Erro: {$msg}\n");
    exit($code);
}

function usage(): string
{
    $features = implode(', ', ALL_FEATURES);
    return <<<TXT
Uso:
  php scripts/issue-license.php --gen-keys [--force] [--key-file=PATH]
  php scripts/issue-license.php --email=EMAIL --domain=URL --expires=DATA [opções]

Opções:
  --email      E-mail do cliente (vai dentro do payload, não validado online)
  --domain     URL do WP do cliente (https://exemplo.com)
  --expires    Data parsável por strtotime (ex: 2027-12-31, "+1 year")
  --plan       free | premium (default: premium)
  --features   Lista CSV (default: todas) — válidas: {$features}
  --jti        ID único (default: 24 hex random)
  --key-file   Path da privkey (default: ~/.guardkids/issuer.key)
  --force      --gen-keys: sobrescreve keypair existente
  --help       Mostra esta ajuda
TXT;
}
