<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZRG Media Downloader</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <!-- Tailwind CSS via CDN para um design rápido e limpo -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between">

    <!-- Header -->
    <header class="border-b border-slate-800 bg-slate-900/50 backdrop-blur">
        <div class="max-w-4xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="bg-indigo-600 p-2 rounded-xl text-white">
                    <i class="fa-solid fa-cloud-arrow-down text-xl"></i>
                </div>
                <span class="font-bold text-lg tracking-wide">ZRG <span class="text-indigo-400">Mediaz</span></span>
            </div>
            <span class="text-xs px-3 py-1 bg-indigo-950 text-indigo-300 border border-indigo-800/50 rounded-full">v1.0 Scalable</span>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-3xl mx-auto px-4 py-10 w-full flex-grow">
        
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold tracking-tight mb-2">Baixe mídias de qualquer lugar</h1>
            <p class="text-slate-400 text-sm">Cole o link da sua plataforma favorita abaixo. O sistema detecta e processa automaticamente.</p>
        </div>

        <!-- Plataformas Suportadas (Escalonável / Visual) -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
            <div class="bg-slate-900 border border-slate-800 p-3 rounded-xl flex items-center space-x-3 opacity-90 hover:border-indigo-500/50 transition">
                <i class="fa-brands fa-youtube text-red-500 text-xl"></i>
                <span class="text-sm font-medium">YouTube</span>
            </div>
            <div class="bg-slate-900 border border-slate-800 p-3 rounded-xl flex items-center space-x-3 opacity-90 hover:border-indigo-500/50 transition">
                <i class="fa-brands fa-instagram text-pink-500 text-xl"></i>
                <span class="text-sm font-medium">Instagram</span>
            </div>
            <div class="bg-slate-900 border border-slate-800 p-3 rounded-xl flex items-center space-x-3 opacity-90 hover:border-indigo-500/50 transition">
                <i class="fa-brands fa-facebook text-blue-500 text-xl"></i>
                <span class="text-sm font-medium">Facebook</span>
            </div>
            <div class="bg-slate-900 border border-slate-800 p-3 rounded-xl flex items-center space-x-3 opacity-90 hover:border-indigo-500/50 transition">
                <i class="fa-brands fa-tiktok text-slate-100 text-xl"></i>
                <span class="text-sm font-medium">TikTok</span>
            </div>
        </div>

        <!-- Formulário de Download -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <form id="downloadForm" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">URL da Mídia</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-500">
                            <i class="fa-solid fa-link"></i>
                        </span>
                        <input type="url" id="urlInput" required placeholder="https://www.instagram.com/p/... ou youtube.com/watch?v=..." 
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl py-3 pl-11 pr-4 text-sm text-slate-100 focus:outline-none focus:border-indigo-500 transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Formato</label>
                        <select id="formatSelect" class="w-full bg-slate-950 border border-slate-800 rounded-xl py-3 px-4 text-sm text-slate-100 focus:outline-none focus:border-indigo-500 transition">
                            <option value="video">Vídeo (MP4)</option>
                            <option value="audio">Áudio Apenas (MP3)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Ação</label>
                        <button type="submit" id="submitBtn" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-medium py-3 px-4 rounded-xl text-sm transition flex items-center justify-center space-x-2 shadow-lg shadow-indigo-600/20">
                            <i class="fa-solid fa-download"></i>
                            <span>Processar Download</span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Status / Resposta -->
            <div id="statusBox" class="hidden mt-6 p-4 rounded-xl bg-slate-950 border border-slate-800 text-sm">
                <div id="statusMessage" class="flex items-center space-x-3">
                    <i class="fa-solid fa-spinner fa-spin text-indigo-400"></i>
                    <span class="text-slate-300">Processando mídia no servidor... Aguarde.</span>
                </div>
                <div id="downloadLinkContainer" class="mt-3 hidden">
                    <!-- Link gerado aparecerá aqui -->
                </div>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-900 py-4 text-center text-xs text-slate-500">
        ZRG Pro Infrastructure &copy; <?php echo date('Y'); ?>
    </footer>

    <!-- Script de Manipulação AJAX -->
    <script>
        const form = document.getElementById('downloadForm');
        const statusBox = document.getElementById('statusBox');
        const statusMessage = document.getElementById('statusMessage');
        const downloadLinkContainer = document.getElementById('downloadLinkContainer');
        const submitBtn = document.getElementById('submitBtn');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const url = document.getElementById('urlInput').value;
            const format = document.getElementById('formatSelect').value;

            statusBox.classList.remove('hidden');
            statusMessage.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-indigo-400"></i><span class="text-slate-300">Baixando arquivo do servidor...</span>`;
            downloadLinkContainer.classList.add('hidden');
            submitBtn.disabled = true;

            try {
                const response = await fetch('apis/download.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `url=${encodeURIComponent(url)}&format=${format}`
                });

                const data = await response.json();

                if (data.success) {
                    statusMessage.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-400"></i><span class="text-emerald-300">Pronto! Arquivo baixado com sucesso.</span>`;
                    downloadLinkContainer.innerHTML = `<a href="${data.file_url}" download class="inline-flex items-center space-x-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold py-2 px-4 rounded-lg transition"><i class="fa-solid fa-file-arrow-down"></i><span>Baixar Arquivo para o Computador</span></a>`;
                    downloadLinkContainer.classList.remove('hidden');
                } else {
                    statusMessage.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-red-400"></i><span class="text-red-300">Erro: ${data.error}</span>`;
                }
            } catch (err) {
                statusMessage.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-red-400"></i><span class="text-red-300">Erro de comunicação com o servidor.</span>`;
            } finally {
                submitBtn.disabled = false;
            }
        });
    </script>
</body>
</html>