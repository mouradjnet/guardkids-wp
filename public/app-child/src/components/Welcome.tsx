import { child } from '../data/mockData';
import { Icon } from './Icon';

export function Welcome() {
  return (
    <section className="flex flex-col items-center justify-center pt-stack-md">
      <div className="relative mb-4">
        <div className="h-24 w-24 overflow-hidden rounded-full border-4 border-surface-container-highest shadow-md">
          <img
            src={child.avatar}
            alt={`${child.name} avatar`}
            className="h-full w-full object-cover"
          />
        </div>
        <div className="absolute -bottom-2 -right-2 rounded-full border-2 border-surface bg-mint-success p-1 text-white shadow-sm">
          <Icon name="verified" className="text-sm" filled />
        </div>
      </div>
      <h2 className="text-center font-display text-headline-lg-mobile text-primary">
        {child.greeting}, {child.name}!
      </h2>
      <p className="mt-1 text-center text-body-md text-on-surface-variant">
        Pronto para se divertir?
      </p>
    </section>
  );
}
