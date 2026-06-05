type IconProps = {
  name: string;
  className?: string;
  filled?: boolean;
};

export function Icon({ name, className = '', filled = false }: IconProps) {
  const style = filled
    ? { fontVariationSettings: "'FILL' 1, 'wght' 500" }
    : undefined;
  return (
    <span
      className={`material-symbols-outlined ${className}`}
      aria-hidden="true"
      style={style}
    >
      {name}
    </span>
  );
}
