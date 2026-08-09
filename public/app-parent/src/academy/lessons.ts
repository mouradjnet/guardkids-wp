// Catálogo de aulas do Academy (Onda 1).
//
// Conteúdo estático, versionado no bundle — sem tabela nova. Reaproveita os 7
// tutoriais escritos em GuardiãoKids Academy. O `body` é markdown; a UI (Onda 1,
// passo 4) renderiza. Manter os `id` estáveis: o progresso do responsável é
// gravado por `id` no wp_usermeta.

export type LessonCategory = 'primeiros-passos' | 'configuracao' | 'tempo-de-tela';

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

  // --- Onda 2: aulas novas ---

  {
    id: 'cadastrar-crianca',
    title: 'Cadastrando uma criança',
    summary: 'Crie o perfil do filho para começar a proteger.',
    category: 'primeiros-passos',
    body: `# Cadastrando uma criança

Cada filho tem o próprio perfil no GuardKids — é ali que ficam as regras, os
limites e o histórico dele.

## Passos
1. No painel, abra **Filhos** e toque em **Adicionar Filho**.
2. Informe o **nome** e a **idade** da criança.
3. Salve — o perfil aparece na lista de Filhos.

## Boas práticas
- Use a idade real: ela ajuda a sugerir limites e conteúdos adequados.
- Um perfil por criança — não compartilhe o mesmo perfil entre irmãos.

## Próximo passo
Com o filho cadastrado, conecte o aparelho dele.`,
  },
  {
    id: 'primeira-regra',
    title: 'Criando a primeira regra',
    summary: 'Defina o que pode e o que não pode ser acessado.',
    category: 'configuracao',
    body: `# Criando a primeira regra

As regras de sites decidem o que a criança pode ou não acessar no navegador
seguro.

## Passos
1. Abra **Sites & Regras** no painel.
2. Escolha o tipo: **liberar** (lista branca) ou **bloquear** (lista negra) um site.
3. Digite o domínio (ex.: \`youtube.com\`) e escolha a quais filhos se aplica.
4. Salve — a regra passa a valer no aparelho da criança.

## Dica
Comece bloqueando poucas coisas essenciais. É melhor ajustar aos poucos, junto
com a criança, do que travar tudo de uma vez.`,
  },
  {
    id: 'tempo-o-que-e',
    title: 'O que é tempo de tela',
    summary: 'Entenda o que o limite de tempo controla — e o que não controla.',
    category: 'tempo-de-tela',
    body: `# O que é tempo de tela

Tempo de tela é quanto tempo por dia a criança pode usar o aparelho protegido.
Quando o limite acaba, o acesso é pausado até o próximo dia (ou até você liberar).

## Por que importa
- Ajuda a criar equilíbrio entre telas, estudo, sono e brincadeira.
- Dá previsibilidade: a criança sabe quanto tempo tem.

## O que ele NÃO é
- Não é castigo. É combinado.
- Não substitui a conversa — funciona melhor quando explicado.

## Próximo passo
Vamos montar uma rotina de tempo saudável.`,
  },
  {
    id: 'tempo-rotina',
    title: 'Criando uma rotina saudável',
    summary: 'Defina um limite diário adequado à idade e à rotina.',
    category: 'tempo-de-tela',
    body: `# Criando uma rotina saudável

## Passos
1. Abra **Limites de Tempo** e escolha o filho.
2. Ative o **limite diário** e defina os minutos por dia.
3. Salve — o limite passa a contar no aparelho da criança.

## Como escolher o tempo
- Comece com um valor que já reflita a rotina atual, não um ideal distante.
- Ajuste depois de alguns dias, olhando os **Relatórios** de uso.

## Boas práticas
- Combine o valor **com** a criança sempre que possível.
- Menos tempo em dias de escola, mais no fim de semana, é um bom começo.`,
  },
  {
    id: 'tempo-escolar',
    title: 'Horário escolar',
    summary: 'Bloqueie distrações durante as aulas.',
    category: 'tempo-de-tela',
    body: `# Horário escolar

Durante a escola ou a lição de casa, faz sentido reduzir o acesso a distrações.

## Como fazer
- Use um **limite diário** menor nos dias de aula.
- Bloqueie sites de distração em **Sites & Regras** no horário de estudo.
- Deixe liberado o que for necessário para estudar.

## Dica
Explique que a restrição do horário escolar é para ajudar a focar — e que o tempo
livre continua existindo depois.`,
  },
  {
    id: 'tempo-descanso',
    title: 'Horário de descanso',
    summary: 'Configure a hora de dormir para proteger o sono.',
    category: 'tempo-de-tela',
    body: `# Horário de descanso

Telas antes de dormir atrapalham o sono. A **hora de dormir** pausa o aparelho
automaticamente no período que você definir.

## Passos
1. Em **Limites de Tempo**, ative a **hora de dormir** para o filho.
2. Defina o horário de início e de fim (ex.: 21h às 7h).
3. Salve — nesse período o acesso fica pausado.

## Boas práticas
- Deixe uma folga antes do sono, não só no minuto de dormir.
- Mantenha o mesmo horário nos dias de semana para criar rotina.`,
  },
  {
    id: 'tempo-livre',
    title: 'Tempo livre',
    summary: 'Equilibre limites com momentos sem restrição.',
    category: 'tempo-de-tela',
    body: `# Tempo livre

Controle não é bloquear tudo. Reservar **tempo livre** ensina a criança a se
autorregular.

## Ideias
- Um limite diário mais generoso no fim de semana.
- Momentos combinados sem restrição (ex.: tarde de sábado).
- Recompensar boas escolhas com um pouco mais de tempo.

## Por quê
Quando a criança sente que também tem liberdade, ela respeita mais os limites do
resto da semana.`,
  },
  {
    id: 'tempo-por-app',
    title: 'Limites por aplicativo',
    summary: 'Controle apps específicos, não só o total do dia.',
    category: 'tempo-de-tela',
    body: `# Limites por aplicativo

Às vezes o problema não é o tempo total, é **um** aplicativo específico.

## Como agir
- Use a lista de aplicativos para **bloquear** apps não adequados.
- Combine com o limite diário: o total do dia continua valendo para o resto.
- Reveja nos **Relatórios** quais apps consomem mais tempo.

## Dica
Antes de bloquear, converse. Bloquear sem explicar costuma gerar mais conflito do
que combinar junto.`,
  },
  {
    id: 'tempo-conversa',
    title: 'Como conversar sobre limites',
    summary: 'Transforme regra em combinado — reduz conflito.',
    category: 'tempo-de-tela',
    body: `# Como conversar sobre limites

O limite funciona muito melhor quando é **combinado**, não imposto de surpresa.

## Roteiro simples
1. Explique o **porquê** (sono, estudo, equilíbrio) — não só a regra.
2. Deixe a criança opinar sobre os horários.
3. Combine e cumpra dos dois lados.
4. Reveja juntos depois de um tempo.

## Evite
- Mudar o combinado sem avisar.
- Usar o tempo de tela só como punição.`,
  },
  {
    id: 'tempo-avaliacao',
    title: 'Revisando e ajustando',
    summary: 'Use os relatórios para calibrar os limites.',
    category: 'tempo-de-tela',
    body: `# Revisando e ajustando

Nenhum limite é perfeito de primeira. O certo é **revisar** com dados.

## Como revisar
1. Abra **Relatórios** e veja o uso real da semana.
2. Compare com o que vocês combinaram.
3. Ajuste o limite diário ou a hora de dormir se precisar.
4. Converse sobre o que funcionou e o que não funcionou.

## Sinal de que está bom
Quando a criança raramente bate no limite e não há brigas — o combinado achou o
ponto certo.`,
  },
];

/** Busca uma aula pelo id estável. */
export function findLesson(id: string): Lesson | undefined {
  return LESSONS.find((lesson) => lesson.id === id);
}
