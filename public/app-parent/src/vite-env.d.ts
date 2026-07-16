/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_WP_USER?: string;
  readonly VITE_WP_APP_PASSWORD?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

interface Window {
  guardkidsApi?: {
    nonce: string;
    root: string;
    logoutUrl?: string;
    /** URL do service worker no dist/, injetada pelo ParentApp. Ver lib/push.ts. */
    swUrl?: string;
  };
}
