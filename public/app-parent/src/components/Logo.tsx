type LogoProps = { size?: number };

export function Logo({ size = 40 }: LogoProps) {
  return (
    <div
      className="flex items-center justify-center rounded-xl bg-primary text-white shadow-ambient"
      style={{ width: size, height: size }}
      aria-label="GuardKids WP"
    >
      <svg
        viewBox="0 0 24 24"
        width={size * 0.6}
        height={size * 0.6}
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        <path d="M9 12l2 2 4-4" />
      </svg>
    </div>
  );
}
