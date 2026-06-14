import { useEffect, useRef, useState } from 'react';
import QrScanner from 'qr-scanner';
import { Icon } from './Icon';

type Props = {
  onDetected: (text: string) => void;
  onClose: () => void;
};

export function QrScannerOverlay({ onDetected, onClose }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const scannerRef = useRef<QrScanner | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;

    const scanner = new QrScanner(
      video,
      (result) => {
        onDetected(result.data);
        scanner.stop();
      },
      { preferredCamera: 'environment', highlightScanRegion: true, maxScansPerSecond: 5 },
    );
    scannerRef.current = scanner;

    scanner.start().catch((e: unknown) => {
      setError(
        e instanceof Error
          ? e.message
          : 'Não foi possível acessar a câmera. Verifique a permissão.',
      );
    });

    return () => {
      scanner.stop();
      scanner.destroy();
      scannerRef.current = null;
    };
    // onDetected é estável (vem de useCallback no caller); intencional ignorar.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="qr-scanner-title"
      className="fixed inset-0 z-50 flex flex-col bg-black/90 text-white"
    >
      <div className="flex items-center justify-between p-4">
        <h2 id="qr-scanner-title" className="font-display text-headline-md">
          Escanear QR Code
        </h2>
        <button
          type="button"
          aria-label="Fechar scanner"
          onClick={onClose}
          className="rounded-full p-2 text-white/80 hover:bg-white/10"
        >
          <Icon name="close" />
        </button>
      </div>

      <div className="relative flex flex-1 items-center justify-center overflow-hidden">
        <video
          ref={videoRef}
          className="h-full w-full object-cover"
          playsInline
          muted
        />
        {error && (
          <div className="absolute inset-x-4 top-1/2 -translate-y-1/2 rounded-xl bg-error/20 p-4 text-center">
            <Icon name="videocam_off" className="text-3xl" />
            <p className="mt-2 text-label-md">{error}</p>
            <button
              type="button"
              onClick={onClose}
              className="mt-3 rounded-lg bg-white px-4 py-2 text-label-md font-semibold text-primary"
            >
              Voltar
            </button>
          </div>
        )}
      </div>

      <p className="p-4 text-center text-label-sm text-white/80">
        Aponte a câmera para o QR Code mostrado no painel dos seus responsáveis.
      </p>
    </div>
  );
}
