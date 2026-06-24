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
 */
final class ChildPin
{
    private const KEY = 'child_pin:secret';

    /** PIN numérico de 4 a 6 dígitos. */
    private const PATTERN = '/^\d{4,6}$/';

    private readonly SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
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
        return true;
    }

    public function clear(): void
    {
        $this->settings->deleteByKey(self::KEY);
    }

    public function isSet(): bool
    {
        $hash = $this->settings->get(self::KEY);
        return is_string($hash) && $hash !== '';
    }

    /**
     * Confere o PIN. Fail-closed: formato inválido ou nenhum PIN definido
     * devolve false.
     */
    public function verify(string $pin): bool
    {
        if (! self::isValidFormat($pin)) {
            return false;
        }
        $hash = $this->settings->get(self::KEY);
        if (! is_string($hash) || $hash === '') {
            return false;
        }
        return password_verify($pin, $hash);
    }

    private static function isValidFormat(string $pin): bool
    {
        return preg_match(self::PATTERN, $pin) === 1;
    }
}
