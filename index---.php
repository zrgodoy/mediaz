<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Downloader - Servidor Local</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            margin: 0;
            padding: 50px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .container {
            background: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
        }
        h2 {
            margin-top: 0;
            color: #333;
            text-align: center;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: bold;
        }
        input[type="url"], select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            border: none;
            color: white;
            font-size: 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background-color: #0056b3;
        }
        button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        #status-message {
            display: none;
            margin-top: 20px;
            padding: 12px;
            background: #e9ecef;
            border-radius: 4px;
            color: #333;
            font-size: 14px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Baixador de Mídia</h2>
    
    <form id="downloadForm">
        <div class="form-group">
            <label for="url">URL do Vídeo (YouTube, etc.):</label>
            <input type="url" id="url" name="url" placeholder="https://www.youtube.com/watch?v=..." required>
        </div>

        <div class="form-group">
            <label for="format">Formato de Saída:</label>
            <select id="format" name="format">
                <option value="video">Vídeo (MP4)</option>
                <option value="audio">Áudio (MP3)</option>
            </select>
        </div>

        <button type="submit" id="submitBtn">Baixar Mídia</button>
    </form>

    <div id="status-message">
        🔄 <span id="status-text">Processando e baixando o arquivo no servidor... Aguarde.</span>
    </div>
</div>

<script>
document.getElementById('downloadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const statusDiv = document.getElementById('status-message');
    const statusText = document.getElementById('status-text');
    const submitBtn = document.getElementById('submitBtn');
    
    statusDiv.style.display = 'block';
    statusText.innerText = 'Processando e baixando o arquivo no servidor... Aguarde.';
    submitBtn.disabled = true;

    const formData = new FormData(this);

    try {
        const response = await fetch('apis/download.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            statusDiv.innerHTML = `✅ Concluído com sucesso! <br><br><a href="${data.file_url}" download style="color: #007bff; font-weight: bold; text-decoration: none; font-size: 16px;">📥 Clique aqui para baixar o arquivo</a>`;
        } else {
            statusDiv.innerHTML = `❌ Erro: ${data.error}`;
        }
    } catch (err) {
        statusDiv.innerHTML = `❌ Erro de conexão com o servidor.`;
    } finally {
        submitBtn.disabled = false;
    }
});
</script>

</body>
</html>