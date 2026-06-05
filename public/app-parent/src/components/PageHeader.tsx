import type { ReactNode } from 'react';

type PageHeaderProps = {
  title: string;
  subtitle?: string;
  action?: ReactNode;
};

export function PageHeader({ title, subtitle, action }: PageHeaderProps) {
  return (
    <header className="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
      <div>
        <h1 className="font-display text-headline-lg-mobile text-primary md:text-headline-lg">
          {title}
        </h1>
        {subtitle && (
          <p className="mt-1 text-body-md text-on-surface-variant">{subtitle}</p>
        )}
      </div>
      {action}
    </header>
  );
}
