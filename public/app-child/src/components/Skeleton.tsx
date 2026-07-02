export function Skeleton({ count = 4 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-3">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="glass-panel h-28 animate-pulse rounded-2xl bg-surface-container-low" />
      ))}
    </div>
  );
}
