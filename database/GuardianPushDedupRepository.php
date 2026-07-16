<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Dedupe dos pushes do guardião, por EVENTO (não por destinatário): o evento
 * aconteceu uma vez, anuncia-se uma vez pra todos os guardiões ativos.
 *
 * Mesma semântica do NotificationRepository::createIfAbsent, sem o feed — o
 * guardião não tem página de alertas; o destino do push é o próprio painel.
 */
final class GuardianPushDedupRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'guardian_push_dedup';
    }

    /**
     * @return bool true se a chave é nova (logo: deve enviar).
     */
    public function createIfAbsent(string $dedupKey): bool
    {
        if ($this->findWhere(['dedup_key' => $dedupKey]) !== []) {
            return false;
        }

        // db->insert direto: Repository::insert grava updated_at, coluna ausente aqui.
        $ok = $this->db->insert($this->table(), [
            'dedup_key'  => $dedupKey,
            'created_at' => current_time('mysql', true),
        ]);

        return $ok !== false;
    }
}
