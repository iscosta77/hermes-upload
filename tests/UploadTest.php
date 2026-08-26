<?php

declare(strict_types=1);

namespace Hermes\Upload\Tests;

use Hermes\CropImage\ImageRepository as CropImageRepository;
use Hermes\Upload\Upload;
use Hermes\Upload\UploadRepository;
use Iscos\Voodoo\Voodoo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UploadTest extends TestCase
{
    private string $dir;
    private \Iscos\Voodoo\Database $db;
    private Upload $upload;
    private UploadRepository $repo;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd necessario.');
        }

        $this->dir = sys_get_temp_dir() . '/hermes-upload-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);

        $this->db = Voodoo::open('sqlite:' . $this->dir . '/teste.sqlite');
        $this->repo = new UploadRepository($this->db);
        $this->repo->criaTabela();
        (new CropImageRepository($this->db))->criaTabela();

        $this->upload = new Upload($this->db, [
            'pasta' => $this->dir . '/uploads',
            'max_tamanho' => 1024 * 1024,
            'permitidos' => ['txt', 'jpg', 'png'],
            'permitir_local' => true,   // testes simulam upload com arquivos locais
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/uploads/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/uploads/cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/uploads/cache');
        @rmdir($this->dir . '/uploads');
        @unlink($this->dir . '/teste.sqlite');
        @rmdir($this->dir);
    }

    /** Simula a forma de $_FILES['campo'] com um arquivo local. */
    private function arquivoFake(string $nome, string $conteudo, string $mime = 'text/plain'): array
    {
        $tmp = $this->dir . '/' . bin2hex(random_bytes(4)) . '-' . $nome;
        file_put_contents($tmp, $conteudo);

        return [
            'name' => $nome,
            'type' => $mime,
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($conteudo),
        ];
    }

    private function fotoFake(string $nome = 'foto.jpg'): array
    {
        $tmp = $this->dir . '/' . bin2hex(random_bytes(4)) . '-' . $nome;
        $im = imagecreatetruecolor(200, 150);
        imagefilledrectangle($im, 0, 0, 199, 149, imagecolorallocate($im, 90, 140, 200));
        imagejpeg($im, $tmp, 90);
        imagedestroy($im);

        return [
            'name' => $nome,
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
    }

    public function testUploadDeArquivoComum(): void
    {
        $registro = $this->upload->salvar($this->arquivoFake('nota.txt', "linha um\nlinha dois\n"), 'documento');
        $id = (int) $registro['id'];

        $this->assertGreaterThan(0, $id);
        $this->assertFileExists($this->dir . '/uploads/' . basename($this->repo->buscar($id)->caminho));
        $this->assertSame('documento', $this->repo->buscar($id)->tipo);
        $this->assertSame('txt', $this->repo->buscar($id)->extensao);
        $this->assertNull($this->repo->buscar($id)->imagem_id);
    }

    public function testUploadDeImagemProcessaEGrava(): void
    {
        $registro = $this->upload->salvar($this->fotoFake(), 'galeria', [], [
            'webp' => 80,
            'thumbs' => [[100, 100]],
        ]);
        $id = (int) $registro['id'];

        $upload = $this->repo->buscar($id);
        $this->assertNotNull($upload->imagem_id, 'imagem deve ter registro em hermes_images');

        $imagem = (new CropImageRepository($this->db))->buscar((int) $upload->imagem_id);
        $this->assertSame('galeria', $imagem->tipo);
        $this->assertFileExists($imagem->webp);
        $this->assertFileExists($imagem->thumbs ? json_decode((string) $imagem->thumbs, true)['100x100'] : '');
    }

    public function testExtensaoNaoPermitida(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/permitid/');

        $this->upload->salvar($this->arquivoFake('malicioso.php', '<?php echo 1;'), 'documento');
    }

    public function testTamanhoExcedido(): void
    {
        $this->expectException(RuntimeException::class);

        $this->upload->salvar($this->arquivoFake('grande.txt', str_repeat("linha de texto\n", 100000)), 'documento');
    }

    public function testSemArquivo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nenhum arquivo/');

        $this->upload->salvar(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => ''], 'documento');
    }

    public function testRegrasExtrasDoFormulario(): void
    {
        $this->expectException(RuntimeException::class);

        // regra extra: titulo obrigatorio (validators em acao)
        $this->upload = new Upload($this->db, [
            'pasta' => $this->dir . '/uploads',
            'regras' => ['titulo' => 'required|min:5'],
        ]);
        $this->upload->salvar($this->arquivoFake('a.txt', 'x'), 'documento', ['titulo' => 'ab']);
    }

    public function testApagarRemoveArquivoEImagem(): void
    {
        $registro = $this->upload->salvar($this->fotoFake(), 'galeria', [], ['webp' => 70]);
        $upload = $this->repo->buscar((int) $registro['id']);
        $caminho = $upload->caminho;
        $imagemId = (int) $upload->imagem_id;

        $this->assertTrue($this->repo->apagar((int) $registro['id']));
        $this->assertFileDoesNotExist($caminho);
        $this->assertNull($this->repo->buscar((int) $registro['id']));
        $this->assertNull((new CropImageRepository($this->db))->buscar($imagemId));
    }

    public function testListarEContarPorTipo(): void
    {
        $this->upload->salvar($this->arquivoFake('a.txt', "arquivo a\n"), 'documento');
        $this->upload->salvar($this->arquivoFake('b.txt', "arquivo b\n"), 'documento');
        $this->upload->salvar($this->fotoFake(), 'galeria');

        $this->assertSame(2, $this->repo->contar('documento'));
        $this->assertSame(1, $this->repo->contar('galeria'));
        $this->assertCount(2, $this->repo->listar('documento'));
    }
}
