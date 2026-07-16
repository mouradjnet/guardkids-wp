<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

use GuardKids\Database\ChildRepository;
use GuardKids\Database\GuardianPushDedupRepository;
use GuardKids\Notifications\WebPush\PushSender;

/**
 * Funil único das notificações do guardião. Espelha o Notifier (que serve a
 * criança), com duas diferenças deliberadas:
 *
 * - dedupa por EVENTO, não por destinatário — o evento aconteceu uma vez,
 *   anuncia-se uma vez pra todos os guardiões ativos;
 * - não persiste feed: o guardião não tem página de alertas, o destino do push
 *   é /painel-pais, que já mostra o que há pra decidir.
 */
final class GuardianNotifier
{
    private readonly GuardianPushDedupRepository $dedup;
    private readonly ChildRepository $children;
    private readonly PushSender $pushSender;

    public function __construct(
        ?GuardianPushDedupRepository $dedup = null,
        ?ChildRepository $children = null,
        ?PushSender $pushSender = null
    ) {
        $this->dedup      = $dedup ?? new GuardianPushDedupRepository();
        $this->children   = $children ?? new ChildRepository();
        $this->pushSender = $pushSender ?? new PushSender();
    }

    private function emit(string $dedupKey, string $title, string $body): void
    {
        if ($this->dedup->createIfAbsent($dedupKey)) {
            $this->pushSender->sendToGuardians($title, $body);
        }
    }

    /** Notificação sem nome ainda é útil; push que explode por causa de cópia, não. */
    private function childName(int $childId): string
    {
        $row  = $this->children->findById($childId);
        $name = trim((string) ($row['name'] ?? ''));

        return $name !== '' ? $name : 'Seu filho';
    }

    /**
     * @param array<string, mixed> $request linha de wp_guardkids_requests
     */
    public function notifyRequestCreated(array $request): void
    {
        $childId = (int) ($request['child_id'] ?? 0);
        $id      = (int) ($request['id'] ?? 0);
        if ($childId === 0 || $id === 0) {
            return;
        }

        // description + highlight, mesma composição do Notifier::notifyRequestDecided.
        $label = trim(
            ((string) ($request['description'] ?? '')) . ' ' . ((string) ($request['highlight'] ?? ''))
        );

        $this->emit(
            'req:' . $id,
            $this->childName($childId) . ' pediu acesso',
            $label !== '' ? $label : 'Toque para decidir.',
        );
    }

    public function notifyLimitReached(int $childId): void
    {
        if ($childId === 0) {
            return;
        }

        $this->emit(
            'lim:' . $childId . ':' . gmdate('Y-m-d'),
            $this->childName($childId) . ' esgotou o tempo de tela',
            'O limite diário de hoje acabou.',
        );
    }

    public function notifyBlockedAttempt(int $childId, string $detail): void
    {
        if ($childId === 0) {
            return;
        }

        $when = ['bedtime' => 'na hora de dormir', 'weekday' => 'em dia bloqueado'][$detail] ?? 'fora do horário';

        $this->emit(
            'blk:' . $childId . ':' . $detail . ':' . gmdate('Y-m-d'),
            $this->childName($childId) . ' tentou acessar ' . $when,
            'O acesso foi bloqueado pelas regras.',
        );
    }

}
