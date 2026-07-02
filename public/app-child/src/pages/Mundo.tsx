import { CategoryCard } from '../components/CategoryCard';
import { EmptyState } from '../components/EmptyState';

const sections = [
  { icon: 'sports_esports', name: 'Jogos', description: 'Jogos seguros pra se divertir' },
  { icon: 'school', name: 'Aprender', description: 'Conteúdos educativos' },
  { icon: 'palette', name: 'Criar', description: 'Solte a imaginação' },
  { icon: 'emoji_events', name: 'Desafios', description: 'Missões pra completar' },
  { icon: 'favorite', name: 'Favoritos', description: 'O que você mais gosta' },
  { icon: 'recommend', name: 'Indicados pelos Pais', description: 'Escolhidos pra você' },
  { icon: 'military_tech', name: 'Conquistas', description: 'Suas medalhas' },
];

export function Mundo() {
  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <div className="grid grid-cols-2 gap-3">
        {sections.map((s) => (
          <CategoryCard key={s.name} icon={s.icon} name={s.name} description={s.description} count={0} />
        ))}
      </div>
      <EmptyState icon="public" message="Seu mundo será preenchido pelo papai." />
    </main>
  );
}
