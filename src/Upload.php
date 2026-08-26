<?php

declare(strict_types=1);

namespace Hermes\Upload;

use Closure;
use Hermes\CropImage\CropImage;
use Hermes\Validators\Validator;
use Iscos\Voodoo\Database;
use RuntimeException;

/**
 * Upload — upload completo para PHP sem framework.
 *
 * Valida (hermes/validators), move com nome seguro, processa imagens
 * (hermes/crop-image) e grava no banco (hermes_uploads, via voodoo-2026).
 *
 * ```php
 * $upload = new Upload($db, [
 *     'pasta' => 'uploads',
 *     'max_tamanho' => 2 * 1024 * 1024,   // 2 MB
 *     'permitidos' => ['jpg', 'png', 'webp'],
 * ]);
 *
 * $registro = $upload->salvar($_FILES['foto'], 'galeria', [
 *     'webp' => 80,
 *     'thumbs' => [[400, null], [400, 400]],
 * ]);
 * ```
 */
final class Upload
{
    private Database $db;

    /** @var array{pasta: string, max_tamanho: int, permitidos: array<int, string>, regras: array<string, string|Closure>} */
    private array $opcoes;

    /**
     * @param array{ pasta?: string, max_tamanho?: int, permitidos?: array<int, string>,
     *               regras?: array<string, string|Closure> } $opcoes
     */
    public function __construct(Database $db, array $opcoes = [])
    {
        $this->db = $db;
        $this->opcoes = array_merge([
            'pasta' => 'uploads',
            'max_tamanho' => 5 * 1024 * 1024,
            'permitidos' => [],
            'regras' => [],
        ], $opcoes);
    }

    /**
     * Valida, move e registra um arquivo.
     *
     * @param array{ name?: string, type?: string, tmp_name?: string, error?: int, size?: int } $arquivo
     *        (a forma de $_FILES['campo'])
     * @param array<string, mixed> $dadosForm campos extras validados (regras)
     * @param array{ webp?: int|false, thumbs?: array<int, array{0:int,1:?int}>,
     *               watermark?: array, cache_dir?: string } $processamento
     *        opcoes do hermes/crop-image — só se for imagem
     *
     * @return array<string, mixed> registro gravado (com 'id')
     */
    public function salvar(array $arquivo, string $tipo = 'arquivo', array $dadosForm = [], array $processamento = []): array
    {
        // 1) presenca e erro nativo do PHP
        $erroUpload = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erroUpload !== UPLOAD_ERR_OK || empty($arquivo['tmp_name'])) {
            throw new RuntimeException(self::mensagemErroPhp($erroUpload));
        }

        $nomeOriginal = (string) ($arquivo['name'] ?? 'arquivo');
        $tamanho = (int) ($arquivo['size'] ?? 0);
        $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));

        // 2) validacao: tamanho + extensao (regras do hermes/validators)
        $callbacks = [
            'arquivo' => fn (mixed $v) => $tamanho <= $this->opcoes['max_tamanho'],
            'extensao' => fn (mixed $v) => $this->opcoes['permitidos'] === []
                || in_array($extensao, $this->opcoes['permitidos'], true),
        ];
        $v = Validator::make(
            ['arquivo' => $nomeOriginal, 'extensao' => $extensao] + $dadosForm,
            [
                'arquivo' => 'required|unique',
                'extensao' => 'required|unique',
                ...$this->opcoes['regras'],
            ],
            'pt',
            $callbacks,
        );
        if ($v->fails()) {
            throw new RuntimeException($v->firstError() . ' (arquivo: ' . $nomeOriginal . ', ' . round($tamanho / 1024) . ' KB)');
        }

        // 3) move com nome seguro
        $destino = $this->mover($arquivo, $extensao);

        // 4) se for imagem, processa com hermes/crop-image
        $imagemId = null;
        $info = @getimagesize($destino);
        if ($info !== false) {
            $processamento['cache_dir'] ??= $this->opcoes['pasta'] . '/cache';
            $registroImagem = CropImage::process($this->db, $destino, $tipo, $processamento);
            $imagemId = $registroImagem['id'];
        }

        // 5) registro no banco (retorna o registro completo, padrao da familia)
        $repo = new UploadRepository($this->db);
        $id = $repo->registrar([
            'tipo' => $tipo,
            'nome_original' => $nomeOriginal,
            'caminho' => $destino,
            'extensao' => $extensao,
            'tamanho' => $tamanho,
            'mime' => $arquivo['type'] ?? null,
            'imagem_id' => $imagemId,
        ]);

        $registro = $repo->buscar($id);
        if ($registro === null) {
            throw new RuntimeException('Falha ao registrar o upload.');
        }

        return $registro->toArray();
    }

    /* ============ internos ============ */

    private function mover(array $arquivo, string $extensao): string
    {
        $pasta = rtrim($this->opcoes['pasta'], '/\\');
        if (!is_dir($pasta) && !mkdir($pasta, 0777, true) && !is_dir($pasta)) {
            throw new RuntimeException("Nao foi possivel criar a pasta de upload: {$pasta}");
        }

        $nomeSeguro = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . ($extensao !== '' ? '.' . $extensao : '');
        $destino = "{$pasta}/{$nomeSeguro}";

        $tmp = (string) $arquivo['tmp_name'];
        $ok = is_uploaded_file($tmp)
            ? move_uploaded_file($tmp, $destino)
            : @rename($tmp, $destino);

        if (!$ok) {
            throw new RuntimeException('Falha ao mover o arquivo enviado.');
        }

        return $destino;
    }

    private static function mensagemErroPhp(int $erro): string
    {
        return match ($erro) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho maximo permitido.',
            UPLOAD_ERR_PARTIAL => 'O upload foi interrompido (arquivo incompleto).',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporaria de upload inexistente no servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar o arquivo no servidor.',
            default => 'Erro desconhecido no upload (codigo ' . $erro . ').',
        };
    }
}
