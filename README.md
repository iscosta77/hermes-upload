# Upload — upload completo para PHP sem framework

> Parte da família **hermes_\*** — ferramentas completas, interligadas e com
> estrutura de banco para quem programa PHP na unha.
> Criado e mantido por **Hermes Agent (Nous Research)** · publicado por Ildefonso Costa.

Upload de arquivos com **validação** (hermes/validators), **processamento de imagem**
(hermes/crop-image: WebP, thumbs, marca d'água), **nome seguro** e **registro no banco**
(`hermes_uploads`, via iscos/voodoo-2026).

## Instalação

```bash
composer require hermes/upload
```

## Uso

```php
use Hermes\Upload\Upload;
use Iscos\Voodoo\Voodoo;

$db = Voodoo::fromEnv(); // .env formato Laravel

$upload = new Upload($db, [
    'pasta'       => 'uploads',
    'max_tamanho' => 2 * 1024 * 1024,        // 2 MB
    'permitidos'  => ['jpg', 'png', 'webp'], // [] = qualquer extensão
    'regras'      => ['titulo' => 'required|min:5'], // regras extras do form
]);

// $_FILES['foto'] direto; se for imagem, processa e grava nas duas tabelas
$registro = $upload->salvar($_FILES['foto'], 'galeria', $_POST, [
    'webp'      => 80,
    'thumbs'    => [[400, null], [400, 400]],
    'watermark' => ['logo' => 'assets/logo.png', 'scale' => 15],
]);

echo $registro['id'];                 // id na hermes_uploads
echo $registro['caminho'];            // uploads/20260825-...-a1b2.jpg
echo $registro['imagem_id'];          // id na hermes_images (null se nao for imagem)
```

## Interações com o banco

```php
use Hermes\Upload\UploadRepository;

$repo = new UploadRepository($db);
$repo->criaTabela();                    // idempotente
$repo->buscar(3);                       // Row da hermes_uploads
$repo->listar('galeria', 20);           // últimas 20 do tipo
$repo->contar('documento');
$repo->apagar(3);                       // remove arquivo + registro + hermes_images
```

## Validações (hermes/validators em ação)

- Nenhum arquivo / upload interrompido / erro nativo do PHP → mensagem clara
- Tamanho máximo (`max_tamanho`)
- Extensões permitidas (`permitidos`) — bloqueia `.php` e cia.
- Regras extras do formulário (`regras`) — rodadas pelo validators
- Nome seguro no destino: `20260825-153012-a1b2c3d4.jpg` (sem acento/espaço/colisão)

## Schema (hermes_uploads)

`tipo`, `nome_original`, `caminho`, `extensao`, `tamanho`, `mime`,
`imagem_id` (FK → `hermes_images`), `criado_em` — índice por `tipo`.

## Família hermes_*

| Pacote | Status |
|---|---|
| hermes/validators | ✅ v1.0.1 |
| hermes/crop-image | ✅ v1.0.0 |
| **hermes/upload** | ✅ **v1.0.0 — este** |
| hermes/upload-multiple | em desenvolvimento (estende este) |
| hermes/gallery | em desenvolvimento (galerias 1:N) |

## Licença

MIT © 2026 Hermes Agent (Nous Research) — criador e mantenedor · Ildefonso Costa — publicador
