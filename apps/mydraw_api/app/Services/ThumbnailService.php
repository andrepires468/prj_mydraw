<?php

namespace App\Services;

use GdImage;
use RuntimeException;
use Throwable;

/**
 * Geração de thumbnails via GD.
 *
 * Regras:
 *  - mantém a proporção da imagem original (nunca distorce);
 *  - nunca faz upscale: se a imagem já cabe na resolução máxima, o thumb
 *    mantém as dimensões originais;
 *  - aplica qualidade configurável a formatos com compressão com perda
 *    (JPEG/WebP); para PNG, mapeia a qualidade em nível de compressão
 *    (0-9) sem perda visual; GIF é gravado sem parâmetro de qualidade.
 */
class ThumbnailService
{
    /**
     * Gera o binário de um thumbnail ajustado a caber dentro da resolução
     * máxima informada, preservando a proporção da imagem original.
     *
     * @param  string  $sourcePath  Caminho absoluto do arquivo original.
     * @param  string  $mime        MIME type (image/jpeg, image/png, image/webp, image/gif).
     * @param  int     $maxWidth    Largura máxima em pixels.
     * @param  int     $maxHeight   Altura máxima em pixels.
     * @param  int     $quality     Qualidade 0-100 (aplicada a formatos lossy).
     * @return string               Binário da imagem gerada.
     */
    public function generate(
        string $sourcePath,
        string $mime,
        int $maxWidth,
        int $maxHeight,
        int $quality = 85
    ): string {
        $source = $this->loadImage($sourcePath, $mime);
        $dst    = null;

        try {
            $srcW = imagesx($source);
            $srcH = imagesy($source);

            [$dstW, $dstH] = $this->fitSize($srcW, $srcH, $maxWidth, $maxHeight);

            $dst = imagecreatetruecolor($dstW, $dstH);
            if ($dst === false) {
                throw new RuntimeException('Falha ao alocar canvas para thumbnail.');
            }

            // Preserva transparência para PNG/GIF/WebP.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
            }

            if (!imagecopyresampled($dst, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH)) {
                throw new RuntimeException('Falha ao redimensionar a imagem.');
            }

            return $this->encode($dst, $mime, $quality);
        } finally {
            if ($source instanceof GdImage) {
                imagedestroy($source);
            }
            if ($dst instanceof GdImage) {
                imagedestroy($dst);
            }
        }
    }

    private function loadImage(string $path, string $mime): GdImage
    {
        $img = match (strtolower($mime)) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => @imagecreatefromjpeg($path),
            'image/png'                              => @imagecreatefrompng($path),
            'image/webp'                             => @imagecreatefromwebp($path),
            'image/gif'                              => @imagecreatefromgif($path),
            default                                  => null,
        };

        if (!$img instanceof GdImage) {
            throw new RuntimeException("Formato de imagem não suportado para thumbnail: {$mime}");
        }

        return $img;
    }

    /**
     * Calcula o tamanho final mantendo proporção e sem ultrapassar os
     * limites. Se a imagem já é menor que os limites, preserva o original.
     *
     * @return array{0:int,1:int}
     */
    private function fitSize(int $srcW, int $srcH, int $maxW, int $maxH): array
    {
        if ($srcW <= 0 || $srcH <= 0) {
            throw new RuntimeException('Dimensões de imagem inválidas.');
        }

        if ($srcW <= $maxW && $srcH <= $maxH) {
            return [$srcW, $srcH];
        }

        $ratio = min($maxW / $srcW, $maxH / $srcH);

        return [
            max(1, (int) floor($srcW * $ratio)),
            max(1, (int) floor($srcH * $ratio)),
        ];
    }

    private function encode(GdImage $image, string $mime, int $quality): string
    {
        $quality = max(0, min(100, $quality));

        ob_start();

        try {
            switch (strtolower($mime)) {
                case 'image/jpeg':
                case 'image/jpg':
                case 'image/pjpeg':
                    imagejpeg($image, null, $quality);
                    break;

                case 'image/png':
                    // PNG é lossless; usamos a qualidade informada apenas para
                    // derivar o nível de compressão (0 = sem compressão, 9 = máxima).
                    $level = (int) round((100 - $quality) / 100 * 9);
                    $level = max(0, min(9, $level));
                    imagepng($image, null, $level);
                    break;

                case 'image/webp':
                    imagewebp($image, null, $quality);
                    break;

                case 'image/gif':
                    imagegif($image);
                    break;

                default:
                    throw new RuntimeException("Formato de imagem não suportado para encode: {$mime}");
            }

            $binary = ob_get_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }

        if ($binary === false || $binary === '') {
            throw new RuntimeException('Falha ao codificar thumbnail.');
        }

        return $binary;
    }
}
