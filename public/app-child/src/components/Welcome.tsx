import type { Child } from '../api/types';
import { Icon } from './Icon';

function greetingFor(hour: number): string {
  if (hour < 12) return 'Bom dia';
  if (hour < 18) return 'Boa tarde';
  return 'Boa noite';
}

export function Welcome({ child }: { child: Child }) {
  const greeting = greetingFor(new Date().getHours());
  return (
    <section className="flex flex-col items-center justify-center pt-stack-md">
      <div className="relative mb-4">
        <div className="h-24 w-24 overflow-hidden rounded-full border-4 border-surface-container-highest shadow-md">
          {child.avatarUrl ? (
            <img
              src={child.avatarUrl}
              alt={`${child.name} avatar`}
              className="h-full w-full object-cover"
            />
          ) : (
            <div className="flex h-full w-full items-center justify-center bg-primary-container text-on-primary-container font-display text-4xl font-bold">
              {child.name.charAt(0).toUpperCase()}
            </div>
          )}
        </div>
        <div className="absolute -bottom-2 -right-2 rounded-full border-2 border-surface bg-mint-success p-1 text-white shadow-sm">
          <Icon name="verified" className="text-sm" filled />
        </div>
      </div>
      <h2 className="text-center font-display text-headline-lg-mobile text-primary">
        {greeting}, {child.name}!
      </h2>
      <p className="mt-1 text-center text-body-md text-on-surface-variant">
        Pronto para se divertir?
      </p>
    </section>
  );
}
