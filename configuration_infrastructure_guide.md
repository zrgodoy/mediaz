# 📚 ZRG Mediaz - Server Configuration & Documentation

Este documento detalha toda a infraestrutura, stack tecnológica, permissões de diretório e rotinas de segurança configuradas para o **ZRG Mediaz** em um ambiente Ubuntu 24.04 LTS.

---

## 1. Stack Tecnológica e Requisitos de Sistema

- **Sistema Operacional:** Ubuntu 24.04 LTS (Kernel 6.8+)
- **Servidor Web:** Apache 2
- **Linguagem Backend:** PHP 8.3+
- **Core de Processamento:** 
  - `yt-dlp` (Gerenciador de extração e downloads)
  - `ffmpeg` / `ffprobe` (Processamento e mesclagem de áudio/vídeo)
  - `Node.js` (Runtime JavaScript obrigatório para os extratores e desafios do YouTube)
- **Frontend:** Tailwind CSS (via CDN) + FontAwesome 6.4

---

## 2. Estrutura de Diretórios Escalável

A organização do projeto isola a interface, os scripts de API e o armazenamento de mídia:

```text
mediaz/
├── index.php             # Interface web principal (Tailwind CSS + AJAX)
├── favicon.png           # Ícone do site
├── downloads/            # Diretório de armazenamento temporário (Gerenciado por PHP/Cron)
├── docs/
│   └── server-configuration.md # Este documento de configuração
└── apis/
    └── download.php      # Script backend de execução e segurança
```

---

## 3. Comandos de Instalação e Configuração no Ubuntu 24.04

### 3.1. Atualização do Sistema e Dependências
Execute os comandos abaixo para garantir que o Apache, PHP, FFmpeg e Node.js estejam instalados e atualizados:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 php libapache2-mod-php nodejs ffmpeg python3-pip curl
```

### 3.2. Instalação e Atualização do `yt-dlp`
Para manter o suporte a extrações recentes de plataformas de vídeo:

```bash
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp
```

### 3.3. Configuração de Permissões do Servidor Web (`www-data`)
Para que o Apache consiga gravar e ler os arquivos baixados sem erros de permissão (`Permission denied`):

```bash
sudo mkdir -p /var/www/html/mediaz/downloads
sudo chown -R www-data:www-data /var/www/html/mediaz/
sudo chmod -R 775 /var/www/html/mediaz/downloads
```

---

## 4. Configuração do Backend (`apis/download.php`)

O script realiza validação estrita de URLs, executa o comando `yt-dlp` tratando fluxos de vídeo (`mp4`) e áudio (`mp3`), e aplica a limpeza automática de arquivos legados.

```php
<?php
// apis/download.php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método inválido.']);
    exit;
}

$url = trim($_POST['url'] ?? '');
$formatType = $_POST['format'] ?? 'video';

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'URL inválida ou vazia.']);
    exit;
}

// Pasta de destino na raiz do projeto
$downloadDir = __DIR__ . '/../downloads/';
if (!file_exists($downloadDir)) {
    mkdir($downloadDir, 0755, true);
}

// Limpeza automática de arquivos com mais de 30 minutos (1800 segundos)
foreach (glob($downloadDir . '*') as $oldFile) {
    if (is_file($oldFile) && (time() - filemtime($oldFile) > 1800)) {
        @unlink($oldFile);
    }
}

$outputTemplate = $downloadDir . '%(title)s.%(ext)s';

if ($formatType === 'audio') {
    $cmd = sprintf('yt-dlp -x --audio-format mp3 -o %s %s 2>&1', 
        escapeshellarg($outputTemplate), 
        escapeshellarg($url)
    );
} else {
    $cmd = sprintf('yt-dlp -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4] / bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1', 
        escapeshellarg($outputTemplate), 
        escapeshellarg($url)
    );
}

exec($cmd, $output, $exitCode);

if ($exitCode !== 0) {
    echo json_encode([
        'success' => false, 
        'error' => 'Falha ao processar link. Verifique se a URL é pública e suportada.'
    ]);
    exit;
}

$files = glob($downloadDir . '*');
if (empty($files)) {
    echo json_encode(['success' => false, 'error' => 'O arquivo não foi gerado corretamente.']);
    exit;
}

usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$latestFile = $files[0];
$fileName = basename($latestFile);
$fileUrl = 'downloads/' . $fileName;

echo json_encode([
    'success' => true,
    'file_url' => $fileUrl
]);
```

---

## 5. Rotina de Segurança e Limpeza Automática

1. **Embutida no PHP:** Toda requisição executada varre o diretório de downloads e remove arquivos com mais de 30 minutos de vida.
2. **Cron Job do Sistema (Opcional):** Para garantir a limpeza periódica independente de acessos web, configure a seguinte tarefa no crontab (`sudo crontab -e`):
   ```cron
   0 */2 * * * find /var/www/html/mediaz/downloads/ -type f -mmin +120 -delete
   ```
   *(Remove arquivos modificados há mais de 120 minutos a cada 2 horas).*