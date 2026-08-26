<?php

declare(strict_types=1);

namespace Hermes\Upload;

use Hermes\CropImage\ImageRepository as ImageRepository;
use Iscos\Voodoo\Row;
use RuntimeException;

/**
 * UploadRepository — interacoes do upload com o banco (via iscos/voodoo-2026).
 */
final class UploadRepository
{
    private \Iscos\Voodoo\Database $db;

    public function __construct(\Iscos\Voodoo\Database $db)
    {
        $this->db = $db;
    }

    /** Garante que a tabela hermes_uploads existe (idempotente). */
    public function criaTabela(): void
    {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Nao foi possivel ler o schema.sql.');
        }

        $linhas = array_filter(
            array_map('trim', explode("\n", $schema)),
            fn (string $l) => $l !== '' && !str_starts_with($l, '--'),
        );
        foreach (array_filter(array_map('trim', explode(';', implode("\n", $linhas)))) as $statement) {
            $this->db->run($statement);
        }
    }

    /**
     * @param array{ tipo?: string, nome_original: string, caminho: string,
     *                extensao: string, tamanho: int, mime?: ?string,
     *                imagem_id?: ?int } $dados
     */
    public function registrar(array $dados): int
    {
        return (int) $this->db->table('hermes_uploads')->insert([
            'tipo' => $dados['tipo'] ?? 'arquivo',
            'nome_original' => $dados['nome_original'],
            'caminho' => $dados['caminho'],
            'extensao' => $dados['extensao'],
            'tamanho' => $dados['tamanho'],
            'mime' => $dados['mime'] ?? null,
            'imagem_id' => $dados['imagem_id'] ?? null,
        ]);
    }

    public function buscar(int $id): ?Row
    {
        return $this->db->table('hermes_uploads')->findById($id);
    }

    /** @return array<int, Row> */
    public function listar(?string $tipo = null, int $limite = 50): array
    {
        $q = $this->db->table('hermes_uploads');
        if ($tipo !== null) {
            $q->where('tipo', $tipo);
        }

        return $q->orderBy('id', 'DESC')->limit($limite)->find();
    }

    public function contar(?string $tipo = null): int
    {
        $q = $this->db->table('hermes_uploads');
        if ($tipo !== null) {
            $q->where('tipo', $tipo);
        }

        return $q->count();
    }

    /**
     * Apaga o registro E o arquivo (e a hermes_images vinculada, se houver).
     */
    public function apagar(int $id): bool
    {
        $upload = $this->buscar($id);
        if ($upload === null) {
            return false;
        }

        if (self::caminhoSeguro($upload->caminho) && is_file($upload->caminho)) {
            @unlink($upload->caminho);
        }
        if ($upload->imagem_id !== null) {
            (new ImageRepository($this->db))->apagar((int) $upload->imagem_id);
        }

        $this->db->table('hermes_uploads')->where('id', $id)->delete();

        return true;
    }

    /** So apaga caminhos relativos simples (anti delecao arbitraria via registro adulterado). */
    private static function caminhoSeguro(string $caminho): bool
    {
        return $caminho !== ''
            && !str_starts_with($caminho, '/')
            && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $caminho)
            && !str_contains($caminho, '..');
    }
}
