<?php

declare(strict_types=1);

namespace GuardKids\Auth;

use GuardKids\Database\SettingsRepository;

/**
 * PIN dos pais pra destravar o ambiente seguro no aparelho da criança
 * (ex.: liberar a tela de bloqueio de bedtime/limite por uns minutos).
 *
 * O PIN nunca é guardado em claro: persistimos `password_hash()` na chave
 * interna `child_pin:secret`. Como a chave contém `:`, o SettingsController
 * a trata como não-pública e jamais a expõe/aceita via REST público — mesmo
 * padrão de defesa em profundidade do {@see ChildAuth}.
 *
 * Só existe um PIN por conta (a UI dos pais é a nível de conta, não por filho).
 *
 * Anti força bruta: o contador de erros é GLOBAL da conta (não por aparelho —
 * senão N aparelhos dividiriam o tempo por N). A cada MAX_FAILURES erros o PIN
 * trava por LOCK_SECONDS, dobrando a cada nova rodada até MAX_LOCK_SECONDS.
 * Acerto, troca ou remoção do PIN zeram o contador.
 */
final class ChildPin
{
    private const KEY          = 'child_pin:secret';
    private const ATTEMPTS_KEY = 'child_pin:attempts';

    /** PIN numérico de 4 a 6 dígitos. */
    private const PATTERN = '/^\d{4,6}$/';

    public const MAX_FAILURES     = 5;
    public const LOCK_SECONDS     = 900;    // 15 min na 1ª rodada
    public const MAX_LOCK_SECONDS = 86400;  // teto de 24 h

    private readonly SettingsRepository $settings;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    public function __construct(?SettingsRepository $settings = null, ?\Closure $now = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
        $this->now      = $now ?? static fn (): int => time();
    }

    /** Segundos até destravar (0 = liberado). */
    public function lockedSeconds(): int
    {
        $until = (int) ($this->attempts()['lockedUntil'] ?? 0);
        return max(0, $until - ($this->now)());
    }

    /**
     * Valida o formato e persiste o hash. Devolve false se o PIN não for
     * 4–6 dígitos (caller transforma em 422).
     */
    public function set(string $pin): bool
    {
        if (! self::isValidFormat($pin)) {
            return false;
        }
        $this->settings->set(self::KEY, password_hash($pin, PASSWORD_DEFAULT));
        $this->settings->deleteByKey(self::ATTEMPTS_KEY);
        return true;
    }

    public function clear(): void
    {
        $this->settings->deleteByKey(self::KEY);
        $this->settings->deleteByKey(self::ATTEMPTS_KEY);
    }

    public function isSet(): bool
    {
        $hash = $this->settings->get(self::KEY);
        return is_string($hash) && $hash !== '';
    }

    /**
     * Confere o PIN. Fail-closed: travado, formato inválido ou nenhum PIN
     * definido devolve false. Erro conta para o bloqueio; acerto zera.
     */
    public function verify(string $pin): bool
    {
        if ($this->lockedSeconds() > 0 || ! self::isValidFormat($pin)) {
            return false;
        }
        $hash = $this->settings->get(self::KEY);
        if (! is_string($hash) || $hash === '') {
            return false;
        }
        if (password_verify($pin, $hash)) {
            $this->settings->deleteByKey(self::ATTEMPTS_KEY);
            return true;
        }
        $this->recordFailure();
        return false;
    }

    private function recordFailure(): void
    {
        $failures = (int) ($this->attempts()['failures'] ?? 0) + 1;
        $lockedUntil = 0;
        if ($failures % self::MAX_FAILURES === 0) {
            $round = intdiv($failures, self::MAX_FAILURES);
            $lock  = min(self::MAX_LOCK_SECONDS, self::LOCK_SECONDS * (2 ** min($round - 1, 10)));
            $lockedUntil = ($this->now)() + $lock;
        }
        $this->settings->set(self::ATTEMPTS_KEY, [
            'failures'    => $failures,
            'lockedUntil' => $lockedUntil,
        ]);
    }

    /** @return array{failures?: int, lockedUntil?: int} */
    private function attempts(): array
    {
        $raw = $this->settings->get(self::ATTEMPTS_KEY);
        return is_array($raw) ? $raw : [];
    }

    private static function isValidFormat(string $pin): bool
    {
        return preg_match(self::PATTERN, $pin) === 1;
    }
}
