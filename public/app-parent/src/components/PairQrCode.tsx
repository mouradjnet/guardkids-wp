import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';

type Props = { value: string; size?: number };

export function PairQrCode({ value, size = 200 }: Props) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!canvasRef.current) return;
    QRCode.toCanvas(canvasRef.current, value, {
      width: size,
      margin: 1,
      errorCorrectionLevel: 'M',
      color: { dark: '#1d4ed8', light: '#ffffff' },
    }).catch((e: unknown) => {
      setError(e instanceof Error ? e.message : 'Falha ao gerar QR Code');
    });
  }, [value, size]);

  if (error) {
    return (
      <p role="alert" className="text-label-sm text-error">
        {error}
      </p>
    );
  }

  return (
    <canvas
      ref={canvasRef}
      width={size}
      height={size}
      aria-label="QR Code de pareamento"
      className="rounded-lg bg-white p-2 shadow-sm"
    />
  );
}
