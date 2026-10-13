# 📚 ZRG Mediaz - Guia de Configuração e Infraestrutura

Este documento registra a configuração atual do projeto **ZRG Mediaz**, com foco na infraestrutura mínima necessária para executar a aplicação em um ambiente Linux/Ubuntu e no comportamento real dos componentes presentes no repositório.

---

## 1. Visão Geral

O projeto é uma aplicação web simples em PHP para baixar ou converter mídias a partir de URLs públicas. A interface principal está em `index.php`, a API de processamento está em `apis/download.php` e a área administrativa para remover arquivos está em `admin/files.php`.

A aplicação usa:

- Apache 2 ou qualquer servidor web PHP compatível
- PHP 8.x
- `yt-dlp` para extração e processamento
- `ffmpeg` para transcodificação e otimização de vídeo
- Tailwind CSS via CDN para layout front-end
- Font Awesome para ícones

---

## 2. Estrutura Atual do Projeto

```text
mediaz/
├── index.php                 # Página principal do front-end
├── index copy.php            # Cópia de backup local do arquivo inicial
├── favicon.png              # Ícone do sistema
├── admin/
│   └── files.php            # Gerenciamento de arquivos em downloads/
├── apis/
│   ├── download copy.php     # Backup local do endpoint de download
│   └── download.php         # Endpoint de processamento de mídia
├── downloads/               # Diretório de saída dos arquivos gerados
├── .gitignore               # Regras de git do projeto
├── configuration_infrastructure_guide.md
└── README.md (se existir no ambiente de deploy, opcional)
```

> Observação: o projeto atual não possui uma pasta `docs/` nem um arquivo `docs/server-configuration.md` no repositório. Este guia funciona como a documentação de infraestrutura do projeto.

---

## 3. Requisitos de Sistema

### 3.1. Dependências principais

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 php libapache2-mod-php ffmpeg python3-pip curl
```

### 3.2. Instalação do `yt-dlp`

```bash
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp
```

### 3.3. Verificação rápida

```bash
yt-dlp --version
ffmpeg -version | head
php -v
```

---

## 4. Permissões e Estrutura de Arquivos

A pasta `downloads/` é o ponto de saída para os arquivos gerados pelo sistema. Por padrão, ela deve ser criada no diretório raiz do projeto e acessível pelo usuário do servidor web.

```bash
sudo mkdir -p /var/www/html/mediaz/downloads
sudo chown -R www-data:www-data /var/www/html/mediaz
sudo chmod -R 755 /var/www/html/mediaz
sudo chmod -R 775 /var/www/html/mediaz/downloads
```

### Importante

- `www-data` precisa ler e gravar em `downloads/`
- arquivos antigos são limpos em execução no backend
- a pasta deve ser persistente e não exposta como área de armazenamento insegura fora do contexto da aplicação

---

## 5. Fluxo de Uso da Aplicação

### 5.1. Front-end

A página principal `index.php` monta o formulário e realiza uma requisição AJAX para:

```text
/apis/download.php
```

Os campos enviados no formulário incluem:

- `url` — URL da mídia
- `codec_preset` — tipo de processamento

### 5.2. Presets suportados

O backend aceita os seguintes valores em `codec_preset`:

- `h264` — converte para H.264 em MP4 com `ffmpeg` e otimização para edição/Prerender
- `best` — tenta usar a melhor qualidade original em MP4
- `audio_mp3` — extrai apenas o áudio em MP3

### 5.3. Validação do backend

No arquivo `apis/download.php`, a API:

1. exige método `POST`
2. valida que a URL foi enviada e é uma URL válida
3. cria a pasta `downloads/` quando necessário
4. limpa arquivos antigos com mais de 30 minutos
5. executa `yt-dlp` com o comando correto conforme o preset
6. verifica o retorno do processo
7. retorna JSON com `success` e `file_url` no caso de êxito

---

## 6. Comportamento Real do Endpoint

O endpoint atual usa comandos como:

```bash
yt-dlp -x --audio-format mp3 -o '%(title)s.%(ext)s' <url>
```

ou

```bash
yt-dlp -f "bv*+ba/b" --merge-output-format mp4 --postprocessor-args "ffmpeg:-c:v libx264 -preset fast -crf 22 -c:a aac -b:a 192k -movflags +faststart" -o '%(title)s.%(ext)s' <url>
```

O retorno da API segue este formato:

```json
{
  "success": true,
  "file_url": "downloads/arquivo.mp4"
}
```

Ou, em caso de falha:

```json
{
  "success": false,
  "error": "Erro no servidor: ..."
}
```

---

## 7. Administração dos Arquivos

O painel em `admin/files.php` permite:

- listar os arquivos disponíveis em `downloads/`
- baixar um arquivo diretamente
- excluir um item individual
- limpar toda a pasta com um único clique

Ele usa `glob()` para listar os arquivos e ordena pela data mais recente.

---

## 8. Segurança e Boas Práticas

### Recomendado

- manter a aplicação em um ambiente acessível apenas para uso autorizado
- limitar o uso a URLs públicas e confiáveis
- não expor a pasta `downloads/` como armazenamento geral sem controle
- manter `yt-dlp` atualizado
- verificar `ffmpeg` e `php` após mudanças de sistema

### Observações importantes

- a API executa comandos de shell via `exec()`; isso exige cuidado com entrada externa
- a validação atual é limitada a `FILTER_VALIDATE_URL` e não implementa whitelist de domínios
- a aplicação é funcional para uso interno/local, mas não deve ser tratada como um serviço público sem reforço de segurança

---

## 9. Limpeza Automática

A aplicação já implementa limpeza de arquivos antigos no próprio PHP:

```php
foreach (glob($downloadDir . '*') as $oldFile) {
    if (is_file($oldFile) && (time() - filemtime($oldFile) > 1800)) {
        @unlink($oldFile);
    }
}
```

Isso remove arquivos mais antigos que 30 minutos automaticamente ao processar uma nova requisição.

Também é possível configurar um cron do sistema para manutenção periódica:

```cron
0 */2 * * * find /var/www/html/mediaz/downloads/ -type f -mmin +120 -delete
```

---

## 10. Troubleshooting

### Problema: `Permission denied`

```bash
sudo chown -R www-data:www-data /var/www/html/mediaz
sudo chmod -R 775 /var/www/html/mediaz/downloads
```

### Problema: `yt-dlp` não encontrado

```bash
which yt-dlp
yt-dlp --version
sudo chmod a+rx /usr/local/bin/yt-dlp
```

### Problema: vídeo não baixa ou falha no processamento

- verificar se a URL é pública
- confirmar que o serviço tem acesso ao internet
- confirmar que `ffmpeg` está instalado
- verificar o conteúdo do retorno JSON da API em rede/devtools

### Problema: arquivo gerado não aparece

- verificar se `downloads/` existe
- confirmar permissões do diretório
- confirmar se `yt-dlp` gerou o arquivo com nome diferente por causa do título da mídia

---

## 11. Resumo da Infraestrutura Atual

A instalação atual do MediaZ funciona com uma estrutura simples e direta:

- site web em PHP
- API de processamento em `apis/download.php`
- armazenamento em `downloads/`
- limpezas automáticas e painel de gerenciamento
- uso de ferramentas externas (`yt-dlp` e `ffmpeg`) para geração de conteúdo final

Essa é a infraestrutura mínima necessária para manter a aplicação operacional em um ambiente Ubuntu com Apache e PHP.