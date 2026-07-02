<?php

declare(strict_types=1);

namespace GuardKids\Notifications\WebPush;

final class VapidKeys
{
    private const OPT_PUBLIC  = 'guardkids_vapid_public';
    private const OPT_PRIVATE = 'guardkids_vapid_private';

    public function publicKey(): string
    {
        $this->ensure();
        return (string) get_option(self::OPT_PUBLIC, '');
    }

    public function privateRaw(): string
    {
        $this->ensure();
        return Base64Url::decode((string) get_option(self::OPT_PRIVATE, ''));
    }

    public function publicRaw(): string
    {
        return Base64Url::decode($this->publicKey());
    }

    private function ensure(): void
    {
        if (get_option(self::OPT_PUBLIC, '') !== '') {
            return;
        }
        $k = EcKeys::generate();
        update_option(self::OPT_PUBLIC, Base64Url::encode($k['public']), false);
        update_option(self::OPT_PRIVATE, Base64Url::encode($k['privateRaw']), false);
    }
}
