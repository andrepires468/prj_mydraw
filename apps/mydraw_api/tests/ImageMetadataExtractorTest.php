<?php

namespace Tests;

use App\Models\Gallery;
use App\Services\ImageMetadataExtractor;

class ImageMetadataExtractorTest extends TestCase
{
    public function test_parse_a1111_parameters_extracts_fields_and_loras(): void
    {
        $positive = <<<'TXT'
masterpiece, best quality,
<lora:retro_scifi_artstyle:0.45>
<lora:Diana_the_Acrobat_IL:1>
TXT;

        $negative = 'low quality, watermark';

        $params = <<<'TXT'
Steps: 28, Sampler: Euler a, Schedule type: Automatic, CFG scale: 3.5, Seed: 4244651066, Size: 1200x1600, Model hash: e381203a3d, Model: hassakuXLIllustrious_v2_1318530, Clip skip: 2, Lora hashes: "retro_scifi_artstyle: 05c6d2cea061, Diana_the_Acrobat_IL: ccb04886f98d", Version: f2.0.1-test
TXT;

        $full = $positive."\n\nNegative prompt: ".$negative."\n".$params;

        $extractor = new ImageMetadataExtractor();
        $parsed    = $extractor->parseA1111Parameters($full);

        $this->assertNotNull($parsed);
        $this->assertSame(Gallery::METADATA_TYPE_STABLE_DIFFUSION, $parsed['metadata_type']);
        $this->assertStringContainsString('masterpiece', (string) $parsed['metadata_prompt']);
        $this->assertSame($negative, $parsed['metadata_negative_prompt']);
        $this->assertSame(28, $parsed['metadata_steps']);
        $this->assertSame('Euler a', $parsed['metadata_sampler']);
        $this->assertSame('Automatic', $parsed['metadata_schedule_type']);
        $this->assertSame(3.5, $parsed['metadata_cfg_scale']);
        $this->assertSame(4244651066, $parsed['metadata_seed']);
        $this->assertSame(1200, $parsed['metadata_width']);
        $this->assertSame(1600, $parsed['metadata_height']);
        $this->assertSame('hassakuXLIllustrious_v2_1318530', $parsed['metadata_model']);
        $this->assertSame('e381203a3d', $parsed['metadata_model_hash']);
        $this->assertSame(2, $parsed['metadata_clip_skip']);
        $this->assertSame('f2.0.1-test', $parsed['metadata_version']);

        $loras = $parsed['metadata_loras'];
        $this->assertIsArray($loras);
        $this->assertCount(2, $loras);

        $byName = [];
        foreach ($loras as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertSame(0.45, $byName['retro_scifi_artstyle']['weight']);
        $this->assertSame('05c6d2cea061', $byName['retro_scifi_artstyle']['hash']);
        $this->assertSame(1.0, $byName['Diana_the_Acrobat_IL']['weight']);
        $this->assertSame('ccb04886f98d', $byName['Diana_the_Acrobat_IL']['hash']);
    }

    public function test_extract_from_png_with_parameters_chunk(): void
    {
        // Minimal valid PNG (1×1) + tEXt "parameters" before IDAT (simplified builder).
        $ihdrData = pack('N', 1).pack('N', 1).chr(8).chr(2).chr(0).chr(0).chr(0);
        $ihdr     = $this->pngChunk('IHDR', $ihdrData);

        $paramText = "test prompt\n\nNegative prompt: bad\nSteps: 20, Sampler: DPM++ 2M, Seed: 1, Size: 512x512";
        $textData  = 'parameters'."\0".$paramText;
        $textChunk = $this->pngChunk('tEXt', $textData);

        // zlib-compressed scanline for 8-bit RGB 1×1: filter 0 + RGB
        $rawIdat = chr(0).chr(255).chr(0).chr(0);
        $idatData = zlib_encode($rawIdat, ZLIB_ENCODING_DEFLATE);
        $this->assertNotFalse($idatData);
        $idat = $this->pngChunk('IDAT', $idatData);

        $iend = $this->pngChunk('IEND', '');

        $png = "\x89PNG\r\n\x1a\n".$ihdr.$textChunk.$idat.$iend;

        $extractor = new ImageMetadataExtractor();
        $parsed    = $extractor->extractStableDiffusionFromBinary($png);

        $this->assertNotNull($parsed);
        $this->assertSame(20, $parsed['metadata_steps']);
        $this->assertSame(512, $parsed['metadata_width']);
        $this->assertSame(512, $parsed['metadata_height']);
    }

    private function pngChunk(string $type, string $data): string
    {
        $len  = pack('N', strlen($data));
        $crc  = pack('N', crc32($type.$data));

        return $len.$type.$data.$crc;
    }
}
