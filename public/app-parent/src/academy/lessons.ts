// Catálogo de aulas do Academy (Onda 1).
//
// Conteúdo estático, versionado no bundle — sem tabela nova. Reaproveita os 7
// tutoriais escritos em GuardiãoKids Academy. O `body` é markdown; a UI (Onda 1,
// passo 4) renderiza. Manter os `id` estáveis: o progresso do responsável é
// gravado por `id` no wp_usermeta.

export type LessonCategory = 'primeiros-passos' | 'configuracao';

export interface Lesson {
  /** slug estável — chave do progresso; nunca renomear sem migração de dados */
  id: string;
  title: string;
  /** frase única mostrada no card antes de abrir a aula */
  summary: string;
  category: LessonCategory;
  /** corpo em markdown */
  body: string;
}

export const LESSONS: Lesson[] = [
  {
    id: 'primeiros-passos',
    title: 'Primeiros Passos',
    summary: 'Conheça o GuardKids e dê o primeiro passo na proteção da família.',
    category: 'primeiros-passos',
    body: `# Primeiros Passos

O GuardKids ajuda o responsável a acompanhar e proteger a vida digital da criança
de forma organizada.

## Antes de começar
- Acesso à conta do responsável
- Dispositivos preparados
- Conexão com internet

## Primeiro acesso
1. Acesse sua conta de responsável.
2. Conheça a tela inicial e as principais áreas do aplicativo.
3. Verifique as opções disponíveis para configuração.

## Boas práticas
- Mantenha o aplicativo atualizado.
- Leia as orientações disponíveis.
- Revise as configurações periodicamente.

## Próximo passo
Cadastre seu primeiro filho para começar a proteção.`,
  },
  {
    id: 'instalacao-inicial',
    title: 'Instalação Inicial',
    summary: 'Prepare os dispositivos do responsável e da criança.',
    category: 'primeiros-passos',
    body: `# Instalação Inicial

## Antes da instalação
**Dispositivo do responsável:** acesso ao aplicativo, conta configurada e conexão.
**Dispositivo da criança:** compatibilidade, acesso ao aparelho e disponibilidade.

## Processo inicial
1. Prepare os dispositivos que serão utilizados.
2. Instale o aplicativo conforme a plataforma.
3. Faça o primeiro acesso com a conta do responsável.
4. Siga as etapas apresentadas pelo aplicativo.

## Problemas comuns
- **Não conclui a instalação:** verifique internet, espaço e versão compatível.
- **O aplicativo não abre:** verifique atualização, reinício e permissões.`,
  },
  {
    id: 'configuracao-responsavel',
    title: 'Configuração do Responsável',
    summary: 'Organize sua conta e revise suas preferências.',
    category: 'configuracao',
    body: `# Configuração do Responsável

A configuração do responsável organiza o acesso e prepara o ambiente para usar os
recursos.

## Passos
1. Acesse a conta com os dados cadastrados.
2. Confira se as informações estão corretas.
3. Conheça o painel principal e seus menus.
4. Revise as preferências conforme a necessidade.

## Segurança da conta
- Use uma senha segura e não a compartilhe.
- Utilize dispositivos confiáveis.
- Mantenha as informações atualizadas.

## Próximo passo
Configure o dispositivo do filho.`,
  },
  {
    id: 'dispositivo-filho',
    title: 'Configuração do Dispositivo Filho',
    summary: 'Conecte o aparelho da criança e proteja o primeiro dispositivo.',
    category: 'primeiros-passos',
    body: `# Configuração do Dispositivo Filho

Conectar o dispositivo da criança prepara o ambiente para os recursos de proteção.

## Antes de começar
- Dispositivo compatível e com acesso físico
- Conexão com internet
- Aplicativo necessário instalado

## Processo de configuração
1. Prepare o dispositivo (ligado, conectado e atualizado).
2. Instale o aplicativo necessário.
3. Autorize as permissões — revise cada uma antes de confirmar.
4. Vincule ao responsável seguindo as instruções.
5. Confirme que o dispositivo aparece no ambiente do responsável.

## Se o dispositivo não aparece
Verifique internet, instalação correta do aplicativo e permissões necessárias.`,
  },
  {
    id: 'gerenciamento-permissoes',
    title: 'Gerenciamento de Permissões',
    summary: 'Entenda e revise as permissões que fazem a proteção funcionar.',
    category: 'configuracao',
    body: `# Gerenciamento de Permissões

As permissões deixam o aplicativo usar corretamente os recursos do sistema.

## Verificando permissões
1. Acesse as configurações do aparelho.
2. Localize o aplicativo relacionado ao GuardKids.
3. Revise se as permissões solicitadas estão autorizadas.
4. Após ajustar, volte ao aplicativo e verifique o funcionamento.

## Boas práticas
- Não altere permissões sem entender a finalidade.
- Revise as configurações após atualizações do sistema.
- Mantenha o sistema atualizado.`,
  },
  {
    id: 'configuracao-preferencias',
    title: 'Configuração de Preferências',
    summary: 'Personalize o GuardKids de acordo com a rotina da família.',
    category: 'configuracao',
    body: `# Configuração de Preferências

As preferências personalizam a experiência dentro das opções disponíveis.

## Acessando as preferências
1. Abra o ambiente do responsável.
2. Navegue até a área de configurações.
3. Analise as opções antes de alterar.
4. Confirme cada ajuste e verifique o resultado.

## Organização
Mantenha atenção em informações cadastradas, dispositivos vinculados e opções
selecionadas.

## Boas práticas
- Configure conforme a necessidade da família.
- Revise periodicamente.`,
  },
  {
    id: 'verificacao-conexao',
    title: 'Verificação de Conexão',
    summary: 'Confirme que a proteção está ativa e se comunicando.',
    category: 'configuracao',
    body: `# Verificação de Conexão

Uma conexão adequada é necessária para os recursos funcionarem.

## Verificando a conexão
1. Confira se os dispositivos têm acesso à internet.
2. Confirme que os aplicativos estão instalados e funcionando.
3. Verifique atualizações pendentes.
4. Reinicie o dispositivo em caso de falha temporária.

## Situações comuns
- **Dispositivo offline:** verifique internet, aplicativo aberto e permissões.
- **Informações desatualizadas:** verifique conexão, atualização e configurações.`,
  },
];

/** Busca uma aula pelo id estável. */
export function findLesson(id: string): Lesson | undefined {
  return LESSONS.find((lesson) => lesson.id === id);
}
