import { useQuery } from '@tanstack/react-query';
import { getContentSummary } from '../api/content';

function Metric({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
      <div className="text-3xl font-bold text-primary">{value}</div>
      <div className="text-label-md text-on-surface-variant">{label}</div>
    </div>
  );
}

export function ContentDashboard() {
  const query = useQuery({ queryKey: ['content', 'summary'], queryFn: getContentSummary });
  const s = query.data;

  return (
    <main className="flex-1 space-y-6 p-6">
      <div>
        <h1 className="font-display text-headline-lg text-on-background">Conteúdo Infantil</h1>
        <p className="text-body-md text-on-surface-variant">
          O Mundo Guardião do seu filho. Cadastre conteúdos seguros para ele explorar.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <Metric label="Conteúdos" value={s?.contents ?? 0} />
        <Metric label="Categorias" value={s?.categories ?? 0} />
        <Metric label="Favoritos" value={s?.favorites ?? 0} />
        <Metric label="Recomendações" value={s?.recommendations ?? 0} />
      </div>

      <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
        <span className="text-label-md text-on-surface-variant">Última sincronização: </span>
        <span className="font-semibold text-on-surface">{s?.lastSync ?? 'Nunca'}</span>
      </div>

      <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-outline-variant p-10 text-center">
        <span className="material-symbols-outlined text-5xl text-outline">inventory_2</span>
        <p className="text-label-lg font-semibold text-on-surface">Nenhum conteúdo cadastrado</p>
        <button
          type="button"
          disabled
          className="rounded-xl bg-primary px-5 py-2.5 text-label-md font-semibold text-white opacity-60"
        >
          Adicionar Conteúdo
        </button>
      </div>
    </main>
  );
}
