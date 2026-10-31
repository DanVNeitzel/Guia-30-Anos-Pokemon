# Guia 30 Anos Pokémon

Aplicação web em português do Brasil para consultar cartas da coleção Pokémon 30C, acompanhar cartas adquiridas e faltantes e visualizar referências de preços.

## Funcionalidades

- Galeria com 191 cartas: 158 numeradas, 30 da Classic Collection e 3 RGB.
- Busca por número ou nome, filtros de coleção e ordenação por preço.
- Controle de cartas adquiridas com progresso e estimativa de valor da coleção.
- Imagens em português, inglês e japonês, conforme disponibilidade no catálogo externo.
- Consulta de preços e histórico por meio das integrações implementadas com Deck Certo, Liga Pokémon e MYP Cards.
- Exportação e importação da coleção em JSON.
- Exportação de faltantes em CSV, documento `.doc`, texto e impressão como PDF.
- Geração de folhas de proxies para fichário.
- Tema claro/escuro, visualização ampliada das cartas e recursos de instalação como PWA.
- Contador de visitantes únicos por IP, armazenando apenas hashes SHA-256 dos endereços.

## Tecnologias e requisitos

O projeto utiliza PHP, HTML, CSS e JavaScript puro, sem etapa de build, Composer, npm ou banco de dados.

- PHP 8.1 ou superior, necessário pelo uso do tipo de retorno `never`.
- Extensões PHP cURL e DOM para consultas externas e leitura das páginas de preços.
- Permissão de escrita na pasta `data/` para o contador de visitantes.
- Navegador com JavaScript e armazenamento local habilitados.
- Acesso à internet para imagens, fontes e consultas de preços.

## Executar localmente

No terminal, entre na pasta do projeto e execute:

```powershell
cd E:\WEBSERVER\root\poke30
php -S localhost:8000
```

Acesse [http://localhost:8000/index.php](http://localhost:8000/index.php).

O servidor embutido do PHP é destinado ao desenvolvimento. Para hospedagem, utilize um servidor com suporte a PHP, como Apache ou Nginx com PHP-FPM.

## Configuração

### Preços do MYP Cards (opcional)

A integração lê a variável de ambiente `MYPCARDS_API_TOKEN`. No PowerShell, configure-a antes de iniciar o servidor:

```powershell
$env:MYPCARDS_API_TOKEN = 'SEU_TOKEN'
php -S localhost:8000
```

Na hospedagem, disponibilize essa variável ao processo PHP. O projeto não carrega arquivos `.env` automaticamente. Sem o token, a integração MYP Cards retorna indisponibilidade; as demais fontes são consultadas separadamente.

### Contador de visitas

O contador utiliza `data/visits.json`, criado automaticamente se necessário. A pasta precisa permitir escrita pelo usuário que executa o PHP. O arquivo contém o total e os hashes dos IPs, com a data do primeiro acesso registrado.

Antes de publicar uma nova instalação, altere a constante `VISIT_SALT` em `index.php` para um valor próprio. Mudar esse valor em uma instalação existente faz os mesmos IPs gerarem novos hashes e pode aumentar a contagem. Atrás de proxy ou CDN, revise `visitorIp()` para usar somente informações de proxies confiáveis.

### Hospedagem e proteção dos dados

O repositório inclui um arquivo chamado `htaccess`, sem ponto inicial. No Apache, copie seu conteúdo para um arquivo **`.htaccess` na raiz do projeto** e habilite a aplicação dessas regras (`AllowOverride`). As regras bloqueiam o acesso HTTP aos dados do contador. No Nginx ou em outro servidor, configure uma restrição equivalente para `/data/`.

O servidor embutido do PHP não aplica regras de `.htaccess`. Antes de disponibilizar o site publicamente, confirme que uma requisição a `data/visits.json` é negada pelo servidor.

## Como usar

1. Abra a galeria e utilize a busca e os filtros para localizar cartas.
2. Marque as cartas que já possui para atualizar o progresso da coleção.
3. Use **Exportar coleção** para salvar um backup em JSON.
4. Use **Importar coleção** para restaurar um backup; a operação solicita confirmação e substitui a seleção atual.
5. Use **Exportar faltantes** para gerar uma lista ou preparar a impressão.

A coleção e as preferências ficam no `localStorage` do navegador, sem conta ou sincronização entre dispositivos. Limpar os dados do site pode apagar essas informações. Utilize o backup JSON para transferi-las entre navegadores ou dispositivos.

## Estrutura

```text
poke30/
├── index.php              # Interface, catálogo, JavaScript, estilos e endpoints PHP
├── manifest.php           # Manifesto PWA utilizado pela página
├── manifest.webmanifest   # Manifesto estático adicional
├── sw.js                  # Service worker e cache de recursos
├── htaccess               # Regras de proteção para configurar no Apache
├── data/
│   └── visits.json        # Dados persistentes do contador
├── pwa-icon.svg           # Ícone da aplicação
├── og-image.svg           # Imagem para compartilhamento
├── logo.png / logo2.png   # Imagens locais
└── pattern*.webp          # Texturas da interface
```

## Endpoints internos

As rotas são atendidas pelo próprio `index.php` e retornam JSON:

| Método | Rota | Finalidade |
| --- | --- | --- |
| GET | `index.php?visits=1` | Registra o visitante e retorna a contagem. |
| POST | `index.php?prices=1` | Consulta o MYP Cards; recebe `cards` com `n` e `collection`. |
| POST | `index.php?market_prices=1` | Consulta o Deck Certo; recebe a lista `cards`. |
| POST | `index.php?liga_price=1` | Consulta uma página da Liga Pokémon; recebe `url`. |

As requisições POST utilizam corpo JSON. Os identificadores de coleção usados pelo frontend são `base`, `classic` e `rgb`.

## PWA e fontes externas

A instalação depende do suporte do navegador. Em produção, utilize HTTPS; `localhost` pode ser usado no desenvolvimento. O service worker tenta acessar a rede primeiro e utiliza o cache como alternativa. O cache inicial inclui a interface e recursos locais, mas não garante imagens externas nem preços disponíveis offline.

As imagens das cartas são carregadas do CDN do Limitless TCG. Os preços dependem da disponibilidade das fontes externas, de sua estrutura de páginas e de eventuais bloqueios de acesso. Valores e probabilidades exibidos devem ser tratados como referências e estimativas. As Energias não fazem parte da galeria atual.

## Autoria

Desenvolvido por [Daniel Neitzel](https://danielneitzel.dev).
