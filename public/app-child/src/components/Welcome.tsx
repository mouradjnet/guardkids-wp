import type { Child } from '../api/types';
import { Icon } from './Icon';

function greetingFor(hour: number): string {
  if (hour < 12) return 'Bom dia';
  if (hour < 18) return 'Boa tarde';
  return 'Boa noite';
}

const MOTIVATIONAL_LINES = [
  'Você está indo muito bem hoje!',
  'Continue assim — um passo de cada vez.',
  'Hoje é um bom dia pra aprender algo novo.',
  'Pronto pra se divertir?',
  'Lembre-se: pausas também são importantes.',
  'Você é mais forte do que imagina.',
  'Curiosidade é o seu superpoder.',
  'Um pouco de leitura faz bem!',
];

function pickMotivationalFor(child: Child): string {
  // Rotaciona baseado em day-of-year + childId pra cada filho ter sua frase
  // diferente no mesmo dia, e a frase muda todo dia. Determinístico (sem
  // Math.random) pra renders consecutivas serem estáveis.
  const now = new Date();
  const dayOfYear = Math.floor(
    (now.getTime() - new Date(now.getFullYear(), 0, 0).getTime()) / 86_400_000,
  );
  const idx = (dayOfYear + child.id) % MOTIVATIONAL_LINES.length;
  return MOTIVATIONAL_LINES[idx];
}

export function Welcome({ child }: { child: Child }) {
  const greeting = greetingFor(new Date().getHours());
  const motivational = pickMotivationalFor(child);
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
      <p className="mt-1 text-center text-body-md text-on-surface-variant">{motivational}</p>
    </section>
  );
}
