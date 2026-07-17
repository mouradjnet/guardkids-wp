<?php

declare(strict_types=1);

/**
 * Migration 025 — normaliza domínios já gravados em `sites`.
 *
 * O invariante da tabela sempre foi "host limpo" (é o que o Companion Android
 * compara ao bloquear). O `allowDomain()` respeitava; o create manual da tela
 * Sites & Regras, não — então o banco acumulou linhas como "https://youtube.com",
 * que o bloqueio por host nunca casaria. O código já foi corrigido; isto limpa
 * o passivo.
 *
 * Normaliza em PHP (e não em SQL) pra usar exatamente a mesma função do
 * runtime — duas implementações da mesma regra divergem com o tempo.
 *
 * **NÃO apaga nada.** Uma versão anterior desta migração deduplicava por
 * (domain, list_type) e destruía regra legítima: `khanacademy.org` com
 * applies_to=[] (todos) e applies_to=[1] (só o Lucas) são regras DIFERENTES,
 * não duplicatas. Migração que apaga dado do cliente precisa de motivo muito
 * melhor do que "ficou parecido" — e o achado que originou esta aqui era só
 * sobre normalização.
 *
 * Se a normalização gerar duas linhas idênticas de verdade, elas ficam: o
 * efeito é um atalho repetido na tela da criança, o que é feio e reversível —
 * ao contrário de apagar.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_sites';

    $rows = $wpdb->get_results("SELECT id, domain FROM {$table}", ARRAY_A);
    if (! is_array($rows)) {
        return;
    }

    foreach ($rows as $row) {
        $atual = (string) ($row['domain'] ?? '');
        $limpo = \GuardKids\Database\SiteRepository::normalizeDomain($atual);

        if ($limpo === '' || $limpo === $atual) {
            // vazio: linha que já era inútil, mas apagar é decisão de produto —
            // fica como está. Igual: nada a fazer.
            continue;
        }

        $wpdb->update($table, ['domain' => $limpo], ['id' => (int) $row['id']]);
    }
};
